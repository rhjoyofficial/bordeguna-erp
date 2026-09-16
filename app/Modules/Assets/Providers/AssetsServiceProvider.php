<?php

namespace App\Modules\Assets\Providers;

use App\Modules\Assets\Console\Commands\RebuildAssetHoldings;
use Illuminate\Support\ServiceProvider;

/**
 * Fixed assets: what the business owns and uses rather than sells.
 *
 * ⚠ Its own module rather than a corner of Purchasing or Accounting. Buying an
 * asset is not purchasing stock, and an asset register is an operational record
 * that happens to have an accounting consequence - putting it in Accounting
 * would make the ledger own a document, which is the boundary every other
 * integration has been careful to keep.
 */
class AssetsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            // Slice A2: prove the holdings of counted groups against their movements.
            $this->commands([RebuildAssetHoldings::class]);
        }
        // ⚠⚠ Added 9 August. Until this line, the module loaded migrations ONLY:
        // the tables existed, the actions were tested, and there was no way for
        // a person to reach any of it. A delivery van's only reachable path was
        // Purchasing, which turns it into stock and expenses it as cost of goods
        // sold - straight through one month's P&L and off the balance sheet.
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
    }
}
