<?php

use App\Modules\Crm\Http\Controllers\CrmSettingsController;
use App\Modules\Crm\Http\Controllers\LeadController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'business'])->prefix('crm')->name('crm.')->group(function () {
    // Reading is open to anyone who may view leads; changing is gated per
    // operation, the same split Sales and Purchasing use.
    Route::middleware('can:crm.leads.view')->group(function () {
        Route::get('leads', [LeadController::class, 'index'])->name('leads.index');
        // ⚠ Duplicate detection is a READ. It must be available to anyone who can
        // see leads, or the warning cannot fire on the screen that needs it.
        Route::get('leads/duplicates', [LeadController::class, 'duplicates'])->name('leads.duplicates');
    });

    Route::middleware('can:crm.leads.manage')->group(function () {
        Route::post('leads', [LeadController::class, 'store'])->name('leads.store');
        Route::put('leads/{lead}', [LeadController::class, 'update'])->name('leads.update');
    });

    // ⚠ Sources and campaigns are the REPORTING AXES. Staff capture leads;
    // inventing a source mid-campaign is how a report ends up with four
    // spellings of "Meta" (C-CRM-14).
    Route::middleware('can:crm.settings.manage')->group(function () {
        Route::get('settings', [CrmSettingsController::class, 'index'])->name('settings.index');
        Route::post('settings/sources', [CrmSettingsController::class, 'storeSource'])->name('settings.sources.store');
        Route::put('settings/sources/{leadSource}', [CrmSettingsController::class, 'updateSource'])->name('settings.sources.update');
        Route::post('settings/campaigns', [CrmSettingsController::class, 'storeCampaign'])->name('settings.campaigns.store');
        Route::put('settings/campaigns/{campaign}', [CrmSettingsController::class, 'updateCampaign'])->name('settings.campaigns.update');
    });
});
