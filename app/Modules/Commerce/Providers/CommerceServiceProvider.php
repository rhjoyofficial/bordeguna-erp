<?php

namespace App\Modules\Commerce\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Digital commerce and website management.
 *
 * Slice 1.1 registers the module's migrations and its admin routes. The
 * STOREFRONT is a separate entry point registered in slice 1.8, deliberately
 * not mixed into the ERP's own route files: one is an authenticated back
 * office and the other is anonymous public traffic resolved by hostname.
 */
class CommerceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
    }
}
