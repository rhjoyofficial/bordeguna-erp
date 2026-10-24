<?php

namespace App\Modules\Inventory\Providers;

use App\Modules\Foundation\Corrections\DocumentPolicyRegistry;
use App\Modules\Inventory\Console\Commands\ImportOpeningStock;
use App\Modules\Inventory\Console\Commands\ImportProductCatalogue;
use App\Modules\Inventory\Console\Commands\MigrateCatalogue;
use App\Modules\Inventory\Console\Commands\VerifyStockLedger;
use App\Modules\Inventory\Corrections\CropHarvestActionPolicy;
use App\Modules\Inventory\Corrections\CropHarvestCorrector;
use App\Modules\Inventory\Corrections\CropWriteOffActionPolicy;
use App\Modules\Inventory\Corrections\CropWriteOffCorrector;
use App\Modules\Inventory\Corrections\InventoryAdjustmentActionPolicy;
use App\Modules\Inventory\Corrections\InventoryAdjustmentCorrector;
use App\Modules\Inventory\Corrections\OpeningStockActionPolicy;
use App\Modules\Inventory\Corrections\OpeningStockCorrector;
use App\Modules\Inventory\Corrections\ProductionRunActionPolicy;
use App\Modules\Inventory\Corrections\ProductionRunCorrector;
use App\Modules\Inventory\Corrections\StockTransferActionPolicy;
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
        // ⭐ Farm harvest (24 Oct 2026): its own file, so it never collides.
        $this->loadRoutesFrom(__DIR__.'/../routes/harvest.php');

        $this->shareAbilities();
        $this->registerCorrections();
    }

    /**
     * ⭐ Corrections wave 1, B4 (20 Oct 2026): the stock documents in the
     * corrections process (FINAL-PLAN 3.3). Resolved in boot(), like the
     * Accounting and Purchasing registrations: the registry is a singleton
     * every module adds to.
     */
    private function registerCorrections(): void
    {
        $registry = $this->app->make(DocumentPolicyRegistry::class);

        $registry->register(
            $this->app->make(InventoryAdjustmentActionPolicy::class),
            $this->app->make(InventoryAdjustmentCorrector::class),
        );

        $registry->register(
            $this->app->make(OpeningStockActionPolicy::class),
            $this->app->make(OpeningStockCorrector::class),
        );

        // Cancel and Receive part are the module's own actions: no corrector.
        $registry->register($this->app->make(StockTransferActionPolicy::class));

        $registry->register(
            $this->app->make(ProductionRunActionPolicy::class),
            $this->app->make(ProductionRunCorrector::class),
        );

        // ⭐ Farm harvest (B9, 24 Oct 2026): Reverse only, on both.
        $registry->register(
            $this->app->make(CropHarvestActionPolicy::class),
            $this->app->make(CropHarvestCorrector::class),
        );

        $registry->register(
            $this->app->make(CropWriteOffActionPolicy::class),
            $this->app->make(CropWriteOffCorrector::class),
        );
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
                'manageHarvest' => (bool) $user?->can('inventory.harvest.manage'),
            ];
        });
    }
}
