<?php

namespace App\Modules\Accounting\Console\Commands;

use App\Modules\Accounting\Models\IncomeVoucher;
use App\Modules\Foundation\Models\Business;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * D1, the data check before edit, delete and correct ships (FINAL-PLAN 0.1,
 * PHASE-0-CONTRACT v2, C18). READ-ONLY: it lists, it never fixes, and it
 * back-fills no history.
 *
 *   1. income vouchers with no live journal, or with more than one
 *   2. income vouchers whose journal_id the migration could not fill
 *   3. journals reversed whose income voucher is not marked reversed
 *   4. income vouchers marked reversed with no reversal journal
 *   5. reversed journals whose lines are still matched on a bank statement
 *
 * ⚠ Queries name business_id themselves (query builder, no global scope), so it
 * runs cold from the console and still examines every business.
 */
class CorrectionsPreflight extends Command
{
    protected $signature = 'erp:corrections-preflight {--strict : exit 1 when anything needs a person}';

    protected $description = 'Read-only data check for corrections: vouchers and journals that need a person before they can be reversed';

    public function handle(): int
    {
        $morph = (new IncomeVoucher)->getMorphClass();
        $found = 0;

        foreach (Business::withoutGlobalScopes()->orderBy('id')->get() as $business) {
            $this->line("<info>{$business->name}</info> (business {$business->id})");
            $vouchers = DB::table('income_vouchers')->where('business_id', $business->id)->count();
            $this->line("  income vouchers: {$vouchers}");

            $liveCounts = DB::table('income_vouchers as v')
                ->where('v.business_id', $business->id)
                ->whereNull('v.reversed_at')
                ->selectRaw('v.id, v.voucher_number, (SELECT COUNT(*) FROM journals j WHERE j.source_type = ? AND j.source_id = v.id AND j.reverses_journal_id IS NULL AND NOT EXISTS (SELECT 1 FROM journals r WHERE r.reverses_journal_id = j.id)) as live', [$morph])
                ->get();

            $found += $this->report('1. Vouchers with no live journal or more than one (cannot be reversed until a person looks):',
                $liveCounts->filter(fn ($row) => (int) $row->live !== 1)->map(fn ($row) => "{$row->voucher_number}: {$row->live} live journal(s)"));

            $found += $this->report('2. Vouchers whose journal_id is empty:',
                DB::table('income_vouchers')->where('business_id', $business->id)->whereNull('journal_id')->pluck('voucher_number'));

            $found += $this->report('3. Journals reversed whose voucher is not marked reversed:',
                DB::table('journals as j')
                    ->join('income_vouchers as v', 'v.id', '=', 'j.source_id')
                    ->where('j.business_id', $business->id)
                    ->where('j.source_type', $morph)
                    ->whereNull('j.reverses_journal_id')
                    ->whereExists(fn ($q) => $q->selectRaw('1')->from('journals as r')->whereColumn('r.reverses_journal_id', 'j.id'))
                    ->whereNull('v.reversed_at')
                    ->get(['j.journal_number', 'v.voucher_number'])
                    ->map(fn ($row) => "{$row->journal_number} ({$row->voucher_number})"));

            $found += $this->report('4. Vouchers marked reversed with no reversal journal:',
                DB::table('income_vouchers')->where('business_id', $business->id)->whereNotNull('reversed_at')->whereNull('reversal_journal_id')->pluck('voucher_number'));

            $found += $this->report('5. Reversed journals still matched on a bank statement:',
                DB::table('journals as j')
                    ->where('j.business_id', $business->id)
                    ->whereExists(fn ($q) => $q->selectRaw('1')->from('journals as r')->whereColumn('r.reverses_journal_id', 'j.id'))
                    ->whereExists(fn ($q) => $q->selectRaw('1')->from('journal_lines as l')
                        ->join('bank_statement_lines as b', 'b.matched_journal_line_id', '=', 'l.id')
                        ->whereColumn('l.journal_id', 'j.id'))
                    ->pluck('j.journal_number'));
        }

        $this->newLine();

        if ($found === 0) {
            $this->info('Nothing needs a person. Every income voucher has exactly one live journal.');

            return self::SUCCESS;
        }

        $this->warn("{$found} item(s) need a person. Nothing was changed.");

        return $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }

    private function report(string $title, iterable $items): int
    {
        $items = collect($items)->values();
        $this->line("  {$title} ".($items->isEmpty() ? 'none' : $items->count()));

        foreach ($items->take(200) as $item) {
            $this->line("    - {$item}");
        }

        return $items->count();
    }
}
