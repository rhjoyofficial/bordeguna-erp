<?php

use App\Http\Controllers\Settings\AiCredentialController;
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

    // AI provider credentials. Owner-only via foundation.organization.manage;
    // the secret is write-only and never comes back out (AiCredential).
    Route::get('settings/ai-providers', [AiCredentialController::class, 'index'])->name('ai-providers.index');
    Route::post('settings/ai-providers', [AiCredentialController::class, 'store'])->name('ai-providers.store');
    Route::patch('settings/ai-providers/{credential}/toggle-active', [AiCredentialController::class, 'toggleActive'])->name('ai-providers.toggle-active');
    Route::patch('settings/ai-providers/{credential}/model', [AiCredentialController::class, 'updateModel'])->name('ai-providers.model');
    Route::post('settings/ai-providers/{credential}/verify', [AiCredentialController::class, 'verify'])->name('ai-providers.verify');
    Route::delete('settings/ai-providers/{credential}', [AiCredentialController::class, 'destroy'])->name('ai-providers.destroy');
});
