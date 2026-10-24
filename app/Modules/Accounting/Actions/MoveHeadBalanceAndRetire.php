<?php

namespace App\Modules\Accounting\Actions;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Foundation\Corrections\Owners;
use App\Modules\Foundation\Corrections\Tk;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * ⭐ Ledger head "Move balance and retire" (B13, 24 Oct 2026; FINAL-PLAN 3.1
 * "Ledger head ... Duplicates: Move balance and retire (one journal, history
 * untouched)", Owner only, dry run first).
 *
 * Two heads mean the same thing ("Electricity" and "Electric bill"). ONE
 * journal on the chosen day moves the duplicate's balance onto the kept head;
 * the duplicate is then switched off with a "merged into" pointer. Every line
 * already posted stays exactly where it was, so every past report still reads
 * as it did: history is untouched, only the balance from that day on moves.
 *
 * ⚠⚠ DRY RUN FIRST, as the party merge: the dry run posts the journal inside a
 * transaction and rolls it back, so a closed day, a refused head or a clash is
 * found before the Owner confirms; the confirm needs the dry run's token.
 *
 * Refused, with one sentence, when the duplicate:
 *   - is used by the system (a slug), by a mapping, by a bank or cash account,
 *     or is a party's named head; or has heads under it
 *   - holds customer, supplier or employee lines (merge the parties instead)
 *   - is of a different kind than the kept head (asset into expense)
 *   - has lines after the chosen day, or a balance in another business
 *   - the chosen day is in a closed month
 */
class MoveHeadBalanceAndRetire
{
    public const TTL_SECONDS = 1800;

    public function __construct(private readonly PostJournal $postJournal) {}

    /**
     * @return array{from: array, to: array, on: string, balances: array, journal: ?string, dry_run: bool, token: ?string}
     *
     * @throws ValidationException
     */
    public function run(Account $from, Account $to, Carbon $on, User $owner, ActionReason $reason, bool $dryRun, ?string $token = null): array
    {
        $on = $on->copy()->startOfDay();
        $businessId = (int) app(BusinessContext::class)->id();
        $this->refuse($from, $to, $on, $owner, $businessId);

        $moving = $this->balance($from, $businessId);
        $toBefore = $this->balance($to, $businessId);
        $report = [
            'from' => ['id' => $from->id, 'label' => $from->label()],
            'to' => ['id' => $to->id, 'label' => $to->label()],
            'on' => $on->toDateString(),
            'balances' => [
                'from_before' => Tk::of($moving),
                'to_before' => Tk::of($toBefore),
                'from_after' => Tk::of(0),
                'to_after' => Tk::of($toBefore + $moving),
            ],
            'journal' => null,
            'dry_run' => $dryRun,
            'token' => null,
        ];

        DB::beginTransaction();

        try {
            $journal = $moving === 0 ? null : $this->postJournal->handle(new JournalDraft(
                date: $on,
                lines: [
                    $moving > 0
                        ? JournalLineDraft::credit($from->id, Money::ofMinor($moving), "Balance moved to {$to->label()}")
                        : JournalLineDraft::debit($from->id, Money::ofMinor(-$moving), "Balance moved to {$to->label()}"),
                    $moving > 0
                        ? JournalLineDraft::debit($to->id, Money::ofMinor($moving), "Balance moved from {$from->label()}")
                        : JournalLineDraft::credit($to->id, Money::ofMinor(-$moving), "Balance moved from {$from->label()}"),
                ],
                memo: "Move balance of {$from->label()} to {$to->label()} and retire it: {$reason->display()}",
            ), $owner);

            if ($this->balance($from, $businessId) !== 0 || $this->balance($to, $businessId) !== $toBefore + $moving) {
                throw ValidationException::withMessages(['merge' => 'The balances would not add up after the move, so nothing was posted.']);
            }

            if ($dryRun) {
                DB::rollBack();
                $report['token'] = $this->token($from, $to, $on, $owner, $moving);

                return $report;
            }

            if ($token === null || ! $this->accepts($token, $from, $to, $on, $owner, $moving)) {
                throw ValidationException::withMessages(['merge' => 'Something changed since the dry run, or there was none. Run the dry run again, then move.']);
            }

            $from->forceFill(['is_active' => false, 'merged_into_id' => $to->id, 'merged_at' => now(), 'merged_by' => $owner->id])->save();

            CorrectionLog::event('merged', $owner, $from, ['into' => $to->label(), 'journal' => $journal?->journal_number, 'moved' => Tk::of($moving), 'on' => $on->toDateString(), 'reason' => $reason->display()]);

            DB::commit();
            $report['journal'] = $journal?->journal_number;

            return $report;
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }
    }

