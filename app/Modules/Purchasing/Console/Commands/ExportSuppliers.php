<?php

namespace App\Modules\Purchasing\Console\Commands;

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Support\SupplierCsv;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The supplier list, out to a CSV that the importer will accept back.
 *
 * ⚠⚠ EXPORT AND IMPORT SHARE ONE COLUMN DEFINITION (`SupplierCsv::columns()`).
 * An export the importer rejects is not an export, and the round trip -
 * export, edit in a spreadsheet, import - is the whole reason this exists.
 */
class ExportSuppliers extends Command
{
    protected $signature = 'suppliers:export
                            {file? : Where to write. Omit to print to the screen}
                            {--business= : Business slug or id to export}
                            {--template : Write an EMPTY file with just the header}';

    protected $description = 'Export suppliers to CSV, or write a blank import template';

    public function handle(): int
    {
        // ⚠ The template needs no business and no database: it is a statement
        // about the file format, not about anybody's data. Handled first so
        // `--template` works before a business exists at all.
        if ($this->option('template')) {
            return $this->writeTemplate();
        }

        $business = $this->resolveBusiness();

        if ($business === null) {
            return self::FAILURE;
        }

        $this->line('Database: <options=bold>'.DB::connection()->getDatabaseName().'</>');
        TenantScope::enter($business->id);

        try {
            $suppliers = Supplier::query()->orderBy('code')->get();
            $csv = SupplierCsv::export($suppliers);
        } finally {
            TenantScope::leave();
        }

        $path = $this->argument('file');

        if ($path === null) {
            $this->newLine();
            $this->line($csv);

            return self::SUCCESS;
        }

        file_put_contents($path, $csv);
        $this->info("Exported {$suppliers->count()} supplier(s) from {$business->name} to {$path}");

        return self::SUCCESS;
    }

    private function writeTemplate(): int
    {
        $path = $this->argument('file');
        $csv = SupplierCsv::template();

        if ($path === null) {
            $this->line($csv);
        } else {
            file_put_contents($path, $csv);
            $this->info("Blank template written to {$path}");
        }

        // ⚠⚠ The guidance is PRINTED, never put in the file as an example row.
        // A template carrying a sample supplier is a template somebody imports
        // unchanged, and then a fictional company sits in the purchase ledger
        // for ever.
        $this->newLine();
        $this->line('<options=bold>Columns:</>');

        foreach (SupplierCsv::DESCRIPTIONS as $column => $description) {
            $required = in_array($column, SupplierCsv::MANDATORY, true) ? '<fg=red>*</>' : ' ';
            $this->line(sprintf('  %s %-21s %s', $required, $column, $description));
        }

        $this->newLine();
        $this->line('<fg=red>*</> required. Every other column may be left empty.');

        return self::SUCCESS;
    }

    private function resolveBusiness(): ?Business
    {
        $given = (string) $this->option('business');

        if ($given === '') {
            $this->error('--business is required. Name the business to export, by slug or id.');

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
