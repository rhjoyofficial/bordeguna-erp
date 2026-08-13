<?php

namespace App\Modules\Inventory\Providers;

use App\Modules\Inventory\Console\Commands\ImportOpeningStock;
use App\Modules\Inventory\Console\Commands\ImportProductCatalogue;
use App\Modules\Inventory\Console\Commands\VerifyStockLedger;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Observers\StockMovementObserver;
use Illuminate\Support\ServiceProvider;

class InventoryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Slice 4: the ledger is the source of truth, so the batch cache derives
        // itself whenever a movement lands. Registered here rather than called
        // by each action - a rule nobody can forget beats a rule nobody must.
        StockMovement::observe(StockMovementObserver::class);

        if ($this->app->runningInConsole()) {
            $this->commands([VerifyStockLedger::class, ImportProductCatalogue::class, ImportOpeningStock::class]);
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
    }
}
