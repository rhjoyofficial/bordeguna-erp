<?php

use App\Http\Controllers\ApprovalInboxController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
})->name('home');

Route::middleware(['auth', 'business'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('approvals', [ApprovalInboxController::class, 'index'])->name('approvals.index');

    // The floating chat guide. JSON in, JSON out - not a page, so the
    // reachability guard rightly ignores it; its way in is the message
    // button rendered on every sidebar-layout page.
    Route::post('assistant/chat', [AssistantController::class, 'chat'])->name('assistant.chat');
});

// Business switching + team management routes live in the Foundation module
// (app/Modules/Foundation/routes/web.php), loaded by FoundationServiceProvider.

require __DIR__ . '/settings.php';
require __DIR__ . '/auth.php';

Route::fallback(function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});
