<?php

namespace App\Modules\Purchasing\Providers;

use App\Modules\Foundation\Models\Business;
use App\Modules\Hrm\Reporting\ReportRegistry;
use App\Modules\Purchasing\Console\Commands\ExportSuppliers;
use App\Modules\Purchasing\Console\Commands\ImportSuppliers;
use App\Modules\Purchasing\Console\Commands\RollAndRemindRecurringExpenses;
use App\Modules\Purchasing\Reporting\Definitions\ExpenseRegisterReport;
use App\Modules\Purchasing\Reporting\Definitions\StandingCostsReport;
use App\Modules\Purchasing\Support\SupplierTypes;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class PurchasingServiceProvider extends ServiceProvider
{
    /**
     * Purchasing's own reports, run through the reporting engine that happens
     * to live in the HRM module.
     *
     * ⚠ Registered here rather than added to HRM's own list, so that the module
     * that owns the data owns the declaration. The engine is a shared seam, not
     * an HRM feature — every definition it accepts gets CSV, Excel, PDF, a
     * printable page, the letterhead and the three signature blocks for free,
     * and that is worth crossing a module boundary for.
     */
    private const REPORTS = [
        ExpenseRegisterReport::class,
        StandingCostsReport::class,
    ];

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // ⚠ Resolved in boot(), never in register(). The registry is a singleton
        // built by HrmServiceProvider::register, and every register() runs
        // before any boot() - resolving it earlier would race the module that
        // defines it.
        $registry = $this->app->make(ReportRegistry::class);

        foreach (self::REPORTS as $report) {
            $registry->register($this->app->make($report));
        }

        // ⭐ Supplier types are a list each business keeps (17 Oct 2026). A new
        // business starts with the same seven every existing one was given by
        // the migration. ⚠ hasTable: a migration older than that table may
        // itself create a business.
        Business::created(function (Business $business) {
            if (Schema::hasTable('supplier_types')) {
                SupplierTypes::seedFor($business->id);
            }
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                ImportSuppliers::class,
                ExportSuppliers::class,
                // ⚠⚠ Without this line the nightly standing-cost digest is
                // scheduled against a command Artisan has never heard of, and
                // fails silently every morning at 08:30.
                RollAndRemindRecurringExpenses::class,
            ]);
        }
    }
}
