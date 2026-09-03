<?php

namespace App\Modules\Sales\Providers;

use App\Modules\Sales\Console\Commands\ImportPriceList;
use Illuminate\Support\ServiceProvider;

class SalesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // ⚠ Resolved in boot(), never in register() - the registry is a
        // singleton and the other modules already registered into it.
        $registry = $this->app->make(\App\Modules\Hrm\Reporting\ReportRegistry::class);
        $registry->register($this->app->make(\App\Modules\Sales\Reporting\Definitions\SalesByBrandReport::class));

        if ($this->app->runningInConsole()) {
            $this->commands([ImportPriceList::class]);
        }
    }
}
