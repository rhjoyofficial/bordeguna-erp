<?php

use App\Http\Controllers\ApprovalInboxController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
})->name('home');

Route::middleware(['auth', 'business'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('approvals', [ApprovalInboxController::class, 'index'])->name('approvals.index');

    // The header search. A GET on purpose (the hand-written-fetch trap: a GET
    // needs no CSRF ceremony), JSON in and out, permission-filtered inside.
    Route::get('search', GlobalSearchController::class)->name('search');

    // The bell's notifications. No index page on purpose: each one carries the
    // url of the thing it is about, and the approvals queue is the work list.
    Route::post('notifications/read', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');

    // The floating chat guide. JSON in, JSON out - not a page, so the
    // reachability guard rightly ignores it; its way in is the message
    // button rendered on every sidebar-layout page.
    Route::post('assistant/chat', [AssistantController::class, 'chat'])->name('assistant.chat');
    // ⚠ The assistant's face, served rather than linked: the file lives on the
    // private disk like every other upload in this system. Any signed-in member
    // of the business may see it - it is the picture in their own chat header.
    Route::get('assistant/avatar', [AssistantController::class, 'avatar'])->name('assistant.avatar');
});

// Business switching + team management routes live in the Foundation module
// (app/Modules/Foundation/routes/web.php), loaded by FoundationServiceProvider.

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';

Route::fallback(function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});
