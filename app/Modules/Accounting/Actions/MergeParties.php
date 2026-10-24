<?php

namespace App\Modules\Accounting\Actions;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Foundation\Corrections\Owners;
use App\Modules\Foundation\Corrections\Tk;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Support\ReferenceScan;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * ⭐ Merge two customers, or two suppliers (B13, 24 Oct 2026; FINAL-PLAN 3.2
 * "Customer: Merge (dry run first)", 3.3 "Supplier: Merge", section 7 Phase 4
 * "Merges, Owner only, dry run first").
 *
 * The same firm was typed twice. Everything that points at the one being
 * merged away moves to the one kept: every document (orders, challans,
 * invoices, receipts, bills, payments, returns, notes), every ledger line's
 * party tag (so the statement, the ageing and the party ledger read as one),
 * the phone book link, attachments, and the voucher lines that picked its
 * named head. The merged one is not deleted: it is switched off with a
 * "merged into" pointer, so an old link still says where it went.
 *
 * ⚠⚠ DRY RUN FIRST. `run(dryRun: true)` does the whole merge inside a
 * transaction and rolls it back, so the counts and the balances it reports
 * are exactly what the real merge will do, unique-key clashes included. The
 * real merge needs the token of a dry run of the SAME pair by the SAME Owner
 * with the SAME counts; anything that changed since asks for a new dry run.
 *
 * ⚠ All or nothing, in one transaction. Refused, with one sentence:
 *   - by anybody but the Owner
 *   - a system row (the walk-in, a staff or owner customer), or one already
 *     merged, deleted, or the same record twice
 *   - when the one merged away has ledger lines in a closed month: those
 *     months' statements would change under a lock no button may bypass
 *   - when a table cannot hold both (a unique key clashes)
 *
 * The kept record's own control-account balance after the merge is the sum
 * of the two before it; the dry run shows both.
 */
class MergeParties
{
    public const TTL_SECONDS = 1800;

    /**
     * @return array{kind: string, gone: array, kept: array, moves: array<string, int>, balances: array, token: ?string, dry_run: bool}
     *
     * @throws ValidationException
     */
    public function run(Customer|Supplier $gone, Customer|Supplier $kept, User $owner, ActionReason $reason, bool $dryRun, ?string $token = null): array
    {
        $this->refuse($gone, $kept, $owner);

        $before = ['gone' => $this->balance($gone), 'kept' => $this->balance($kept)];
        $counts = null;
        $report = null;

        DB::beginTransaction();

        try {
            $gone = $gone::query()->whereKey($gone->getKey())->lockForUpdate()->firstOrFail();
            $kept = $kept::query()->whereKey($kept->getKey())->lockForUpdate()->firstOrFail();
            $this->refuse($gone, $kept, $owner);

            $counts = $this->move($gone, $kept);
            $after = ['gone' => $this->balance($gone), 'kept' => $this->balance($kept)];

            $report = [
                'kind' => $gone instanceof Customer ? 'customer' : 'supplier',
                'gone' => ['id' => $gone->getKey(), 'name' => $gone->name],
                'kept' => ['id' => $kept->getKey(), 'name' => $kept->name],
                'moves' => $counts,
                'balances' => [
                    'gone_before' => Tk::of($before['gone']),
                    'kept_before' => Tk::of($before['kept']),
                    'kept_after' => Tk::of($after['kept']),
                    'gone_after' => Tk::of($after['gone']),
                ],
                'dry_run' => $dryRun,
                'token' => null,
            ];

            if ($after['kept'] !== $before['gone'] + $before['kept'] || $after['gone'] !== 0) {
                throw ValidationException::withMessages(['merge' => 'The balances would not add up after the merge, so nothing was merged.']);
            }

            if ($dryRun) {
                DB::rollBack();
                $report['token'] = $this->token($gone, $kept, $owner, $counts);

                return $report;
            }

            if ($token === null || ! $this->accepts($token, $gone, $kept, $owner, $counts)) {
                throw ValidationException::withMessages(['merge' => 'Something changed since the dry run, or there was none. Run the dry run again, then merge.']);
            }

            $this->retire($gone, $kept, $owner);

            CorrectionLog::event('merged', $owner, $gone, ['into' => $kept->name, 'into_id' => $kept->getKey(), 'moves' => $counts, 'reason' => $reason->display()]);
            CorrectionLog::event('merged_in', $owner, $kept, ['from' => $gone->name, 'from_id' => $gone->getKey(), 'moves' => $counts, 'reason' => $reason->display()]);

            DB::commit();

            return $report;
        } catch (QueryException $e) {
            DB::rollBack();

            throw ValidationException::withMessages(['merge' => "{$gone->name} and {$kept->name} each have a record that cannot be joined into one (".$this->tableOf($e).'), so nothing was merged. Remove one of the two first.']);
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }
    }

