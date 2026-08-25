<?php

use App\Modules\Foundation\Http\Controllers\AttachmentController;
use App\Modules\Foundation\Http\Controllers\BusinessSettingsController;
use App\Modules\Foundation\Http\Controllers\BusinessSwitcherController;
use App\Modules\Foundation\Http\Controllers\ChangeRequestController;
use App\Modules\Foundation\Http\Controllers\OrganizationController;
use App\Modules\Foundation\Http\Controllers\RoleManagementController;
use App\Modules\Foundation\Http\Controllers\UserManagementController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'business'])->group(function () {
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
