<?php

use Illuminate\Support\Facades\Route;

/*
 * Sales slice 1 adds customers, prices and accounts, and nothing sells yet.
 * Screens arrive with slice 2 (delivery), slice 3 (invoice) and slice 5 (the
 * counter). The group exists now so the module's provider has a file to load
 * and the prefix is settled in one place rather than argued about per screen.
 */
Route::middleware(['web', 'auth', 'business'])->prefix('sales')->name('sales.')->group(function () {
    //
});
