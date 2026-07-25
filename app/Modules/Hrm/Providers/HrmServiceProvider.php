<?php

namespace App\Modules\Hrm\Providers;

use App\Modules\Hrm\Console\Commands\RunLeaveYearEnd;
use Illuminate\Support\ServiceProvider;

class HrmServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->commands([RunLeaveYearEnd::class]);
        }
    }
}
