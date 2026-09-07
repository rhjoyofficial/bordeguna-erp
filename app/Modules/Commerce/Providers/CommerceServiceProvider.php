<?php

namespace App\Modules\Commerce\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Digital commerce and website management.
 *
 * Slice 1.1 registers the module's migrations only. Routes arrive with the
 * admin screens, and the storefront is a separate entry point registered in
 * slice 1.8, deliberately not mixed into the ERP's own route files.
 */
class CommerceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
