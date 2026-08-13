<?php

namespace App\Modules\Inventory\Console\Commands;

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\Actions\ApplyOpeningStockImport;
use App\Modules\Inventory\Actions\PlanOpeningStockImport;
use App\Modules\Inventory\Support\OpeningStockFile;
use App\Modules\Inventory\Support\OpeningStockImportPlan;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The 31 August count, as a file rather than an afternoon of typing.
 *
 * ⚠⚠ REPORTS BY DEFAULT, WRITES ONLY WITH --apply, and unlike every other
 * importer here that is not merely a good habit: an opening stock document is
 * written ONCE PER WAREHOUSE for the life of the business. There is no second
 * run to fix a mistake, only an inventory adjustment against the real company's
 * books.
 *
 * ⚠⚠ RUNS COLD. A console command carries no BusinessContext and every model
 * here is business-scoped, so without entering the tenant `BusinessScope`
 * compiles `1 = 0`: the import would find no products, call every SKU unknown,
 * and fail with a page of errors that say nothing true.
 */
class ImportOpeningStock extends Command
{
    protected $signature = 'inventory:import-opening-stock
                            {file : Path to the opening stock CSV}
                            {--business= : Business slug or id the count belongs to}
                            {--as-at= : The date the count was taken (YYYY-MM-DD)}
                            {--note= : A note stored on each document}
                            {--apply : Write the documents. Without this, nothing is written}';

    protected $description = 'Review, and optionally import, an opening stock count';

    public function handle(PlanOpeningStockImport $planner, ApplyOpeningStockImport $applier): int
    {
        $business = $this->resolveBusiness();

        if ($business === null) {
            return self::FAILURE;
        }

        $asAt = $this->resolveAsAt();

        if ($asAt === null) {
            return self::FAILURE;
        }

        // ⚠⚠ Printed, not assumed. The production container has a cached config,
        // so `docker exec -e DB_DATABASE=...` is silently ignored and the
        // command runs against the LIVE database while the operator believes it
        // is on a scratch copy. Reading this line is the check.
        $this->line('Database: <options=bold>'.DB::connection()->getDatabaseName().'</>');
        $this->line("Business: <options=bold>{$business->name}</> (id {$business->id})");
        $this->line('Counted as at: <options=bold>'.$asAt->toDateString().'</>');
        $this->newLine();

        try {
            $rows = OpeningStockFile::read($this->argument('file'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        TenantScope::enter($business->id);

        $plan = $planner->handle($rows, $asAt);

        $this->report($plan, count($rows));

        if ($plan->hasErrors()) {
            $this->newLine();
            $this->error('The file is not fit to import. Nothing was written.');

            return self::FAILURE;
        }

        if (! $this->option('apply')) {
            $this->newLine();
            $this->info('Review only. Re-run with --apply to write these documents.');
            $this->line('⚠ Opening stock is written ONCE PER WAREHOUSE. Check the value above against the');
            $this->line('  accountant\'s opening trial balance before applying.');

            return self::SUCCESS;
        }

        $by = User::query()->orderBy('id')->firstOrFail();

        try {
            $result = $applier->handle($plan, $asAt, $by, $this->option('note'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Wrote {$result['documents']} opening stock document(s), {$result['lines']} line(s).");
        $this->line('Total debited to Stock on Hand: <options=bold>'.$plan->totalValue()->format().'</>');
        $this->line('The same amount is credited to Opening Balance Equity.');

        return self::SUCCESS;
    }

    private function report(OpeningStockImportPlan $plan, int $rowCount): void
    {
        $this->line("Rows read: <options=bold>{$rowCount}</>");
        $this->line('Warehouses: <options=bold>'.count($plan->warehouses).'</>');
        $this->line('Lines to write: <options=bold>'.$plan->lineCount().'</>');
        $this->newLine();

        foreach ($plan->warehouses as $entry) {
            $this->line(
                "  {$entry['warehouseCode']} - {$entry['warehouseName']}: "
                .count($entry['lines']).' line(s), '
                .$plan->valueFor($entry['warehouseId'])->format()
            );
        }

        $this->newLine();
        // ⚠⚠ The figure the accountant's opening trial balance must carry for
        // stock, to the paisa. If the two differ, Opening Balance Equity will
        // never reach zero - and that account reaching zero is the only check
        // that catches an opening position which balances while being wrong.
        $this->line('<options=bold>Total value: '.$plan->totalValue()->format().'</>');
        $this->line('  Dr Stock on Hand / Cr Opening Balance Equity');

        if ($plan->warnings !== []) {
            $this->newLine();
            $this->line('<comment>Warnings:</comment>');
            foreach ($plan->warnings as $w) {
                $this->line("  ! line {$w['line']} {$w['sku']}: {$w['message']}");
            }
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
            $this->error('Pass --business= so the count cannot land in the wrong set of books.');

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

    private function resolveAsAt(): ?Carbon
    {
        $raw = $this->option('as-at');

        if ($raw === null) {
            // ⚠ No default. "Today" would silently date the count on the day
            // somebody happened to run the import, and the opening position is
            // defined by the day the shelves were counted - 31 August - not by
            // when the file reached the ERP.
            $this->error('Pass --as-at=YYYY-MM-DD, the date the shelves were actually counted.');

            return null;
        }

        try {
            return Carbon::parse($raw)->startOfDay();
        } catch (Throwable) {
            $this->error("'{$raw}' is not a date this command understands. Use YYYY-MM-DD.");

            return null;
        }
    }
}
