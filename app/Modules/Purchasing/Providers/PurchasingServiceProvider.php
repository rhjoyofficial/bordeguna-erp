<?php

namespace App\Modules\Purchasing\Providers;

use App\Models\User;
use App\Modules\Foundation\Corrections\DocumentPolicyRegistry;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\HistoryOnlyTypes;
use App\Modules\Hrm\Reporting\ReportRegistry;
use App\Modules\Purchasing\Console\Commands\ExportSuppliers;
use App\Modules\Purchasing\Console\Commands\ImportSuppliers;
use App\Modules\Purchasing\Console\Commands\RollAndRemindRecurringExpenses;
use App\Modules\Purchasing\Corrections\GoodsReceiptActionPolicy;
use App\Modules\Purchasing\Corrections\GoodsReceiptCorrector;
use App\Modules\Purchasing\Corrections\LandedCostActionPolicy;
use App\Modules\Purchasing\Corrections\LandedCostCorrector;
use App\Modules\Purchasing\Corrections\PurchaseOrderActionPolicy;
use App\Modules\Purchasing\Corrections\PurchaseOrderCorrector;
use App\Modules\Purchasing\Corrections\PurchaseReturnActionPolicy;
use App\Modules\Purchasing\Corrections\PurchaseReturnCorrector;
use App\Modules\Purchasing\Corrections\SupplierBillActionPolicy;
use App\Modules\Purchasing\Corrections\SupplierBillCorrector;
use App\Modules\Purchasing\Corrections\SupplierCreditNoteActionPolicy;
use App\Modules\Purchasing\Corrections\SupplierCreditNoteCorrector;
use App\Modules\Purchasing\Corrections\SupplierDiscountActionPolicy;
use App\Modules\Purchasing\Corrections\SupplierDiscountCorrector;
use App\Modules\Purchasing\Corrections\SupplierPaymentActionPolicy;
use App\Modules\Purchasing\Corrections\SupplierPaymentCorrector;
use App\Modules\Purchasing\Corrections\SupplierPaymentDuplicateRule;
use App\Modules\Purchasing\Models\SupplierAdvance;
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

        // ⭐ Supplier discounts (ruling P3, 18 Oct 2026): reversed only through
        // the corrections process, which puts the cost back on the batches.
        $this->app->make(DocumentPolicyRegistry::class)->register(
            $this->app->make(SupplierDiscountActionPolicy::class),
            $this->app->make(SupplierDiscountCorrector::class),
        );

        // ⭐ Corrections wave 1 (19 Oct 2026): goods receipts are reversed and
        // corrected through the corrections process; purchase orders are
        // registered for their History and actions (they post nothing).
        $this->app->make(DocumentPolicyRegistry::class)->register(
            $this->app->make(GoodsReceiptActionPolicy::class),
            $this->app->make(GoodsReceiptCorrector::class),
        );
        $this->app->make(DocumentPolicyRegistry::class)->register(
            $this->app->make(PurchaseOrderActionPolicy::class),
            $this->app->make(PurchaseOrderCorrector::class),
        );
        // ⭐ Corrections wave 1, B2 (20 Oct 2026): supplier bills, payments,
        // credit notes and purchase returns, each with its own corrector.
        foreach ([
            [SupplierBillActionPolicy::class, SupplierBillCorrector::class, null],
            // ⭐ B13 (24 Oct 2026): a Correct asks "Possible duplicate" too.
            [SupplierPaymentActionPolicy::class, SupplierPaymentCorrector::class, SupplierPaymentDuplicateRule::class],
            [SupplierCreditNoteActionPolicy::class, SupplierCreditNoteCorrector::class, null],
            [PurchaseReturnActionPolicy::class, PurchaseReturnCorrector::class, null],
            // ⭐ Initial plan B24 (25 Oct 2026): Reverse of a posted landed cost.
            [LandedCostActionPolicy::class, LandedCostCorrector::class, null],
        ] as [$policy, $corrector, $duplicate]) {
            $this->app->make(DocumentPolicyRegistry::class)->register($this->app->make($policy), $this->app->make($corrector), $duplicate === null ? null : $this->app->make($duplicate));
        }

        // ⭐ 24 Oct 2026 (wave 1 gaps): a supplier advance has a History tab
        // (paid, applied, refunded, reversed) but no correction actions yet;
        // seen by whoever may open its page (purchasing.reports.view).
        $this->app->make(HistoryOnlyTypes::class)->register(
            'purchasing.supplier_advance',
            SupplierAdvance::class,
            fn (SupplierAdvance $advance, User $user) => $user->can('purchasing.reports.view'),
        );

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
