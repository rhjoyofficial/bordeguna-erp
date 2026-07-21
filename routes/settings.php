<?php

use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\ReasonController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/appearance');
    })->name('appearance');
});

Route::middleware(['auth', 'business'])->group(function () {
    Route::get('settings/reasons', [ReasonController::class, 'index'])->name('reasons.index');
    Route::post('settings/reasons', [ReasonController::class, 'store'])->name('reasons.store');
    Route::put('settings/reasons/{reason}', [ReasonController::class, 'update'])->name('reasons.update');
    Route::patch('settings/reasons/{reason}/toggle-active', [ReasonController::class, 'toggleActive'])->name('reasons.toggle-active');
});
