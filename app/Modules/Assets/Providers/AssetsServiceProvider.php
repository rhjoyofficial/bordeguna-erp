<?php

namespace App\Modules\Assets\Providers;

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
    }
}
