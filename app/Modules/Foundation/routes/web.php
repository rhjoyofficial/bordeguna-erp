<?php

use App\Modules\Foundation\Http\Controllers\AttachmentController;
use App\Modules\Foundation\Http\Controllers\BusinessSettingsController;
use App\Modules\Foundation\Http\Controllers\BusinessSwitcherController;
use App\Modules\Foundation\Http\Controllers\CalendarController;
use App\Modules\Foundation\Http\Controllers\ChatController;
use App\Modules\Foundation\Http\Controllers\FileManagerController;
use App\Modules\Foundation\Http\Controllers\NoteController;
use App\Modules\Foundation\Http\Controllers\ChangeRequestController;
use App\Modules\Foundation\Http\Controllers\OrganizationController;
use App\Modules\Foundation\Http\Controllers\RoleManagementController;
use App\Modules\Foundation\Http\Controllers\UserManagementController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'business'])->group(function () {
    // ── The business calendar (Joy, 29 Aug 2026) ────────────────────────────
    // ⚠⚠ The calendar OWNS almost nothing it shows. Holidays, approved leave,
    // follow-ups, expected deliveries and due dates all come from the modules
    // that own them, and CalendarFeed gates each source by that module's own
    // permission. Only the hand-typed entries live in `calendar_events`.
    Route::middleware('can:calendar.view')->group(function () {
        Route::get('calendar', [CalendarController::class, 'index'])->name('calendar.index');
    });

    Route::middleware('can:calendar.manage')->group(function () {
        Route::post('calendar', [CalendarController::class, 'store'])->name('calendar.store');
        Route::put('calendar/{event}', [CalendarController::class, 'update'])->name('calendar.update');
        Route::delete('calendar/{event}', [CalendarController::class, 'destroy'])->name('calendar.destroy');
    });

    // ── Chat (Joy, 29 Aug 2026) ─────────────────────────────────────────────
    // ⚠⚠ NO REAL-TIME PUSH. There is no websocket server on this project, so
    // the screen POLLS `chat/{channel}/poll`, which returns JSON and only
    // messages newer than the id the client already holds.
    Route::middleware('can:chat.use')->group(function () {
        Route::get('chat', [ChatController::class, 'index'])->name('chat.index');
        Route::get('chat/{channel}/poll', [ChatController::class, 'poll'])->name('chat.poll');
        Route::post('chat/channels', [ChatController::class, 'storeChannel'])->name('chat.channels.store');
        Route::post('chat/{channel}/messages', [ChatController::class, 'storeMessage'])->name('chat.messages.store');
        Route::delete('chat/messages/{message}', [ChatController::class, 'destroyMessage'])->name('chat.messages.destroy');
    });

    // ── Files (Joy, 29 Aug 2026) ────────────────────────────────────────────
    // ⚠⚠ No new table and no new download route: files already live in
    // `attachments` behind the authenticated `attachments.show` stream. WHO SEES
    // WHAT is decided by FileVisibility, whose default is CLOSED - read it
    // before touching this. A browser over the attachments table is the easiest
    // way in this system to show somebody every payroll document at once.
    Route::middleware('can:files.view')->group(function () {
        Route::get('files', [FileManagerController::class, 'index'])->name('files.index');
    });

    Route::middleware('can:files.manage')->group(function () {
        Route::post('files', [FileManagerController::class, 'store'])->name('files.store');
        // ⚠ Created from the archive screen, not from settings: the moment
        // somebody needs a new kind of document is the moment they are
        // filing one that fits nothing existing.
        Route::post('files/categories', [FileManagerController::class, 'storeCategory'])->name('files.categories.store');
        // ⚠ Refuses anything that is not a LIBRARY file. Evidence attached to a
        // record is removed from that record, where the loss is visible.
        Route::delete('files/{attachment}', [FileManagerController::class, 'destroy'])->name('files.destroy');
    });

    // ── Notes (Joy, 29 Aug 2026) ────────────────────────────────────────────
    // ⚠⚠ NOTHING DELETES. Archive is the only exit, because the one thing
    // anybody asks of a notes system months later is "what was that thing we
    // wrote down", and a delete button is how that answer stops existing.
    Route::middleware('can:notes.view')->group(function () {
        Route::get('notes', [NoteController::class, 'index'])->name('notes.index');
    });

    Route::middleware('can:notes.manage')->group(function () {
        Route::post('notes', [NoteController::class, 'store'])->name('notes.store');
        Route::put('notes/{note}', [NoteController::class, 'update'])->name('notes.update');
        Route::post('notes/{note}/archive', [NoteController::class, 'archive'])->name('notes.archive');
        Route::post('notes/{note}/restore', [NoteController::class, 'restore'])->name('notes.restore');
        Route::post('notes/{note}/pin', [NoteController::class, 'togglePin'])->name('notes.pin');
    });

    // Team / user management — presented under Settings, owned by Foundation.
    // Each action authorizes foundation.users.manage internally; {user} is
    // membership-checked in the controller (users are not tenant-scoped).
    Route::get('settings/team', [UserManagementController::class, 'index'])->name('users.index');
    Route::post('settings/team', [UserManagementController::class, 'store'])->name('users.store');
    Route::put('settings/team/{user}', [UserManagementController::class, 'update'])->name('users.update');
    Route::patch('settings/team/{user}/toggle-active', [UserManagementController::class, 'toggleActive'])->name('users.toggle-active');
    Route::post('settings/team/{user}/password-link', [UserManagementController::class, 'sendPasswordLink'])->name('users.password-link');
    Route::delete('settings/team/{user}', [UserManagementController::class, 'destroy'])->name('users.destroy');

    // Role & permission management — same admin permission as team management.
    // {role} is checked against the current business in the controller.
    Route::get('settings/roles', [RoleManagementController::class, 'index'])->name('roles.index');
    Route::post('settings/roles', [RoleManagementController::class, 'store'])->name('roles.store');
    Route::put('settings/roles/{role}', [RoleManagementController::class, 'update'])->name('roles.update');
    Route::delete('settings/roles/{role}', [RoleManagementController::class, 'destroy'])->name('roles.destroy');

    // Company profile for the current business (name, contact, currency,
    // timezone, fiscal year). Same admin permission as organization structure.
    Route::get('settings/business', [BusinessSettingsController::class, 'edit'])->name('business.edit');
    Route::patch('settings/business', [BusinessSettingsController::class, 'update'])->name('business.update');
    Route::post('settings/business/logo', [BusinessSettingsController::class, 'uploadLogo'])->name('business.logo.store');
    Route::delete('settings/business/logo', [BusinessSettingsController::class, 'deleteLogo'])->name('business.logo.destroy');

    // Streams a stored attachment inline; the binding is business-scoped so a
    // file from another business 404s.
    Route::get('attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');

    // Organization structure — Office → Department → Section tree + Designations.
    // Gated by foundation.organization.manage; all units are business-scoped so
    // route binding confines them to the current business automatically.
    Route::get('settings/organization', [OrganizationController::class, 'index'])->name('organization.index');

    Route::post('settings/organization/offices', [OrganizationController::class, 'storeOffice'])->name('organization.offices.store');
    Route::put('settings/organization/offices/{office}', [OrganizationController::class, 'updateOffice'])->name('organization.offices.update');
    Route::patch('settings/organization/offices/{office}/toggle', [OrganizationController::class, 'toggleOffice'])->name('organization.offices.toggle');
    Route::delete('settings/organization/offices/{office}', [OrganizationController::class, 'destroyOffice'])->name('organization.offices.destroy');
    Route::put('settings/organization/offices/{office}/geofence', [OrganizationController::class, 'updateOfficeGeofence'])->name('organization.offices.geofence');

    Route::post('settings/organization/departments', [OrganizationController::class, 'storeDepartment'])->name('organization.departments.store');
    Route::put('settings/organization/departments/{department}', [OrganizationController::class, 'updateDepartment'])->name('organization.departments.update');
    Route::patch('settings/organization/departments/{department}/toggle', [OrganizationController::class, 'toggleDepartment'])->name('organization.departments.toggle');
    Route::delete('settings/organization/departments/{department}', [OrganizationController::class, 'destroyDepartment'])->name('organization.departments.destroy');

    Route::post('settings/organization/sections', [OrganizationController::class, 'storeSection'])->name('organization.sections.store');
    Route::put('settings/organization/sections/{section}', [OrganizationController::class, 'updateSection'])->name('organization.sections.update');
    Route::patch('settings/organization/sections/{section}/toggle', [OrganizationController::class, 'toggleSection'])->name('organization.sections.toggle');
    Route::delete('settings/organization/sections/{section}', [OrganizationController::class, 'destroySection'])->name('organization.sections.destroy');

    Route::post('settings/organization/designations', [OrganizationController::class, 'storeDesignation'])->name('organization.designations.store');
    Route::put('settings/organization/designations/{designation}', [OrganizationController::class, 'updateDesignation'])->name('organization.designations.update');
    Route::patch('settings/organization/designations/{designation}/toggle', [OrganizationController::class, 'toggleDesignation'])->name('organization.designations.toggle');
    Route::delete('settings/organization/designations/{designation}', [OrganizationController::class, 'destroyDesignation'])->name('organization.designations.destroy');

    Route::get('businesses/switch', [BusinessSwitcherController::class, 'index'])->name('businesses.switch');
    Route::post('businesses/switch', [BusinessSwitcherController::class, 'store'])->name('businesses.switch.store');

    // Generic, workflow-driven approve/reject for change requests. The
    // {changeRequest} binding is business-scoped; the current stage decides who
    // may act (canBeActionedBy) inside the controller.
    Route::post('change-requests/{changeRequest}/approve', [ChangeRequestController::class, 'approve'])->name('change-requests.approve');
    Route::post('change-requests/{changeRequest}/reject', [ChangeRequestController::class, 'reject'])->name('change-requests.reject');
});
