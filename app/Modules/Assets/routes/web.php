<?php

use App\Modules\Assets\Http\Controllers\DepreciationController;
use App\Modules\Assets\Http\Controllers\FixedAssetController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'business'])->prefix('assets')->name('assets.')->group(function () {
    // ⚠ Reading the register is a permission of its own rather than open to the
    // whole business, unlike purchase orders. What the business owns and what it
    // paid for it is closer to the payroll register than to a stock list.
    Route::middleware('can:assets.view')->group(function () {
        Route::get('/', [FixedAssetController::class, 'index'])->name('index');
        Route::get('depreciation', [DepreciationController::class, 'index'])->name('depreciation.index');
    });

    Route::post('/', [FixedAssetController::class, 'store'])
        ->middleware('can:assets.manage')->name('store');

    // ⚠⚠ Owner only. One run posts cost against EVERY asset at once and cannot
    // be undone by running it again - the idempotence guard makes a second run
    // harmless, not reversible.
    Route::post('depreciation', [DepreciationController::class, 'store'])
        ->middleware('can:assets.depreciation.run')->name('depreciation.store');
});
