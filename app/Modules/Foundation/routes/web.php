<?php

use App\Modules\Foundation\Http\Controllers\BusinessSwitcherController;
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
    Route::delete('settings/team/{user}', [UserManagementController::class, 'destroy'])->name('users.destroy');

    Route::get('businesses/switch', [BusinessSwitcherController::class, 'index'])->name('businesses.switch');
    Route::post('businesses/switch', [BusinessSwitcherController::class, 'store'])->name('businesses.switch.store');
});
