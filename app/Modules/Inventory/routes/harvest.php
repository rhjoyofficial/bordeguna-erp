<?php

use App\Modules\Inventory\Http\Controllers\CropHarvestController;
use Illuminate\Support\Facades\Route;

/*
 * ⭐ Farm harvest (B9, 24 Oct 2026): Inventory > Production > Farm harvest.
 * A file of its own so the harvest's routes never collide with the rest of
 * Inventory's. Reverse rides the corrections engine's generic routes.
 */
Route::middleware(['web', 'auth', 'business'])->prefix('inventory')->name('inventory.')->group(function () {
    Route::middleware('can:inventory.harvest.manage')->group(function () {
        Route::get('harvests', [CropHarvestController::class, 'index'])->name('harvests.index');
        Route::post('harvests', [CropHarvestController::class, 'store'])->name('harvests.store');
        Route::post('crop-write-offs', [CropHarvestController::class, 'storeWriteOff'])->name('crop-write-offs.store');
    });

    // The Owner decides, by the `crop_write_off` workflow's stage, asked in the
    // action (ApproveCropWriteOff), as every approval card's endpoint does.
    Route::post('crop-write-offs/{writeOff}/approve', [CropHarvestController::class, 'approve'])->whereNumber('writeOff')->name('crop-write-offs.approve');
    Route::post('crop-write-offs/{writeOff}/reject', [CropHarvestController::class, 'reject'])->whereNumber('writeOff')->name('crop-write-offs.reject');
});
