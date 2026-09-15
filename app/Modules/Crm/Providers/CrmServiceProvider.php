<?php

namespace App\Modules\Crm\Providers;

use App\Modules\Crm\Console\Commands\NotifyFollowUpsDue;
use App\Modules\Crm\Console\Commands\SweepDormantCustomersCommand;
use App\Modules\Crm\Support\LeadVisibility;
use Illuminate\Support\ServiceProvider;

class CrmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ⚠ Scoped, not a plain singleton: the team it memoises belongs to ONE
        // request or job. A long-running worker holding a singleton would keep
        // yesterday's org chart after somebody changed supervisors in HRM.
        $this->app->scoped(LeadVisibility::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // ⚠ Registered here, not only scheduled. A command referenced by the
        // scheduler but never registered exists on the production container and
        // nowhere else - including the test suite, which then proves nothing
        // about it.
        if ($this->app->runningInConsole()) {
            $this->commands([NotifyFollowUpsDue::class, SweepDormantCustomersCommand::class]);
        }
    }
}
