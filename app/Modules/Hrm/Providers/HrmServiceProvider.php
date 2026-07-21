<?php

namespace App\Modules\Hrm\Providers;

use Illuminate\Support\ServiceProvider;

class HrmServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
    }
}
