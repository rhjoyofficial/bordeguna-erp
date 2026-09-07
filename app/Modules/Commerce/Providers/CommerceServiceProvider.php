<?php

namespace App\Modules\Commerce\Providers;

use App\Modules\Commerce\Sections\SectionCatalogue;
use App\Modules\Commerce\Sections\SectionTypeRegistry;
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
    /**
     * The section contract is registered as a SINGLETON built from code.
     *
     * ⚠ Registered in register() rather than boot() because the registry is
     * a dependency other things resolve, and a registry that is only populated
     * once boot() has run would be empty for anything resolved earlier.
     */
    public function register(): void
    {
        $this->app->singleton(SectionTypeRegistry::class, function () {
            $registry = new SectionTypeRegistry;
            $registry->registerMany(SectionCatalogue::all());

            return $registry;
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
    }
}
