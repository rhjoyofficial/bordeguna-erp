<?php

namespace App\Modules\Accounting\Providers;

use App\Modules\Accounting\Console\Commands\VerifyAccountingLedger;
use Illuminate\Support\ServiceProvider;

class AccountingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([VerifyAccountingLedger::class]);
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
    }
}
