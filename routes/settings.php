<?php

use App\Http\Controllers\FailedJobController;
use App\Http\Controllers\Settings\AiCredentialController;
use App\Http\Controllers\Settings\DashboardAssignmentController;
use App\Http\Controllers\Settings\GreetingController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\ReasonController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    // ⚠⚠ NO "DELETE MY ACCOUNT" (audit, 13 Oct 2026). Breeze shipped one, and
    // it hard-deleted the user, taking their name off every record and audit
    // entry they had touched. Leaving is deactivation by an administrator on
    // the Team screen, which keeps the history and ends the sessions.

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/appearance');
    })->name('appearance');

    // C-I18N-5: one's own UI language. Auth only, no permission - a user may
    // set their own and can reach nobody else's row through it.
    Route::patch('settings/locale', function (Request $request) {
        $data = $request->validate([
            'locale' => ['required', 'in:en,bn'],
        ]);

        $request->user()->update($data);

        return back();
    })->name('locale.update');

    // The Bismillah greeting, on or off for oneself (11 Oct 2026).
    Route::patch('settings/greeting', [GreetingController::class, 'update'])->name('greeting.update');
});

Route::middleware(['auth', 'business'])->group(function () {
    // The picture lives on the employee record, which is business-scoped, so
    // these two sit here rather than with the plain profile routes above.
    Route::post('settings/profile/photo', [ProfileController::class, 'storePhoto'])->name('profile.photo.store');
    Route::delete('settings/profile/photo', [ProfileController::class, 'destroyPhoto'])->name('profile.photo.destroy');

    // Which dashboard each role lands on (11 Oct 2026). Owner only, by
    // dashboard.view_all, checked in the controller.
    Route::get('settings/dashboards', [DashboardAssignmentController::class, 'index'])->name('dashboard-assignments.index');
    Route::put('settings/dashboards', [DashboardAssignmentController::class, 'update'])->name('dashboard-assignments.update');

    // Background jobs that failed (Owner, system.jobs.manage): read, retry,
    // forget. System-wide: failed_jobs has no business.
    Route::middleware('can:system.jobs.manage')->group(function () {
        Route::get('settings/failed-jobs', [FailedJobController::class, 'index'])->name('failed-jobs.index');
        Route::post('settings/failed-jobs/retry-all', [FailedJobController::class, 'retryAll'])->name('failed-jobs.retry-all');
        Route::post('settings/failed-jobs/{uuid}/retry', [FailedJobController::class, 'retry'])->name('failed-jobs.retry');
        Route::delete('settings/failed-jobs/{uuid}', [FailedJobController::class, 'forget'])->name('failed-jobs.forget');
    });

    Route::get('settings/reasons', [ReasonController::class, 'index'])->name('reasons.index');
    Route::post('settings/reasons', [ReasonController::class, 'store'])->name('reasons.store');
    Route::put('settings/reasons/{reason}', [ReasonController::class, 'update'])->name('reasons.update');
    Route::patch('settings/reasons/{reason}/toggle-active', [ReasonController::class, 'toggleActive'])->name('reasons.toggle-active');

    // AI provider credentials. Owner-only via foundation.organization.manage;
    // the secret is write-only and never comes back out (AiCredential).
    Route::get('settings/ai-providers', [AiCredentialController::class, 'index'])->name('ai-providers.index');
    // ⚠ The master switch for the floating assistant. Separate from a
    // credential: a key can be stored and verified while the feature stays off.
    Route::patch('settings/ai-providers/assistant', [AiCredentialController::class, 'toggleAssistant'])->name('ai-providers.assistant');
    // Which two languages the chat toggle offers, and the monthly ceiling.
    Route::patch('settings/ai-providers/preferences', [AiCredentialController::class, 'updatePreferences'])->name('ai-providers.preferences');
    // ⚠ POST, not PATCH, because it carries a file. PHP does not parse a
    // multipart body on PATCH, so an avatar sent that way arrives empty with
    // no error anywhere - the kind of failure that looks like a broken upload
    // widget for a day.
    Route::post('settings/ai-providers/identity', [AiCredentialController::class, 'updateIdentity'])->name('ai-providers.identity');
    Route::post('settings/ai-providers', [AiCredentialController::class, 'store'])->name('ai-providers.store');
    Route::patch('settings/ai-providers/{credential}/toggle-active', [AiCredentialController::class, 'toggleActive'])->name('ai-providers.toggle-active');
    Route::patch('settings/ai-providers/{credential}/model', [AiCredentialController::class, 'updateModel'])->name('ai-providers.model');
    Route::post('settings/ai-providers/{credential}/verify', [AiCredentialController::class, 'verify'])->name('ai-providers.verify');
    Route::delete('settings/ai-providers/{credential}', [AiCredentialController::class, 'destroy'])->name('ai-providers.destroy');
});
