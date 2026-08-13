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

        if ($this->app->runningInConsole()) {
            $this->commands([ImportPriceList::class]);
        }
    }
}
