<?php

use App\Http\Controllers\ApprovalInboxController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('landing')
        : redirect()->route('login');
})->name('home');

Route::middleware(['auth', 'business'])->group(function () {
    // C-LAND-4: what each person sees first. Login and `/` come through here;
    // the business middleware has already resolved the tenant and the
    // permission team, so the resolver never re-implements either. Visiting
    // /dashboard directly still shows the dashboard - this route decides
    // where login GOES, it forbids nothing.
    Route::get('landing', function () {
        return redirect(\App\Support\LandingPages::resolveFor(request()->user()));
    })->name('landing');

    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // MY WORK (Module 1, Slice 3): the personal queue - approvals, follow-ups,
    // to-dos, waiting-on-others, and the role-gated warning sections.
    Route::get('work', App\Http\Controllers\MyWorkController::class)->name('work');

    // ── The group layer (Module 6): reads THROUGH per-company records for
    // the businesses the viewer belongs to. Links, never migrations.
    Route::get('group/partners', [App\Modules\Foundation\Http\Controllers\GroupPartnerController::class, 'index'])->name('group.partners');
    Route::post('group/partners/link', [App\Modules\Foundation\Http\Controllers\GroupPartnerController::class, 'link'])->name('group.partners.link');
    Route::delete('group/partners/{link}', [App\Modules\Foundation\Http\Controllers\GroupPartnerController::class, 'unlink'])->name('group.partners.unlink');
    Route::get('group/products', [App\Modules\Foundation\Http\Controllers\GroupProductController::class, 'index'])->name('group.products');
    Route::post('group/products/link', [App\Modules\Foundation\Http\Controllers\GroupProductController::class, 'link'])->name('group.products.link');
    Route::delete('group/products/{link}', [App\Modules\Foundation\Http\Controllers\GroupProductController::class, 'unlink'])->name('group.products.unlink');
    Route::get('group/cockpit', [App\Modules\Foundation\Http\Controllers\GroupCockpitController::class, 'index'])->name('group.cockpit');

    // Module 7: the morning walk around the shop, done by the system.
    Route::get('health', [App\Modules\Foundation\Http\Controllers\BusinessHealthController::class, 'index'])->name('health');
    Route::get('approvals', [ApprovalInboxController::class, 'index'])->name('approvals.index');

    // The header search. A GET on purpose (the hand-written-fetch trap: a GET
    // needs no CSRF ceremony), JSON in and out, permission-filtered inside.
    Route::get('search', GlobalSearchController::class)->name('search');

    // The bell's notifications, and the inbox behind it. The inbox exists
    // because the bell shows five and production was holding ninety-nine
    // unread: everything past the fifth had no URL anywhere in the app.
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('notifications/{notification}/unread', [NotificationController::class, 'markUnread'])->name('notifications.unread');

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
