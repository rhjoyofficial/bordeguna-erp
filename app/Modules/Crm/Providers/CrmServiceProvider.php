<?php

namespace App\Modules\Crm\Providers;

use App\Modules\Crm\Console\Commands\NotifyFollowUpsDue;
use Illuminate\Support\ServiceProvider;

class CrmServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // ⚠ Registered here, not only scheduled. A command referenced by the
        // scheduler but never registered exists on the production container and
        // nowhere else - including the test suite, which then proves nothing
        // about it.
        if ($this->app->runningInConsole()) {
            $this->commands([NotifyFollowUpsDue::class, \App\Modules\Crm\Console\Commands\SweepDormantCustomersCommand::class]);
        }
    }
}
