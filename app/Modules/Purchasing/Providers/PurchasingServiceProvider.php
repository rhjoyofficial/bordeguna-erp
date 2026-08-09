<?php

namespace App\Modules\Purchasing\Providers;

use App\Modules\Purchasing\Console\Commands\ExportSuppliers;
use App\Modules\Purchasing\Console\Commands\ImportSuppliers;
use Illuminate\Support\ServiceProvider;

class PurchasingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->commands([ImportSuppliers::class, ExportSuppliers::class]);
        }
    }
}