    /** @throws ValidationException */
    private function refuse(Account $from, Account $to, Carbon $on, User $owner, int $businessId): void
    {
        $say = fn (string $sentence) => throw ValidationException::withMessages(['merge' => $sentence]);
        $name = $from->label();

        if (! Owners::is($owner)) {
            $say('Only the Owner can move a balance and retire a head.');
        }

        if ($from->id === $to->id) {
            $say('Pick two different heads.');
        }

        if ($from->merged_into_id !== null || ! $from->is_active) {
            $say("{$name} is already switched off.");
        }

        if (! $to->is_active || ! $to->is_postable) {
            $say("{$to->label()} cannot take postings. Pick an active head that can.");
        }

        if ($from->type !== $to->type) {
            $say("{$name} and {$to->label()} are different kinds of head. A balance only moves between two heads of the same kind.");
        }

        if ($from->system_slug !== null) {
            $say("{$name} is used by the system itself and cannot be retired.");
        }

        if (! $from->is_postable) {
            $say("{$name} is a heading, not a ledger: nothing posts to it.");
        }

        if ($from->children()->exists()) {
            $say("{$name} has heads under it. Move or retire those first.");
        }

        if (DB::table('account_mappings')->where('account_id', $from->id)->exists()) {
            $say("{$name} is mapped for automatic postings (Settings > Account mappings). Change the mapping first.");
        }

        if (DB::table('money_accounts')->where('account_id', $from->id)->exists()) {
            $say("{$name} belongs to a bank, cash or wallet account and cannot be retired here.");
        }

        if (DB::table('journal_lines')->where('account_id', $from->id)->whereNotNull('party_type')->exists()) {
            $say("{$name} holds customer, supplier or employee balances. Those move by merging the parties, not the head.");
        }

        $later = DB::table('journal_lines')->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.account_id', $from->id)
            ->whereDate('journals.journal_date', '>', $on->toDateString())
            ->exists();

        if ($later) {
            $say("{$name} has entries after ".Tk::date($on).'. Pick a later day, or today.');
        }

        $elsewhere = DB::table('journal_lines')->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.account_id', $from->id)
            ->where('journals.business_id', '!=', $businessId)
            ->groupBy('journals.business_id')
            ->havingRaw('SUM(journal_lines.amount_minor) <> 0')
            ->select('journals.business_id')
            ->exists();

        if ($elsewhere) {
            $say("{$name} also has a balance in another business. Move it there first, then retire it.");
        }

        if (AccountingPeriod::isClosedOn($on)) {
            $say(Tk::date($on).' is in a closed month. Pick a day in an open month.');
        }
    }

    private function balance(Account $account, int $businessId): int
    {
        return (int) DB::table('journal_lines')->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journals.business_id', $businessId)
            ->where('journal_lines.account_id', $account->id)
            ->sum('journal_lines.amount_minor');
    }

    private function token(Account $from, Account $to, Carbon $on, User $owner, int $moving): string
    {
        $claims = base64_encode((string) json_encode(['f' => $from->id, 't' => $to->id, 'd' => $on->toDateString(), 'u' => $owner->id, 'm' => $moving, 'at' => now()->getTimestamp()]));

        return $claims.'.'.hash_hmac('sha256', $claims, (string) config('app.key'));
    }

    private function accepts(string $token, Account $from, Account $to, Carbon $on, User $owner, int $moving): bool
    {
        [$claims, $mac] = array_pad(explode('.', $token, 2), 2, '');

        if (! hash_equals(hash_hmac('sha256', $claims, (string) config('app.key')), $mac)) {
            return false;
        }

        $data = json_decode((string) base64_decode($claims, true), true);

        return is_array($data)
            && ($data['f'] ?? null) === $from->id && ($data['t'] ?? null) === $to->id
            && ($data['d'] ?? null) === $on->toDateString() && ($data['u'] ?? null) === $owner->id
            && ($data['m'] ?? null) === $moving
            && now()->getTimestamp() - (int) ($data['at'] ?? 0) <= self::TTL_SECONDS;
    }
}
