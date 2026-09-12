<?php

namespace App\Modules\Commerce\Providers;

use App\Modules\Commerce\Http\Middleware\ResolveStorefront;
use App\Modules\Commerce\Sections\SectionCatalogue;
use App\Modules\Commerce\Sections\SectionTypeRegistry;
use App\Modules\Commerce\Support\CartPresenter;
use App\Modules\Commerce\Support\CurrentCart;
use App\Modules\Commerce\Themes\BionicClassicTheme;
use App\Modules\Commerce\Themes\ThemeRegistry;
use Illuminate\Support\Facades\View;
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

        // Themes are CODE registered by key, never uploadable packages. An
        // upload path means arbitrary templating executing inside the system
        // that holds the company's books.
        $this->app->singleton(ThemeRegistry::class, function () {
            $registry = new ThemeRegistry;
            $registry->register(new BionicClassicTheme);

            return $registry;
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        // The public shop. Separate file on purpose: one is an
        // authenticated back office, the other is anonymous traffic
        // resolved by hostname.
        $this->loadRoutesFrom(__DIR__.'/../routes/storefront.php');

        // Slice 2.1. ⚠ The basket count reaches every theme layout from ONE
        // place rather than from each controller that renders a page. A theme
        // that forgets to draw it draws nothing; a controller that forgot to
        // pass it would crash the layout.
        View::composer('themes.*', function ($view) {
            $request = request();
            $store = ResolveStorefront::current($request);

            $view->with('basket', [
                'count' => $store === null ? 0 : CartPresenter::count(CurrentCart::find($request, $store)),
                'url' => '/cart',
            ]);
        });
    }
}
