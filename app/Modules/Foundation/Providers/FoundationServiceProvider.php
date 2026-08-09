<?php

namespace App\Modules\Foundation\Providers;

use App\Modules\Foundation\Console\Commands\TeardownSandbox;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Support\Facades\Gate;
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

        if ($this->app->runningInConsole()) {
            $this->commands([TeardownSandbox::class]);
        }
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // A Super Admin bypasses every permission check across every business.
        // Returning null (not false) for non-super-admins lets the normal
        // spatie permission gates evaluate as usual. Without this the seeded
        // Super Admin holds no roles in any business and would fail every
        // authorize() call — the account exists precisely to have god-mode.
        Gate::before(fn ($user) => $user->is_super_admin ? true : null);
    }
}
