<?php

namespace App\Modules\Sales\Providers;

use App\Modules\Foundation\Corrections\DocumentPolicyRegistry;
use App\Modules\Hrm\Reporting\ReportRegistry;
use App\Modules\Sales\Console\Commands\ImportPriceList;
use App\Modules\Sales\Corrections\CustomerReceiptActionPolicy;
use App\Modules\Sales\Corrections\CustomerReceiptCorrector;
use App\Modules\Sales\Corrections\CustomerReceiptDuplicateRule;
use App\Modules\Sales\Corrections\DeliveryActionPolicy;
use App\Modules\Sales\Corrections\DeliveryCorrector;
use App\Modules\Sales\Corrections\SalesInvoiceActionPolicy;
use App\Modules\Sales\Corrections\SalesInvoiceCorrector;
use App\Modules\Sales\Couriers\Console\ImportCourierCredentials;
use App\Modules\Sales\Couriers\Console\SyncCourierLocationsCommand;
use App\Modules\Sales\Couriers\Console\SyncCourierParcelsCommand;
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

        if ($this->app->runningInConsole()) {
            $this->commands([ImportPriceList::class, ImportCourierCredentials::class, SyncCourierLocationsCommand::class, SyncCourierParcelsCommand::class]);
        }
    }
}
