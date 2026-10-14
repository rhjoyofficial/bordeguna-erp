<?php

use App\Modules\Accounting\Support\CounterpartyIdentity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⚠⚠ THE LOAN HEADS (loans plan v2, phase 1; ChatGPT review and confirmation,
 * 14 Oct 2026). Money lent to and borrowed from people and firms OUTSIDE the
 * group, each balance kept per counterparty (Support\PartyLedgers):
 *
 *   1175  Loans & Advances to Others (heading under 1100 Current Assets)
 *   11751   Loans to Other Parties
 *   11752   Loans to Related Parties
 *   11753   Interest Receivable
 *   11754   Current Portion of Long-term Loans Given
 *   1270  Long-term Loans Given (under 1200 Non-Current Assets)
 *   2116  Bank Overdraft / Cash Credit           } under 2100
 *   2126  Short-term Loans Payable               } Current
 *   2127  Interest Payable                       } Liabilities
 *   2128  Current Portion of Long-term Loans     }
 *   2210  Bank Loans          (exists: gains the slug bank_loans)
 *   2220  Loans from Owners   (exists: keeps owner_loans; gains a note)
 *   2230  Loans from Other Parties (under 2200 Non-Current Liabilities)
 *
 * ⚠⚠ 1170 IS NOT TOUCHED. It carries the employee_advances slug, the payroll
 * mappings and one display head per employee; converting it into a group was
 * the change the review refused. Staff loans stay with HRM on 1170.
 *
 * ⚠ Brand to brand is never a loan: 1195/2190 and Inter-business transfers.
 *
 * ⚠ Every head carries a system slug and the software finds it by the slug,
 * never by the code (Support\PartyLedgers).
 *
 * ⚠ Written with the DB facade, like the migrations beside it: the Account
 * model would log into the CURRENT business and a migration has none, so each
 * change is written to the activity log here, plus one system entry.
 *
 * ⚠ Does nothing on a chart the seeder has not planted (no 1000 and no 2000
 * root): ChartOfAccountsSeeder plants the same heads on a fresh install.
 * Idempotent: a second run changes nothing. Every code and slug is checked
 * BEFORE anything is written, and a code held by something that is not the
 * head this needs stops the migration with a sentence rather than guessing.
 *
 * ⚠ MySQL: codes are bound as STRINGS (an integer makes MySQL cast the varchar
 * column to DOUBLE).
 */
