<?php

namespace App\Modules\Accounting\Providers;

use App\Modules\Accounting\Console\Commands\ReconcileSubledgers;
use App\Modules\Accounting\Console\Commands\VerifyAccountingLedger;
use Illuminate\Support\ServiceProvider;

class AccountingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([VerifyAccountingLedger::class, ReconcileSubledgers::class]);
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // ⚠ Resolved in boot(), never register() - the registry singleton is
        // built by HrmServiceProvider::register, and every register() runs
        // before any boot(). Same pattern, same reason, as Purchasing.
        $this->app->make(\App\Modules\Hrm\Reporting\ReportRegistry::class)
            ->register($this->app->make(\App\Modules\Accounting\Reporting\Definitions\BudgetVsActualReport::class));
    }
}
