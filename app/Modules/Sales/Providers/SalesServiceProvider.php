<?php

namespace App\Modules\Sales\Providers;

use App\Models\User;
use App\Modules\Foundation\Corrections\DocumentPolicyRegistry;
use App\Modules\Foundation\Support\HistoryOnlyTypes;
use App\Modules\Hrm\Reporting\ReportRegistry;
use App\Modules\Sales\Console\Commands\ImportPriceList;
use App\Modules\Sales\Console\Commands\ListBlankCreditLimits;
use App\Modules\Sales\Corrections\CommissionPaymentActionPolicy;
use App\Modules\Sales\Corrections\CommissionPaymentCorrector;
use App\Modules\Sales\Corrections\CommissionSettlementActionPolicy;
use App\Modules\Sales\Corrections\CommissionSettlementCorrector;
use App\Modules\Sales\Corrections\CourierStatementActionPolicy;
use App\Modules\Sales\Corrections\CourierStatementCorrector;
use App\Modules\Sales\Corrections\CustomerAdvanceActionPolicy;
use App\Modules\Sales\Corrections\CustomerAdvanceApplicationCorrector;
use App\Modules\Sales\Corrections\CustomerAdvanceApplicationPolicy;
use App\Modules\Sales\Corrections\CustomerAdvanceCorrector;
use App\Modules\Sales\Corrections\CustomerAdvanceRefundCorrector;
use App\Modules\Sales\Corrections\CustomerAdvanceRefundPolicy;
use App\Modules\Sales\Corrections\CustomerReceiptActionPolicy;
use App\Modules\Sales\Corrections\CustomerReceiptCorrector;
use App\Modules\Sales\Corrections\CustomerReceiptDuplicateRule;
use App\Modules\Sales\Corrections\DeliveryActionPolicy;
use App\Modules\Sales\Corrections\DeliveryCorrector;
use App\Modules\Sales\Corrections\ExtraChargeCorrector;
use App\Modules\Sales\Corrections\ExtraChargePolicy;
use App\Modules\Sales\Corrections\PriceCreditCorrector;
use App\Modules\Sales\Corrections\PriceCreditPolicy;
use App\Modules\Sales\Corrections\SalesInvoiceActionPolicy;
use App\Modules\Sales\Corrections\SalesInvoiceCorrector;
use App\Modules\Sales\Corrections\SalesReturnActionPolicy;
use App\Modules\Sales\Corrections\SalesReturnCorrector;
use App\Modules\Sales\Corrections\SampleIssueActionPolicy;
use App\Modules\Sales\Corrections\SampleIssueCorrector;
use App\Modules\Sales\Corrections\SellerTransferActionPolicy;
use App\Modules\Sales\Corrections\SellerTransferCorrector;
use App\Modules\Sales\Couriers\Console\ImportCourierCredentials;
use App\Modules\Sales\Couriers\Console\SyncCourierLocationsCommand;
use App\Modules\Sales\Couriers\Console\SyncCourierParcelsCommand;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Reporting\Definitions\CourierSettlementAgeingReport;
use App\Modules\Sales\Reporting\Definitions\DeliveryEconomicsReport;
use App\Modules\Sales\Reporting\Definitions\SalesByBrandReport;
use App\Modules\Sales\Reporting\Definitions\SalesByChannelReport;
use App\Modules\Sales\Reporting\Definitions\SalesMarginReport;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class SalesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // ⭐ 26 Sep 2026 (Q21): the order book opens for anyone who may READ
        // sales or RECORD an order. A field officer holds the second only, and
        // SalesOrderController then shows him the orders he took, nobody else's.
        Gate::define('sales.orders.see', fn ($user) => $user->can('sales.reports.view') || $user->can('sales.orders.record'));
        // ⭐ Courier hub slice 4: the parcels list opens for whoever reads sales
        // or follows parcels (couriers.parcels.view).
        Gate::define('sales.parcels.see', fn ($user) => $user->can('sales.reports.view') || $user->can('couriers.parcels.view'));
        // ⭐ The Sales overview (module redesign, 15 Oct 2026) opens for anyone
        // the Sales group itself opens for: whoever may read sales, sell, or
        // take an order. Each card on it is then sent only with the permission
        // the widget behind it checks, so nobody is granted "the overview".
        Gate::define('sales.overview', fn ($user) => $user->can('sales.reports.view') || $user->can('sales.sell') || $user->can('sales.orders.record'));

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // ⚠ Resolved in boot(), never in register() - the registry is a
        // singleton and the other modules already registered into it.
        $registry = $this->app->make(ReportRegistry::class);
        $registry->register($this->app->make(SalesByBrandReport::class));
        // Reports pack (11 Oct 2026): revenue by brand, channel and customer
        // type, margin, delivery economics, courier settlement ageing.
        $registry->register($this->app->make(SalesByChannelReport::class));
        $registry->register($this->app->make(SalesMarginReport::class));
        $registry->register($this->app->make(DeliveryEconomicsReport::class));
        $registry->register($this->app->make(CourierSettlementAgeingReport::class));

        // ⭐ Corrections wave 1 (20 Oct 2026, FINAL-PLAN 3.2): the sales
        // documents in the corrections process. A sales invoice (Credit note,
        // Correct, Void sale at the counter), a customer receipt (Reverse,
        // Correct) and a challan (Reverse). An order is not a posted document:
        // its Edit, Cancel and Close short are SalesOrderController's.
        $corrections = $this->app->make(DocumentPolicyRegistry::class);
        $corrections->register(
            $this->app->make(SalesInvoiceActionPolicy::class),
            $this->app->make(SalesInvoiceCorrector::class),
        );
        $corrections->register(
            $this->app->make(CustomerReceiptActionPolicy::class),
            $this->app->make(CustomerReceiptCorrector::class),
            $this->app->make(CustomerReceiptDuplicateRule::class),
        );
        $corrections->register(
            $this->app->make(DeliveryActionPolicy::class),
            $this->app->make(DeliveryCorrector::class),
        );
        // ⭐ 24 Oct 2026 (wave 1 gaps): a price credit and an extra charge on
        // an issued invoice, through the engine (preview, Tk 20,000, closed
        // month). After sales.invoice, so an invoice's morph still resolves to
        // its own type.
        $corrections->register(
            $this->app->make(PriceCreditPolicy::class),
            $this->app->make(PriceCreditCorrector::class),
        );
        $corrections->register(
            $this->app->make(ExtraChargePolicy::class),
            $this->app->make(ExtraChargeCorrector::class),
        );
        // ⭐ Initial plan B21 (24 Oct 2026): a retail seller's hand-over or
        // return of unsold goods, Reverse only. His reported sales are counter
        // sales, voided through sales.invoice like any other.
        $corrections->register(
            $this->app->make(SellerTransferActionPolicy::class),
            $this->app->make(SellerTransferCorrector::class),
        );
        // ⭐ Commission agreements (Joy's Q18, 25 Oct 2026): a settlement and
        // a payment of owed commission are reversed and kept.
        $corrections->register(
            $this->app->make(CommissionSettlementActionPolicy::class),
            $this->app->make(CommissionSettlementCorrector::class),
        );
        $corrections->register(
            $this->app->make(CommissionPaymentActionPolicy::class),
            $this->app->make(CommissionPaymentCorrector::class),
        );
        // ⭐ B11 (24 Oct 2026): a customer's advance, its use on an invoice and
        // its refund, each reversed through the engine in dependency order.
        $corrections->register(
            $this->app->make(CustomerAdvanceActionPolicy::class),
            $this->app->make(CustomerAdvanceCorrector::class),
        );
        $corrections->register(
            $this->app->make(CustomerAdvanceApplicationPolicy::class),
            $this->app->make(CustomerAdvanceApplicationCorrector::class),
        );
        $corrections->register(
            $this->app->make(CustomerAdvanceRefundPolicy::class),
            $this->app->make(CustomerAdvanceRefundCorrector::class),
        );
        // ⭐ B13 (24 Oct 2026): a sales return, Reverse (a mirror return).
        $corrections->register(
            $this->app->make(SalesReturnActionPolicy::class),
            $this->app->make(SalesReturnCorrector::class),
        );
        // ⭐ B13: a posted courier payout statement, Reverse (then import again).
        $corrections->register(
            $this->app->make(CourierStatementActionPolicy::class),
            $this->app->make(CourierStatementCorrector::class),
        );
        // ⭐ Samples (Joy, 25 Oct 2026): a given sample, Reverse only (goods
        // back onto their batches, the journal mirrored).
        $corrections->register(
            $this->app->make(SampleIssueActionPolicy::class),
            $this->app->make(SampleIssueCorrector::class),
        );

        // ⭐ 24 Oct 2026 (wave 1 gaps): a sales order is not a posted document
        // (its Edit, Cancel and Close short are SalesOrderController's), but it
        // has a History tab. Seen by whoever may open the order itself
        // (SalesOrderController::assertMayOpen).
        $this->app->make(HistoryOnlyTypes::class)->register(
            'sales.order',
            SalesOrder::class,
            fn (SalesOrder $order, User $user) => $user->can('sales.reports.view')
                || ($user->can('sales.orders.record') && (int) $order->created_by === (int) $user->id),
        );

        if ($this->app->runningInConsole()) {
            $this->commands([ImportPriceList::class, ListBlankCreditLimits::class, ImportCourierCredentials::class, SyncCourierLocationsCommand::class, SyncCourierParcelsCommand::class]);
        }
    }
}