return new class extends Migration
{
    private const REASON = 'The loan heads join the chart; each line on them names its counterparty (14 Oct 2026).';

    private const ACCOUNT = 'App\\Modules\\Accounting\\Models\\Account';

    private const OWNER_LOAN_NOTE = "Only for a documented owner loan agreed with the accountant; money the owner puts in is Owner's Capital 3100.";

    /**
     * The headings the new heads hang under: code => [name, type].
     */
    private const PARENTS = [
        '1100' => ['Current Assets', 'asset'],
        '1200' => ['Non-Current Assets', 'asset'],
        '2100' => ['Current Liabilities', 'liability'],
        '2200' => ['Non-Current Liabilities', 'liability'],
    ];

    /**
     * New rows, parents first: code => [name, type, parent code, postable, slug, note].
     */
    private const HEADS = [
        '1175' => ['Loans & Advances to Others', 'asset', '1100', false, 'loans_and_advances_to_others',
            'Money lent to people and firms outside the group, and the interest they owe. Staff loans stay on 1170; our own businesses use 1195.'],
        '11751' => ['Loans to Other Parties', 'asset', '1175', true, 'loans_to_other_parties',
            'Principal lent to an unrelated party. Every line names the counterparty.'],
        '11752' => ['Loans to Related Parties', 'asset', '1175', true, 'loans_to_related_parties',
            'Principal lent to a related party. Only for a counterparty marked related.'],
        '11753' => ['Interest Receivable', 'asset', '1175', true, 'interest_receivable',
            'Interest earned and not yet received, per counterparty. Never added to principal.'],
        '11754' => ['Current Portion of Long-term Loans Given', 'asset', '1175', true, 'current_portion_of_loans_given',
            'The part of a long-term loan given (1270) that falls due within the next 12 months.'],
        '1270' => ['Long-term Loans Given', 'asset', '1200', true, 'long_term_loans_given',
            'Principal lent that falls due after 12 months; the part due within 12 months moves to 11754.'],
        '2116' => ['Bank Overdraft / Cash Credit', 'liability', '2100', true, 'bank_overdraft',
            'An overdraft or cash-credit facility drawn, per bank.'],
        '2126' => ['Short-term Loans Payable', 'liability', '2100', true, 'short_term_loans_payable',
            'Borrowing repayable within 12 months, per lender.'],
        '2127' => ['Interest Payable', 'liability', '2100', true, 'interest_payable',
            'Interest owed and not yet paid, per lender. Never added to principal.'],
        '2128' => ['Current Portion of Long-term Loans', 'liability', '2100', true, 'current_portion_of_long_term_loans',
            'The part of a long-term loan (2210, 2220, 2230) that falls due within the next 12 months.'],
        '2230' => ['Loans from Other Parties', 'liability', '2200', true, 'loans_from_other_parties',
            'Borrowing from a person or firm that is not a bank, repayable after 12 months.'],
    ];

    /**
     * Rows production already has: code => [name, slug, note when it has none].
     * Created only on a chart that somehow lacks them.
     */
    private const EXISTING = [
        '2210' => ['Bank Loans', 'bank_loans', null],
        '2220' => ['Loans from Owners', 'owner_loans', self::OWNER_LOAN_NOTE],
    ];

    public function up(): void
    {
        if (! DB::table('accounts')->whereIn('code', ['1000', '2000'])->exists()) {
            return;
        }

        $parents = $this->parents();
        $this->assertCodesAndSlugsAreFree();

        DB::transaction(function () use ($parents) {
            $changes = [];
            $ids = $parents;

            foreach (self::HEADS as $code => [$name, $type, $parentCode, $postable, $slug, $note]) {
                $ids[$code] = $this->head((string) $code, $name, $type, $ids[$parentCode], $postable, $slug, $note, $changes);
            }

            foreach (self::EXISTING as $code => [$name, $slug, $note]) {
                $this->head((string) $code, $name, 'liability', $ids['2200'], true, $slug, $note, $changes);
            }

            if ($changes !== []) {
                $this->insertLog([
                    'log_name' => 'system',
                    'description' => 'Chart of accounts: '.self::REASON,
                    'properties' => json_encode(['reason' => self::REASON, 'changes' => $changes]),
                ], null);
            }
        });
    }

    /**
     * Takes back what up() did while it is safe: no posting on any new head and
     * nothing else filed under 1175. Otherwise it stops with a sentence.
     * ⚠ For rehearsal; a live chart has no reason to go back.
     */
    public function down(): void
    {
        $ours = DB::table('accounts')->whereIn('code', array_map('strval', array_keys(self::HEADS)))->get(['id', 'code', 'system_slug']);
        $ours = $ours->filter(fn ($row) => $row->system_slug === self::HEADS[(string) $row->code][4]);
        $ids = $ours->pluck('id')->map(fn ($id) => (int) $id)->all();

        $posted = DB::table('journal_lines')->whereIn('account_id', $ids)
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->distinct()->orderBy('accounts.code')->pluck('accounts.code');

        if ($posted->isNotEmpty()) {
            throw new RuntimeException('Cannot roll back the loan heads: these heads already have postings: '.$posted->implode(', ').'. Rolling back would strand them.');
        }

        $strangers = DB::table('accounts')->whereIn('parent_id', $ids)->whereNotIn('id', $ids)->pluck('code');

        if ($strangers->isNotEmpty()) {
            throw new RuntimeException('Cannot roll back the loan heads: '.$strangers->implode(', ').' were added under 1175 since. Move them first.');
        }

        try {
            DB::transaction(function () use ($ours) {
                // Heads before the heading they sit under.
                foreach ($ours->sortByDesc(fn ($row) => strlen((string) $row->code)) as $row) {
                    if ($this->createdHere((int) $row->id)) {
                        DB::table('accounts')->where('id', $row->id)->delete();
                    } else {
                        $this->undoUpdate((int) $row->id);
                    }
                }

                foreach (array_keys(self::EXISTING) as $code) {
                    $id = DB::table('accounts')->where('code', (string) $code)->value('id');

                    if ($id !== null) {
                        $this->undoUpdate((int) $id);
                    }
                }
            });
        } catch (QueryException $e) {
            // Something still names a new head (a draft voucher line, a budget).
            throw new RuntimeException('Cannot roll back the loan heads: one of them is still in use ('.$e->getMessage().').', 0, $e);
        }
    }

    /**
     * The four headings, each checked to be what it says it is.
     *
     * @return array<string, int> code => id
     */
    private function parents(): array
    {
        $ids = [];

        foreach (self::PARENTS as $code => [$name, $type]) {
            $row = DB::table('accounts')->where('code', (string) $code)->first();

            if ($row === null || (bool) $row->is_postable || $row->type !== $type) {
                throw new RuntimeException("Cannot add the loan heads: the chart has no {$code} {$name} heading for them to sit under".($row === null ? '.' : " (code {$code} is \"{$row->name}\", which is not a {$type} heading).").' Put it back, then run the migration again.');
            }

            $ids[(string) $code] = (int) $row->id;
        }

        return $ids;
    }

    /**
     * ⚠ Everything checked before anything is written, so a stop leaves the
     * chart exactly as it found it.
     */
    private function assertCodesAndSlugsAreFree(): void
    {
        foreach (self::HEADS as $code => [$name, $type, , $postable, $slug]) {
            $existing = DB::table('accounts')->where('code', (string) $code)->first();

            if ($existing !== null && ! $this->isTheHead($existing, $name, $type, $postable, $slug)) {
                throw new RuntimeException("Cannot add the loan heads: code {$code} is already used by \"{$existing->name}\" (account id {$existing->id}), which is not {$code} {$name}. Renumber that account first, then run the migration again.");
            }

            $this->assertSlugIsFree((string) $code, $name, $slug);
        }

        foreach (self::EXISTING as $code => [$name, $slug]) {
            $existing = DB::table('accounts')->where('code', (string) $code)->first();

            if ($existing !== null) {
                if ($existing->type !== 'liability' || ! (bool) $existing->is_postable) {
                    throw new RuntimeException("Cannot add the loan heads: code {$code} is \"{$existing->name}\" (account id {$existing->id}), which is not a postable liability. Put {$code} {$name} back, then run the migration again.");
                }

                if ($existing->system_slug !== null && $existing->system_slug !== $slug) {
                    throw new RuntimeException("Cannot add the loan heads: {$code} {$existing->name} already carries the slug {$existing->system_slug}, so it cannot become `{$slug}`. Ask the developer which head the software should use.");
                }
            }

            $this->assertSlugIsFree((string) $code, $name, $slug);
        }
    }

    private function assertSlugIsFree(string $code, string $name, string $slug): void
    {
        $holder = DB::table('accounts')->where('system_slug', $slug)->first();

        if ($holder !== null && (string) $holder->code !== $code) {
            throw new RuntimeException("Cannot add the loan heads: the slug `{$slug}` that {$code} {$name} needs is already on {$holder->code} \"{$holder->name}\" (account id {$holder->id}). Ask the developer which head the software should use.");
        }
    }

    /**
     * A row already on the code is accepted only when it is this head: it holds
     * the slug, or it has none and the same name, type and postability (one
     * somebody made by hand ahead of the migration).
     */
    private function isTheHead(object $row, string $name, string $type, bool $postable, string $slug): bool
    {
        if ($row->system_slug === $slug) {
            return true;
        }

        return $row->system_slug === null
            && $row->type === $type
            && (bool) $row->is_postable === $postable
            && CounterpartyIdentity::nameKey($row->name) === CounterpartyIdentity::nameKey($name);
    }

    /**
     * Creates the head, or gives an existing one its slug (and note, when it has
     * none). Returns its id.
     *
     * @param  list<string>  $changes
     */
    private function head(string $code, string $name, string $type, int $parentId, bool $postable, string $slug, ?string $note, array &$changes): int
    {
        $existing = DB::table('accounts')->where('code', $code)->first();

        if ($existing === null) {
            $id = DB::table('accounts')->insertGetId([
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'parent_id' => $parentId,
                'is_postable' => $postable,
                'is_active' => true,
                'system_slug' => $slug,
                'note' => $note,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->log($id, 'created', ['code' => $code, 'name' => $name, 'parent_id' => $parentId, 'is_postable' => $postable, 'system_slug' => $slug], []);
            $changes[] = "{$code} {$name} created".($postable ? '' : ' as a heading');

            return $id;
        }

        $update = [];
        $old = [];

        if ($existing->system_slug === null) {
            $update['system_slug'] = $slug;
            $old['system_slug'] = null;
        }

        if ($note !== null && $existing->note === null) {
            $update['note'] = $note;
            $old['note'] = null;
        }

        if ($update !== []) {
            DB::table('accounts')->where('id', $existing->id)->update($update + ['updated_at' => now()]);
            $this->log((int) $existing->id, 'updated', $update, $old);
            $changes[] = "{$code} {$existing->name} now carries ".implode(' and ', array_keys($update));
        }

        return (int) $existing->id;
    }

    private function createdHere(int $accountId): bool
    {
        return DB::table('activity_log')->where('subject_type', self::ACCOUNT)->where('subject_id', $accountId)
            ->where('description', 'created')->where('properties', 'like', '%loan heads join the chart%')->exists();
    }

    /** Puts back the slug and note up() gave an existing row, read from the log up() wrote. */
    private function undoUpdate(int $accountId): void
    {
        if (! Schema::hasTable('activity_log')) {
            return;
        }

        $logged = DB::table('activity_log')->where('subject_type', self::ACCOUNT)->where('subject_id', $accountId)
            ->where('description', 'updated')->where('properties', 'like', '%loan heads join the chart%')
            ->orderByDesc('id')->pluck('properties');

        foreach ($logged as $json) {
            $old = json_decode($json, true)['old'] ?? [];

            if ($old !== []) {
                DB::table('accounts')->where('id', $accountId)->update($old + ['updated_at' => now()]);
            }
        }
    }

    private function log(int $accountId, string $event, array $attributes, array $old): void
    {
        $this->insertLog([
            'log_name' => 'default',
            'description' => $event,
            'subject_type' => self::ACCOUNT,
            'subject_id' => $accountId,
            'properties' => json_encode(array_filter(['attributes' => $attributes, 'old' => $old, 'reason' => self::REASON], fn ($value) => $value !== [])),
        ], $event);
    }

    /** @param  array<string, mixed>  $row */
    private function insertLog(array $row, ?string $event): void
    {
        if (! Schema::hasTable('activity_log')) {
            return;
        }

        $row['created_at'] = now();
        $row['updated_at'] = now();

        if ($event !== null && Schema::hasColumn('activity_log', 'event')) {
            $row['event'] = $event;
        }

        DB::table('activity_log')->insert($row);
    }
};