    /** @throws ValidationException */
    private function refuse(Model $gone, Model $kept, User $owner): void
    {
        $say = fn (string $sentence) => throw ValidationException::withMessages(['merge' => $sentence]);

        if (! Owners::is($owner)) {
            $say('Only the Owner can merge two records.');
        }

        if ($gone::class !== $kept::class) {
            $say('Only a customer and a customer, or a supplier and a supplier, can be merged.');
        }

        if ((int) $gone->getKey() === (int) $kept->getKey()) {
            $say('Pick two different records.');
        }

        if ((int) $gone->business_id !== (int) $kept->business_id) {
            $say('The two records belong to different businesses.');
        }

        foreach ([$gone, $kept] as $party) {
            if (($party->is_system ?? false) || ($party instanceof Customer && ($party->employee_id !== null || $party->owner_user_id !== null))) {
                $say("{$party->name} is made by the system (the walk-in, a staff or an owner customer) and cannot be merged.");
            }

            if ($party->merged_into_id !== null) {
                $say("{$party->name} was already merged into another record.");
            }

            if (method_exists($party, 'trashed') && $party->trashed()) {
                $say("{$party->name} is in the Recycle bin.");
            }
        }

        $lock = AccountingPeriod::current();

        if ($lock !== null && ! $lock->is_unlocked) {
            $closedLines = DB::table('journal_lines')
                ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
                ->where('journal_lines.party_type', $gone->getMorphClass())
                ->where('journal_lines.party_id', $gone->getKey())
                ->whereDate('journals.journal_date', '<=', $lock->closed_through->toDateString())
                ->exists();

            if ($closedLines) {
                $say("{$gone->name} has entries dated in a closed month (up to ".Tk::date(Carbon::parse($lock->closed_through)).'). Those cannot move, so the merge is refused. Merge the other way round, or keep both.');
            }
        }
    }

    /**
     * Repoint every row; returns "table.column" => rows moved.
     *
     * @return array<string, int>
     */
    private function move(Customer|Supplier $gone, Customer|Supplier $kept): array
    {
        $column = $gone instanceof Customer ? 'customer_id' : 'supplier_id';
        $moves = [];

        foreach (ReferenceScan::pointers($gone, [$column]) as [$table, $idColumn, $typeColumn]) {
            $query = DB::table($table)->where($idColumn, $gone->getKey());

            if ($typeColumn !== null) {
                $query->where($typeColumn, $gone->getMorphClass());
            }

            $rows = $query->update([$idColumn => $kept->getKey()]);

            if ($rows > 0) {
                $moves["{$table}.{$idColumn}"] = $rows;
            }
        }

        // A voucher line that picked the merged one's named head (EntityLedgers)
        // now picks the kept one's.
        if ($gone->account_id !== null && $kept->account_id !== null) {
            foreach (ReferenceScan::pointers($gone, ['account_id'], ['accounts', 'customers', 'suppliers', 'employees', 'money_accounts', 'account_mappings']) as [$table, $idColumn, $typeColumn]) {
                if ($typeColumn !== null || $idColumn !== 'account_id') {
                    continue;
                }

                $rows = DB::table($table)->where('account_id', $gone->account_id)->update(['account_id' => $kept->account_id]);

                if ($rows > 0) {
                    $moves["{$table}.account_id"] = ($moves["{$table}.account_id"] ?? 0) + $rows;
                }
            }
        }

        ksort($moves);

        return $moves;
    }

    private function retire(Customer|Supplier $gone, Customer|Supplier $kept, User $owner): void
    {
        $gone->forceFill([
            'is_active' => false,
            'merged_into_id' => $kept->getKey(),
            'merged_at' => now(),
            'merged_by' => $owner->id,
        ])->save();

        if ($gone->account_id !== null) {
            Account::query()->whereKey($gone->account_id)->update([
                'is_active' => false,
                'merged_into_id' => $kept->account_id,
                'merged_at' => now(),
                'merged_by' => $owner->id,
            ]);
        }
    }

    /** The party's net balance across every account its lines are tagged on, in paisa. */
    private function balance(Model $party): int
    {
        return (int) DB::table('journal_lines')
            ->where('party_type', $party->getMorphClass())
            ->where('party_id', $party->getKey())
            ->sum('amount_minor');
    }

    /** @param array<string, int> $counts */
    private function token(Model $gone, Model $kept, User $owner, array $counts): string
    {
        $claims = base64_encode((string) json_encode(['g' => $gone->getKey(), 'k' => $kept->getKey(), 't' => $gone::class, 'u' => $owner->id, 'c' => hash('sha256', (string) json_encode($counts)), 'at' => now()->getTimestamp()]));

        return $claims.'.'.hash_hmac('sha256', $claims, (string) config('app.key'));
    }

    /** @param array<string, int> $counts */
    private function accepts(string $token, Model $gone, Model $kept, User $owner, array $counts): bool
    {
        [$claims, $mac] = array_pad(explode('.', $token, 2), 2, '');

        if (! hash_equals(hash_hmac('sha256', $claims, (string) config('app.key')), $mac)) {
            return false;
        }

        $data = json_decode((string) base64_decode($claims, true), true);

        return is_array($data)
            && ($data['g'] ?? null) === $gone->getKey()
            && ($data['k'] ?? null) === $kept->getKey()
            && ($data['t'] ?? null) === $gone::class
            && ($data['u'] ?? null) === $owner->id
            && ($data['c'] ?? null) === hash('sha256', (string) json_encode($counts))
            && now()->getTimestamp() - (int) ($data['at'] ?? 0) <= self::TTL_SECONDS;
    }

    private function tableOf(QueryException $e): string
    {
        return preg_match('/(?:table|into|UNIQUE constraint failed:)\s*[`"]?([a-z_]+)/i', $e->getMessage(), $m) === 1 ? str_replace('_', ' ', $m[1]) : 'a unique record';
    }
}
