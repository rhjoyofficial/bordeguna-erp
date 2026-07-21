<?php

use App\Http\Controllers\ApprovalInboxController;
use App\Http\Controllers\DashboardController;
use App\Modules\Foundation\Http\Controllers\BusinessSwitcherController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
})->name('home');

Route::middleware(['auth', 'business'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('approvals', [ApprovalInboxController::class, 'index'])->name('approvals.index');

    Route::get('businesses/switch', [BusinessSwitcherController::class, 'index'])->name('businesses.switch');
    Route::post('businesses/switch', [BusinessSwitcherController::class, 'store'])->name('businesses.switch.store');
});

require __DIR__ . '/settings.php';
require __DIR__ . '/auth.php';

Route::fallback(function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});
