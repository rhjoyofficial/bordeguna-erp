<?php

namespace App\Modules\Sales\Console\Commands;

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Sales\Actions\ApplyPriceListImport;
use App\Modules\Sales\Actions\PlanPriceListImport;
use App\Modules\Sales\Support\PriceImportPlan;
use App\Modules\Sales\Support\PriceListFile;
use App\Support\DayFirstDate;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Bulk selling prices, because 56 products across four tiers is 224 rows and
 * the only way to enter them today is one form at a time.
 *
 * ⚠⚠ REPORTS BY DEFAULT, WRITES ONLY WITH --apply. A tool whose safe mode needs
 * a flag gets run without the flag exactly once.
 *
 * ⚠⚠ RUNS COLD. A console command carries no BusinessContext and every model
 * here is business-scoped, so without entering the tenant `BusinessScope`
 * compiles `1 = 0`: the import would read an empty catalogue, call every SKU
 * unknown, and fail with a page of errors that say nothing true. Fifth time
 * this project has met "correct inside a request, wrong outside one".
 */
class ImportPriceList extends Command
{
    protected $signature = 'prices:import
                            {file : Path to the price CSV}
                            {--business= : Business slug or id the prices belong to}
                            {--effective-from= : The date these prices take effect (YYYY-MM-DD)}
                            {--apply : Write the changes. Without this, nothing is written}';

    protected $description = 'Review, and optionally import, a selling-price CSV';

    public function handle(PlanPriceListImport $planner, ApplyPriceListImport $applier): int
    {
        $business = $this->resolveBusiness();

        if ($business === null) {
            return self::FAILURE;
        }

        $effectiveFrom = $this->resolveEffectiveFrom();

        if ($effectiveFrom === null) {
            return self::FAILURE;
        }

        // ⚠⚠ Printed, not assumed. The production container has a cached config,
        // so `docker exec -e DB_DATABASE=...` is silently ignored and the
        // command runs against the LIVE database while the operator believes it
        // is on a scratch copy. Reading this line is the check.
        $this->line('Database: <options=bold>'.DB::connection()->getDatabaseName().'</>');
        $this->line("Business: <options=bold>{$business->name}</> (id {$business->id})");
        $this->line('Effective from: <options=bold>'.$effectiveFrom->toDateString().'</>');
        $this->newLine();

        try {
            ['rows' => $rows, 'ignored' => $ignored] = PriceListFile::read($this->argument('file'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        TenantScope::enter($business->id);

        $plan = $planner->handle($rows, $ignored, $effectiveFrom);

        $this->report($plan, count($rows));

        if ($plan->hasErrors()) {
            $this->newLine();
            $this->error('The file is not fit to import. Nothing was written.');

            return self::FAILURE;
        }

        if (! $this->option('apply')) {
            $this->newLine();
            $this->info('Review only. Re-run with --apply to write these prices.');

            return self::SUCCESS;
        }

        if (! $plan->wouldChangeAnything()) {
            $this->newLine();
            $this->info('Nothing to write: every price in the file is already in force.');

            return self::SUCCESS;
        }

        $by = User::query()->orderBy('id')->firstOrFail();

        $result = $applier->handle($plan, $effectiveFrom, $by);

        $this->newLine();
        $this->info("Wrote {$result['created']} price rule(s), superseding {$result['superseded']}.");

        return self::SUCCESS;
    }

    private function report(PriceImportPlan $plan, int $rowCount): void
    {
        $this->line("Rows read: <options=bold>{$rowCount}</>");

        if ($plan->ignoredColumns !== []) {
            // ⚠ Shown, never silent. A misspelt column is a whole pricing tier
            // that quietly does not arrive.
            $this->line('Columns ignored: <comment>'.implode(', ', $plan->ignoredColumns).'</comment>');
        }

        $this->newLine();
        $this->line('Would create:    <options=bold>'.count($plan->creates).'</> price rule(s)');
        $this->line('Would supersede: <options=bold>'.count($plan->supersedes).'</> rule(s) already in force');
        $this->line('Unchanged:       <options=bold>'.count($plan->unchanged).'</>');

        foreach ($plan->supersedes as $s) {
            $this->line("  ~ {$s['sku']} {$s['group']}: {$s['was']} -> {$s['becomes']} (old rule from {$s['from']} closes {$s['closesOn']})");
        }

        if ($plan->warnings !== []) {
            $this->newLine();
            $this->line('<comment>Warnings:</comment>');
            foreach ($plan->warnings as $w) {
                $this->line("  ! line {$w['line']} {$w['sku']}: {$w['message']}");
            }
        }

        if ($plan->stillUnpriced !== []) {
            $this->newLine();
            // ⚠⚠ The number that actually matters. A product with no price
            // cannot be sold at all: the resolver returns null and the sale
            // throws.
            $this->line('<comment>Active products STILL WITHOUT A PRICE after this import ('.count($plan->stillUnpriced).'):</comment>');
            $this->line('  '.implode(', ', $plan->stillUnpriced));
        }

        if ($plan->errors !== []) {
            $this->newLine();
            $this->line('<fg=red>Errors:</>');
            foreach ($plan->errors as $e) {
                $this->line("  x line {$e['line']} {$e['sku']}: {$e['message']}");
            }
        }
    }

    private function resolveBusiness(): ?Business
    {
        $key = $this->option('business');

        if ($key === null) {
            $this->error('Pass --business= so the prices cannot land in the wrong set of books.');

            return null;
        }

        $business = Business::withoutGlobalScopes()
            ->where('slug', $key)
            ->orWhere('id', is_numeric($key) ? (int) $key : 0)
            ->first();

        if ($business === null) {
            $this->error("No business matches '{$key}'.");
        }

        return $business;
    }

    private function resolveEffectiveFrom(): ?Carbon
    {
        $raw = $this->option('effective-from');

        if ($raw === null) {
            // ⚠ No default. "Today" would silently backdate or forward-date a
            // price list depending on when somebody happened to run it, and a
            // price's start date is the thing every invoice is explained by.
            $this->error('Pass --effective-from=YYYY-MM-DD. A price list without a start date cannot be resolved against any sale.');

            return null;
        }

        // ⚠ Day first or ISO only (18 Oct 2026): Carbon::parse would read
        // 05/09/2026 as 9 May.
        $iso = DayFirstDate::toIso($raw);

        if ($iso === null) {
            $this->error("'{$raw}' is not a date this command understands. Use YYYY-MM-DD or DD/MM/YYYY.");

            return null;
        }

        return Carbon::createFromFormat('Y-m-d', $iso)->startOfDay();
    }
}
