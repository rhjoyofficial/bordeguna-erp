<?php

use App\Modules\Crm\Http\Controllers\CrmActivityController;
use App\Modules\Crm\Http\Controllers\CrmReportController;
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
        // ⚠ Linking a duplicate deletes nothing and moves nothing; it records
        // which of two leads should be worked. A working-list correction, so it
        // needs no permission beyond managing leads.
        Route::post('leads/{lead}/duplicate-of', [LeadController::class, 'linkDuplicate'])->name('leads.duplicate-of');
    });

    // ⚠⚠ Conversion creates MASTER DATA with a permanent code, so it is its own
    // permission and deliberately NOT given to Staff. They qualify a lead;
    // deciding that a customer record exists is a manager's call (C-CRMB-14).
    Route::middleware('can:crm.leads.convert')->group(function () {
        Route::post('leads/{lead}/convert', [LeadController::class, 'convert'])->name('leads.convert');
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

    // ── Phase B: timeline and work queue ────────────────────────────────────
    // ⚠ The subject is `lead` or `customer`, constrained in the route so a
    // typo 404s at routing rather than reaching the controller as a null model.
    Route::middleware('can:crm.leads.view')->group(function () {
        // ⚠⚠ Declared BEFORE the {type}/{id} pattern, which would otherwise
        // swallow `/crm/queue` as a subject called "queue".
        Route::get('queue', [CrmActivityController::class, 'queue'])->name('queue');
        Route::get('reports', [CrmReportController::class, 'index'])->name('reports');

        Route::get('{type}/{id}/timeline', [CrmActivityController::class, 'show'])
            ->whereIn('type', ['lead', 'customer'])->whereNumber('id')->name('timeline');
    });

    Route::middleware('can:crm.leads.manage')->group(function () {
        Route::post('{type}/{id}/activities', [CrmActivityController::class, 'store'])
            ->whereIn('type', ['lead', 'customer'])->whereNumber('id')->name('activities.store');
        Route::post('{type}/{id}/tasks', [CrmActivityController::class, 'storeTask'])
            ->whereIn('type', ['lead', 'customer'])->whereNumber('id')->name('tasks.store');
        Route::put('tasks/{task}', [CrmActivityController::class, 'updateTask'])->name('tasks.update');

        // ⚠ Interest is CLOSED, never deleted - there is deliberately no
        // DELETE route. A demand signal that quietly disappears is one nobody
        // can audit.
        Route::post('{type}/{id}/interests', [CrmActivityController::class, 'storeInterest'])
            ->whereIn('type', ['lead', 'customer'])->whereNumber('id')->name('interests.store');
        Route::put('interests/{interest}/close', [CrmActivityController::class, 'closeInterest'])->name('interests.close');
    });
});
