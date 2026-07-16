<?php

namespace App\Modules\Foundation\Providers;

use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Support\ServiceProvider;

class FoundationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BusinessContext::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
