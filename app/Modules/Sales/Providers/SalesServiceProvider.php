<?php

namespace App\Modules\Sales\Providers;

use App\Modules\Hrm\Reporting\ReportRegistry;
use App\Modules\Sales\Couriers\Console\ImportCourierCredentials;
use App\Modules\Sales\Console\Commands\ImportPriceList;
use App\Modules\Sales\Reporting\Definitions\SalesByBrandReport;
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

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // ⚠ Resolved in boot(), never in register() - the registry is a
        // singleton and the other modules already registered into it.
        $registry = $this->app->make(ReportRegistry::class);
        $registry->register($this->app->make(SalesByBrandReport::class));

        if ($this->app->runningInConsole()) {
            $this->commands([ImportPriceList::class, ImportCourierCredentials::class]);
        }
    }
}
