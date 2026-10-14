<?php

use App\Http\Controllers\FailedJobController;
use App\Http\Controllers\RelaxController;
use App\Http\Controllers\Settings\AiCredentialController;
use App\Http\Controllers\Settings\DashboardAssignmentController;
use App\Http\Controllers\Settings\GreetingController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\PopupController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\ReasonController;
use App\Http\Controllers\Settings\SoundController;
use App\Http\Controllers\Settings\WorkScheduleController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// 'business:optional': these are a person's own pages, reachable with no
// business at all, but when there is one the sidebar and the page must be
// built for it (audit FND-001, 14 Oct 2026).
Route::middleware(['auth', 'business:optional'])->group(function () {
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
    // The popup sound, on or off for oneself (14 Oct 2026).
    Route::patch('settings/popup-sound', [GreetingController::class, 'sound'])->name('popup-sound.update');
    // The work schedule alerts and their sound, on or off for oneself (14 Oct 2026).
    Route::patch('settings/schedule-alerts', [WorkScheduleController::class, 'mine'])->name('schedule-alerts.update');

    // A library sound, for every signed-in person (14 Oct 2026).
    Route::get('sounds/{sound}/file', [SoundController::class, 'file'])->name('sounds.file');
});

/*
 * ⚠⚠ 'business' AS WELL AS 'auth' (audit FND-001 / SWF-001, 14 Oct 2026).
 * The data below is group-wide, but the PERMISSIONS are not: roles live per
 * business (Spatie teams), so `can:system.popups.manage` is only true once a
 * business is resolved. In the plain auth group no business is set, and every
 * Owner and Manager who is not super admin got a 403 on these four screens
 * while the sidebar showed them the links. The tests did not see it because
 * they set the business up front; PopupsAndSoundsTest now clears it first.
 */
Route::middleware(['auth', 'business'])->group(function () {
    // A popup's video or image, and the claim before a once-rule popup shows.
    // Here, not above: both check roles, and roles need the business (SWF-005).
    Route::get('popups/{popup}/media', [PopupController::class, 'media'])->name('popups.media');
    Route::post('popups/{popup}/claim', [PopupController::class, 'claim'])->name('popups.claim');

    // Settings > Sounds and Settings > Popups (Owner, system.popups.manage).
    // Group-wide data: no business_id on the rows.
    Route::middleware('can:system.popups.manage')->group(function () {
        Route::get('settings/sounds', [SoundController::class, 'index'])->name('sounds.index');
        Route::post('settings/sounds', [SoundController::class, 'store'])->name('sounds.store');
        Route::put('settings/sounds/slots', [SoundController::class, 'slots'])->name('sounds.slots');
        Route::post('settings/sounds/{sound}', [SoundController::class, 'update'])->name('sounds.update');
        Route::delete('settings/sounds/{sound}', [SoundController::class, 'destroy'])->name('sounds.destroy');

        Route::get('settings/popups', [PopupController::class, 'index'])->name('popups.index');
        Route::post('settings/popups', [PopupController::class, 'store'])->name('popups.store');
        Route::post('settings/popups/{popup}', [PopupController::class, 'update'])->name('popups.update');
        Route::patch('settings/popups/{popup}/toggle', [PopupController::class, 'toggle'])->name('popups.toggle');
        Route::delete('settings/popups/{popup}', [PopupController::class, 'destroy'])->name('popups.destroy');
    });

    // The Relax zone (14 Oct 2026): the page and its files for anybody signed
    // in; the list itself for Owners and Managers (relax.manage). Group-wide.
    Route::get('relax', [RelaxController::class, 'show'])->name('relax.show');
    Route::get('relax/{item}/media', [RelaxController::class, 'media'])->name('relax.media');

    Route::middleware('can:relax.manage')->group(function () {
        Route::get('settings/relax', [RelaxController::class, 'index'])->name('relax.index');
        Route::post('settings/relax', [RelaxController::class, 'store'])->name('relax.store');
        Route::post('settings/relax/{item}', [RelaxController::class, 'update'])->name('relax.update');
        Route::patch('settings/relax/{item}/toggle', [RelaxController::class, 'toggle'])->name('relax.toggle');
        Route::patch('settings/relax/{item}/move', [RelaxController::class, 'move'])->name('relax.move');
        Route::delete('settings/relax/{item}', [RelaxController::class, 'destroy'])->name('relax.destroy');
    });

    // Settings > Work schedule (schedule.manage: Owner and Manager, 14 Oct 2026).
    // Group-wide: one rhythm for the whole team.
    Route::middleware('can:schedule.manage')->group(function () {
        Route::get('settings/work-schedule', [WorkScheduleController::class, 'index'])->name('work-schedule.index');
        Route::post('settings/work-schedule', [WorkScheduleController::class, 'store'])->name('work-schedule.store');
        Route::put('settings/work-schedule/settings', [WorkScheduleController::class, 'settings'])->name('work-schedule.settings');
        Route::put('settings/work-schedule/{slot}', [WorkScheduleController::class, 'update'])->name('work-schedule.update');
        Route::patch('settings/work-schedule/{slot}/toggle', [WorkScheduleController::class, 'toggle'])->name('work-schedule.toggle');
        Route::delete('settings/work-schedule/{slot}', [WorkScheduleController::class, 'destroy'])->name('work-schedule.destroy');
    });
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
