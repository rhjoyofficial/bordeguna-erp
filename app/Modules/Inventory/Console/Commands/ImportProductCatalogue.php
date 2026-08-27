<?php

namespace App\Modules\Inventory\Console\Commands;

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\Actions\ApplyProductCatalogueImport;
use App\Modules\Inventory\Actions\PlanProductCatalogueImport;
use App\Modules\Inventory\Support\CatalogueImportPlan;
use App\Modules\Inventory\Support\ProductCatalogueFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Stage 3 of the Bor de Guna onboarding: the product catalogue, as master data
 * under version control rather than an afternoon of typing.
 *
 * ⚠⚠ REPORTS BY DEFAULT, WRITES ONLY WITH --apply. Joy's rule for the whole
 * onboarding exercise is that the file is reviewed before anything is
 * generated, and a tool whose safe mode needs a flag gets run without the flag
 * exactly once.
 *
 * ⚠⚠ RUNS COLD. A console command carries no BusinessContext, and every model
 * here is business-scoped, so without entering the tenant `BusinessScope`
 * compiles `1 = 0` - the import would read an empty catalogue, believe every
 * product is new, and then write them into no business at all. Fourth time this
 * project has met "correct inside a request, wrong outside one", so it has its
 * own test.
 */
class ImportProductCatalogue extends Command
{
    protected $signature = 'catalogue:import
                            {file : Path to the catalogue CSV}
                            {--business= : Business slug or id the catalogue belongs to}
                            {--apply : Write the changes. Without this, nothing is written}';

    protected $description = 'Review, and optionally import, a product catalogue CSV';

    public function handle(PlanProductCatalogueImport $planner, ApplyProductCatalogueImport $applier): int
    {
        $business = $this->resolveBusiness();

        if ($business === null) {
            return self::FAILURE;
        }

        // ⚠⚠ Printed, not assumed. The production container has a cached
        // config, so `docker exec -e DB_DATABASE=...` is silently ignored and
        // the command runs against the LIVE database while the operator
        // believes it is on a scratch copy. Reading this line is the check.
        $this->line('Database: <options=bold>'.DB::connection()->getDatabaseName().'</>');
        $this->line("Business: <options=bold>{$business->name}</> (id {$business->id})");
        $this->newLine();

        try {
            $rows = ProductCatalogueFile::read($this->argument('file'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($rows === []) {
            $this->warn('The file has a valid header and no rows. Nothing to do.');

            return self::SUCCESS;
        }

        TenantScope::enter($business->id);

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
                $this->info('Nothing to change. The catalogue already matches the file.');

                return self::SUCCESS;
            }

            $applier->handle($plan);

            $this->newLine();
            $this->info('Imported: '.count($plan->creates).' created, '.count($plan->updates).' updated.');

            return self::SUCCESS;
        } finally {
            // ⚠ Both halves of the tenant are released. A long-lived process
            // must not inherit this one's business.
            TenantScope::leave();
        }
    }

    private function resolveBusiness(): ?Business
    {
        $given = (string) $this->option('business');

        if ($given === '') {
            $this->error('--business is required. Name the business the catalogue belongs to, by slug or id.');

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
     * ⚠ Rendered as plain lines rather than tables on purpose. A Symfony table
     * WRAPS a cell wider than the terminal, which would split an error message
     * across lines - unreadable for the operator, and it silently breaks any
     * test asserting on the message text.
     */
    private function report(CatalogueImportPlan $plan, int $rowCount): void
    {
        $this->line("Read <options=bold>{$rowCount}</> row(s).");
        $this->line(sprintf(
            'Plan: %d to create, %d to update, %d unchanged, %d error(s), %d warning(s).',
            count($plan->creates), count($plan->updates), count($plan->unchanged),
            count($plan->errors), count($plan->warnings),
        ));

        if ($plan->newCategories !== []) {
            $this->line('New categories: '.implode(', ', $plan->newCategories));
        }

        if ($plan->newUnits !== []) {
            $this->line('New units: '.implode(', ', array_map(
                fn (array $u) => "{$u['name']} ({$u['abbreviation']})",
                $plan->newUnits
            )));
        }

        if ($plan->creates !== []) {
            $this->newLine();
            $this->line('<info>Would create:</>');

            foreach ($plan->creates as $create) {
                $this->line("  line {$create['line']}  {$create['sku']}  {$create['name']}");
            }
        }

        if ($plan->updates !== []) {
            $this->newLine();
            $this->line('<info>Would update:</>');

            foreach ($plan->updates as $update) {
                $this->line("  line {$update['line']}  {$update['sku']}");

                foreach ($update['changes'] as $field => [$from, $to]) {
                    $this->line("      {$field}: ".$this->readable($from).' -> '.$this->readable($to));
                }
            }
        }

        // ⚠ C-CAT-1. Shown so the number is never a surprise; these are not
        // touched, deactivated or deleted.
        if ($plan->absentFromFile !== []) {
            $this->newLine();
            $this->line('In the catalogue but not in this file, left untouched: '
                .implode(', ', $plan->absentFromFile));
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

            // ⚠ D-4, said ONCE rather than on every offending row. Brands are
            // few and deliberate, and auto-creating one on a typo would split a
            // brand's margin in two without anybody noticing.
            if ($this->mentionsUnknownBrand($plan)) {
                $this->newLine();
                $this->line('<comment>Brands are never created by an import. Add the brand first, then re-run.</>');
            }
        }
    }

    private function mentionsUnknownBrand(CatalogueImportPlan $plan): bool
    {
        foreach ($plan->errors as $error) {
            if (str_contains($error['message'], 'unknown brand')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{line:int, sku:string, message:string}  $issue
     */
    private function where(array $issue): string
    {
        return $issue['line'] === 0
            ? "[{$issue['sku']}]"
            : "line {$issue['line']} [{$issue['sku']}]";
    }

    private function readable(mixed $value): string
    {
        return match (true) {
            $value === null => '(none)',
            $value === true => 'yes',
            $value === false => 'no',
            default => (string) $value,
        };
    }
}
