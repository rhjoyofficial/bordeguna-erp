<?php

namespace App\Modules\Purchasing\Console\Commands;

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Purchasing\Actions\ApplySupplierImport;
use App\Modules\Purchasing\Actions\PlanSupplierImport;
use App\Modules\Purchasing\Support\SupplierCsv;
use App\Modules\Purchasing\Support\SupplierImportPlan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Suppliers, as master data under version control rather than typing.
 *
 * ⚠⚠ REPORTS BY DEFAULT, WRITES ONLY WITH --apply. Same rule as the catalogue
 * importer: a tool whose safe mode needs a flag gets run without the flag
 * exactly once.
 *
 * ⚠⚠ RUNS COLD. A console command carries no BusinessContext, and Supplier is
 * business-scoped, so without entering the tenant `BusinessScope` compiles
 * `1 = 0` - the import would read an empty list, believe every supplier is new,
 * and write them into no business at all.
 */
class ImportSuppliers extends Command
{
    protected $signature = 'suppliers:import
                            {file : Path to the supplier CSV}
                            {--business= : Business slug or id the suppliers belong to}
                            {--apply : Write the changes. Without this, nothing is written}';

    protected $description = 'Review, and optionally import, a supplier CSV';

    public function handle(PlanSupplierImport $planner, ApplySupplierImport $applier): int
    {
        $business = $this->resolveBusiness();

        if ($business === null) {
            return self::FAILURE;
        }

        // ⚠⚠ Printed, not assumed. The production container has a cached config,
        // so `docker exec -e DB_DATABASE=...` is silently ignored and the
        // command runs against the LIVE database while the operator believes it
        // is on a scratch copy. Reading this line is the check.
        $this->line('Database: <options=bold>'.DB::connection()->getDatabaseName().'</>');
        $this->line("Business: <options=bold>{$business->name}</> (id {$business->id})");
        $this->newLine();

        try {
            $rows = SupplierCsv::read($this->argument('file'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($rows === []) {
            $this->warn('The file has a valid header and no rows. Nothing to do.');

            return self::SUCCESS;
        }

        TenantScope::enter($business->id);
        $previous = app(BusinessContext::class)->id();
        app(BusinessContext::class)->set($business->id);

        try {
            $plan = $planner->handle($rows);

            $this->report($plan, count($rows));

            if ($plan->hasErrors()) {
                $this->newLine();
                $this->error(count($plan->errors).' error(s). Nothing has been written - fix the file and run again.');

                return self::FAILURE;
            }

            if (! $this->option('apply')) {
                $this->newLine();
                $this->info('Review only. Nothing has been written. Re-run with --apply to import.');

                return self::SUCCESS;
            }

            if (! $plan->wouldChangeAnything()) {
                $this->newLine();
                $this->info('Nothing to change. The supplier list already matches the file.');

                return self::SUCCESS;
            }

            $applier->handle($plan);

            $this->newLine();
            $this->info('Imported: '.count($plan->creates).' created, '.count($plan->updates).' updated.');

            return self::SUCCESS;
        } finally {
            // ⚠ Restored rather than blindly cleared. A long-lived process
            // must not inherit this command's business, and must not lose the
            // one it already had either.
            $previous === null
                ? app(BusinessContext::class)->forget()
                : app(BusinessContext::class)->set($previous);
            TenantScope::leave();
        }
    }

    private function resolveBusiness(): ?Business
    {
        $given = (string) $this->option('business');

        if ($given === '') {
            $this->error('--business is required. Name the business the suppliers belong to, by slug or id.');

            return null;
        }

        $business = Business::withoutGlobalScopes()
            ->when(ctype_digit($given), fn ($q) => $q->where('id', (int) $given), fn ($q) => $q->where('slug', $given))
            ->first();

        if ($business === null) {
            $this->error("No business matches \"{$given}\".");

            return null;
        }

        return $business;
    }

    /**
     * ⚠ Plain lines rather than a Symfony table. A table WRAPS a cell wider
     * than the terminal, splitting an error message across lines - unreadable
     * for the operator, and it silently breaks any test asserting on the text.
     */
    private function report(SupplierImportPlan $plan, int $rowCount): void
    {
        $this->line("Read <options=bold>{$rowCount}</> row(s).");
        $this->line(sprintf(
            'Plan: %d to create, %d to update, %d unchanged, %d error(s), %d warning(s).',
            count($plan->creates), count($plan->updates), count($plan->unchanged),
            count($plan->errors), count($plan->warnings),
        ));

        if ($plan->creates !== []) {
            $this->newLine();
            $this->line('<info>Would create:</>');

            foreach ($plan->creates as $create) {
                $this->line("  line {$create['line']}  {$create['code']}  {$create['name']}");
            }
        }

        if ($plan->updates !== []) {
            $this->newLine();
            $this->line('<info>Would update:</>');

            foreach ($plan->updates as $update) {
                $this->line("  line {$update['line']}  {$update['code']}");

                foreach ($update['changes'] as $field => [$from, $to]) {
                    $this->line("      {$field}: ".$this->readable($from).' -> '.$this->readable($to));
                }
            }
        }

        // ⚠ Shown so the number is never a surprise. These are not touched,
        // deactivated or deleted - a supplier with purchase history must never
        // disappear because somebody imported a shorter spreadsheet.
        if ($plan->absentFromFile !== []) {
            $this->newLine();
            $this->line('In the business but not in this file, left untouched: '.implode(', ', $plan->absentFromFile));
        }

        if ($plan->warnings !== []) {
            $this->newLine();
            $this->line('<comment>Warnings - read these, they do not stop the import:</>');

            foreach ($plan->warnings as $warning) {
                $this->line('  '.$this->where($warning).' '.$warning['message']);
            }
        }

        if ($plan->errors !== []) {
            $this->newLine();
            $this->line('<fg=red;options=bold>Errors - nothing will be written until every one is fixed:</>');

            foreach ($plan->errors as $error) {
                $this->line('  '.$this->where($error).' '.$error['message']);
            }
        }
    }

    /**
     * @param  array{line:int, code:string, message:string}  $issue
     */
    private function where(array $issue): string
    {
        return $issue['line'] === 0 ? "[{$issue['code']}]" : "line {$issue['line']} [{$issue['code']}]";
    }

    private function readable(mixed $value): string
    {
        return match (true) {
            $value === null, $value === '' => '(none)',
            $value === true => 'active',
            $value === false => 'inactive',
            default => (string) $value,
        };
    }
}
