<?php

namespace App\Modules\Accounting\Providers;

use App\Modules\Accounting\Console\Commands\ReconcileSubledgers;
use App\Modules\Accounting\Console\Commands\ReleaseDueAmortizations;
use App\Modules\Accounting\Console\Commands\VerifyAccountingLedger;
use App\Modules\Accounting\Reporting\Definitions\BudgetVsActualReport;
use App\Modules\Accounting\Support\ChartPermissions;
use App\Modules\Hrm\Reporting\ReportRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AccountingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([VerifyAccountingLedger::class, ReconcileSubledgers::class, ReleaseDueAmortizations::class]);
        }

        // The old all-in-one chart key keeps every split power (ChartPermissions).
        ChartPermissions::register();

        // ⭐ 26 Sep 2026 (Q19): the three operating reports open for whoever
        // holds the narrow key (the Manager) OR reads the whole ledger. Spatie's
        // before-hook answers true for the key itself and falls through here
        // when the user lacks it, so this closure only has to know the wide one.
        Gate::define('accounting.operations.view', fn ($user) => $user->can('accounting.view'));

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // ⚠ Resolved in boot(), never register() - the registry singleton is
        // built by HrmServiceProvider::register, and every register() runs
        // before any boot(). Same pattern, same reason, as Purchasing.
        $this->app->make(ReportRegistry::class)
            ->register($this->app->make(BudgetVsActualReport::class));
    }
}
