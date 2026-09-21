<?php

namespace App\Modules\Inventory\Providers;

use App\Modules\Inventory\Console\Commands\ImportOpeningStock;
use App\Modules\Inventory\Console\Commands\ImportProductCatalogue;
use App\Modules\Inventory\Console\Commands\MigrateCatalogue;
use App\Modules\Inventory\Console\Commands\VerifyStockLedger;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Observers\StockMovementObserver;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;

class InventoryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Slice 4: the ledger is the source of truth, so the batch cache derives
        // itself whenever a movement lands. Registered here rather than called
        // by each action - a rule nobody can forget beats a rule nobody must.
        StockMovement::observe(StockMovementObserver::class);

        if ($this->app->runningInConsole()) {
            $this->commands([VerifyStockLedger::class, ImportProductCatalogue::class, ImportOpeningStock::class, MigrateCatalogue::class]);
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        $this->shareAbilities();
    }

    /**
     * What this person may WRITE in Inventory, for the sidebar and the master
     * data screens (21 Sep 2026 audit, B.9 and A.10).
     *
     * ⚠⚠ Staff were shown Production, Stock requests and Purchase units in the
     * menu and Add / Edit / Delete on every master-data page, and every one of
     * them answered 403. A link nobody may open reads as a broken system, not
     * as a permission they do not hold.
     *
     * ⚠ These hide BUTTONS. They are not the boundary - every route still
     * authorizes its own permission, and nobody's permissions change here.
     *
     * ℹ Shared from the module rather than HandleInertiaRequests so Inventory
     * owns its own flags in one object; Inertia merges both. A closure, so it
     * is resolved per page against the signed-in user and the business the
     * request resolved - never at boot, when neither exists yet.
     */
    private function shareAbilities(): void
    {
        Inertia::share('inventoryAbilities', function () {
            $user = request()->user();

            return [
                'manageCatalog' => (bool) $user?->can('inventory.catalog.manage'),
                'adjustStock' => (bool) $user?->can('inventory.stock.adjust'),
                'requestStock' => (bool) $user?->can('inventory.stock.request'),
                'approveStock' => (bool) $user?->can('inventory.stock.approve'),
                'manageProduction' => (bool) $user?->can('inventory.production.manage'),
            ];
        });
    }
}
