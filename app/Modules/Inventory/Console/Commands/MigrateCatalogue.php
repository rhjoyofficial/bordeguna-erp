<?php

namespace App\Modules\Inventory\Console\Commands;

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\Actions\ApplyProductCatalogueImport;
use App\Modules\Inventory\Actions\PlanCatalogueMigration;
use App\Modules\Inventory\Actions\PlanProductCatalogueImport;
use App\Modules\Inventory\Models\CatalogueMigrationRun;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Support\CatalogueImportPlan;
use App\Modules\Inventory\Support\CatalogueMigrationFile;
use App\Modules\Inventory\Support\CatalogueMigrationPlan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Executes the approved catalogue migration: renames the live products to the
 * new BRAND-CATEGORY-ITEM-SIZE SKUs, retires what the file no longer carries,
 * and hands the rest to the existing catalogue importer for creates and
 * attribute updates.
 *
 * ⚠⚠ REPORTS BY DEFAULT, WRITES ONLY WITH --apply. Same contract as every
 * importer in this project.
 *
 * ⚠⚠ THE PREVIEW IS THE REAL RUN, ROLLED BACK. Everything - renames, retires,
 * the import planner on the post-rename state, creates and updates - executes
 * inside one transaction. Without --apply the transaction is rolled back and
 * the report printed; with --apply it commits. The preview can therefore never
 * disagree with what an apply would do: they are the same code path against
 * the same data.
 *
 * ⚠⚠ RE-DERIVED LIVE. The old-to-new mapping is computed from the database at
 * the moment this runs (Joy's explicit requirement). The approved plan
 * document is the acceptance reference, never the input.
 *
 * ⚠⚠ RUNS COLD, same as catalogue:import: no BusinessContext in a console
 * command, so the tenant is entered explicitly or every scoped query compiles
 * to `1 = 0`.
 */
class MigrateCatalogue extends Command
{
    protected $signature = 'catalogue:migrate
                            {file : Path to the combined catalogue CSV (new SKUs)}
                            {--business= : Business slug or id the catalogue belongs to}
                            {--apply : Write the changes. Without this, everything is rolled back}';

    protected $description = 'Migrate the live catalogue to the new SKU scheme: rename, retire, create - preview first';

    public function handle(
        PlanCatalogueMigration $migrationPlanner,
        PlanProductCatalogueImport $importPlanner,
        ApplyProductCatalogueImport $importApplier,
    ): int {
        $business = $this->resolveBusiness();

        if ($business === null) {
            return self::FAILURE;
        }

        // ⚠⚠ Printed, not assumed - the cached-config trap: `docker exec -e
        // DB_DATABASE=...` is silently ignored in production, so the operator
        // reads this line to know which database is really underneath.
        $this->line('Database: <options=bold>'.DB::connection()->getDatabaseName().'</>');
        $this->line("Business: <options=bold>{$business->name}</> (id {$business->id})");
        $this->newLine();

        try {
            $file = CatalogueMigrationFile::read($this->argument('file'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($file['rows'] === []) {
            $this->warn('The file has a valid header and no rows. Nothing to do.');

            return self::SUCCESS;
        }

        if ($file['ignored'] !== []) {
            $this->line('Columns this migration does not read: '.implode(', ', $file['ignored']));
            $this->newLine();
        }

        TenantScope::enter($business->id);
        DB::beginTransaction();

        try {
            $plan = $migrationPlanner->handle($file['rows']);

            $this->reportMigration($plan, count($file['rows']));

            if ($plan->hasErrors()) {
                DB::rollBack();
                $this->newLine();
                $this->error(count($plan->errors).' error(s). Nothing has been written.');

                return self::FAILURE;
            }

            $this->applyRenamesAndRetires($plan);

            $importPlan = $importPlanner->handle($plan->importRows);

            if ($importPlan->hasErrors() || ! $this->createsAgree($plan, $importPlan)) {
                $this->reportImport($importPlan);
                DB::rollBack();
                $this->newLine();
                $this->error('The import step refused the post-rename state. Nothing has been written.');

                return self::FAILURE;
            }

            $importApplier->handle($importPlan);

            $this->reportImport($importPlan);

            if (! $this->option('apply')) {
                DB::rollBack();
                $this->newLine();
                $this->info('Preview only - the run above was executed and ROLLED BACK. Nothing has been written. Re-run with --apply to commit.');

                return self::SUCCESS;
            }

            CatalogueMigrationRun::create([
                'business_id' => $business->id,
                'filename' => basename($this->argument('file')),
                'file_hash' => hash_file('sha256', $this->argument('file')),
                'renames_count' => count($plan->renames),
                'retires_count' => count($plan->retires),
                'creates_count' => count($plan->creates),
                'keeps_count' => count($plan->keeps),
                'skips_count' => count($plan->skips),
                'mapping' => $plan->auditPayload(),
            ]);

            DB::commit();

            $this->newLine();
            $this->info(sprintf(
                'Applied: %d renamed, %d retired (%d newly deactivated), %d created, %d updated, %d kept, %d skipped.',
                count($plan->renames), count($plan->retires), count($plan->retiresNeedingWrite()),
                count($importPlan->creates), count($importPlan->updates), count($plan->keeps), count($plan->skips),
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        } finally {
            TenantScope::leave();
        }
    }

    private function applyRenamesAndRetires(CatalogueMigrationPlan $plan): void
    {
        // Through the model so the activity log records every SKU change -
        // each rename is then explainable from the product's own history as
        // well as from the migration run's mapping.
        foreach ($plan->renames as $rename) {
            Product::findOrFail($rename['product_id'])->update(['sku' => $rename['new_sku']]);
        }

        foreach ($plan->retiresNeedingWrite() as $retire) {
            Product::findOrFail($retire['product_id'])->update(['is_active' => false]);
        }
    }

    /**
     * The migration's creates and the import planner's creates must be the
     * same set. A row the import planner wants to CREATE that the migration
     * did not predict means a rename failed to pair - executing it would
     * duplicate a live product under its new SKU, which is the exact accident
     * this command exists to prevent.
     */
    private function createsAgree(CatalogueMigrationPlan $plan, CatalogueImportPlan $importPlan): bool
    {
        $predicted = array_column($plan->creates, 'sku');
        $actual = array_column($importPlan->creates, 'sku');

        sort($predicted);
        sort($actual);

        if ($predicted === $actual) {
            return true;
        }

        foreach (array_diff($actual, $predicted) as $sku) {
            $this->error("import would CREATE {$sku}, but the migration did not pair it or predict it as new.");
        }

        foreach (array_diff($predicted, $actual) as $sku) {
            $this->error("the migration predicted CREATE {$sku}, but the import step does not create it.");
        }

        return false;
    }

    /** Plain lines, not tables: a wrapped cell is unreadable and untestable. */
    private function reportMigration(CatalogueMigrationPlan $plan, int $rowCount): void
    {
        $this->line("Read <options=bold>{$rowCount}</> row(s).");
        $this->line(sprintf(
            'Migration: %d keep, %d rename, %d create, %d retire (%d newly deactivated), %d skip, %d error(s), %d warning(s).',
            count($plan->keeps), count($plan->renames), count($plan->creates),
            count($plan->retires), count($plan->retiresNeedingWrite()), count($plan->skips),
            count($plan->errors), count($plan->warnings),
        ));

        if ($plan->keeps !== []) {
            $this->newLine();
            $this->line('<info>Keeps its SKU:</>');

            foreach ($plan->keeps as $keep) {
                $this->line("  {$keep['sku']}  {$keep['name']}");
            }
        }

        if ($plan->renames !== []) {
            $this->newLine();
            $this->line('<info>Renames (live product, new SKU):</>');

            foreach ($plan->renames as $rename) {
                $this->line("  {$rename['old_sku']} -> {$rename['new_sku']}  {$rename['name']}  [{$rename['how']}]");
            }
        }

        if ($plan->creates !== []) {
            $this->newLine();
            $this->line('<info>New products:</>');

            foreach ($plan->creates as $create) {
                $this->line("  line {$create['line']}  {$create['sku']}  {$create['name']}");
            }
        }

        if ($plan->retires !== []) {
            $this->newLine();
            $this->line('<info>Retired (deactivated, NEVER deleted):</>');

            foreach ($plan->retires as $retire) {
                $this->line("  {$retire['sku']}  {$retire['name']}".($retire['was_active'] ? '  [active today]' : '  [already inactive]'));
            }
        }

        if ($plan->skips !== []) {
            $this->newLine();
            $this->line('<info>Skipped:</>');

            foreach ($plan->skips as $skip) {
                $this->line("  line {$skip['line']}  {$skip['sku']}  {$skip['reason']}");
            }
        }

        if ($plan->warnings !== []) {
            $this->newLine();
            $this->line('<comment>Warnings - read these, they do not stop the run:</>');

            foreach ($plan->warnings as $warning) {
                $this->line("  [{$warning['sku']}] {$warning['message']}");
            }
        }

        if ($plan->errors !== []) {
            $this->newLine();
            $this->line('<fg=red;options=bold>Errors - nothing will be written until every one is fixed:</>');

            foreach ($plan->errors as $error) {
                $where = $error['line'] === 0 ? "[{$error['sku']}]" : "line {$error['line']} [{$error['sku']}]";
                $this->line("  {$where} {$error['message']}");
            }
        }
    }

    private function reportImport(CatalogueImportPlan $importPlan): void
    {
        $this->newLine();
        $this->line(sprintf(
            'Import step (after renames): %d to create, %d to update, %d unchanged, %d error(s), %d warning(s).',
            count($importPlan->creates), count($importPlan->updates), count($importPlan->unchanged),
            count($importPlan->errors), count($importPlan->warnings),
        ));

        foreach ($importPlan->errors as $error) {
            $this->line("  line {$error['line']} [{$error['sku']}] {$error['message']}");
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
}
