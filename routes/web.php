<?php

use App\Http\Controllers\ApprovalInboxController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\AttentionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardPreferenceController;
use App\Http\Controllers\DashboardWidgetController;
use App\Http\Controllers\GeoLookupController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\MyWorkController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\RoleDashboardController;
use App\Http\Controllers\ScheduleController;
use App\Modules\Commerce\Http\Controllers\StorefrontController;
use App\Modules\Commerce\Http\Middleware\ResolveStorefront;
use App\Modules\Foundation\Http\Controllers\BusinessHealthController;
use App\Modules\Foundation\Http\Controllers\GroupCockpitController;
use App\Modules\Foundation\Http\Controllers\GroupPartnerController;
use App\Modules\Foundation\Http\Controllers\GroupProductController;
use App\Support\Dashboards\DashboardKey;
use App\Support\LandingPages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * ⚠⚠ TWO FRONT DOORS, ONE ROUTE. On the ERP's own hostname this is the sign-in
 * redirect it has always been. On a hostname somebody has attached to a live
 * shop it is that shop's home page - ResolveStorefront has already resolved the
 * store and set the tenant by the time this runs.
 *
 * ⚠ It is `home` rather than a storefront route because `/` is registered by the
 * application itself, and ResolveStorefront names it as the one shared route.
 */
Route::get('/', function (Request $request) {
    if (ResolveStorefront::current($request) !== null) {
        return app(StorefrontController::class)->page($request);
    }

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
        return redirect(LandingPages::resolveFor(request()->user()));
    })->name('landing');

    // Role dashboards (11 Oct 2026): /dashboard is the highest dashboard the
    // person's roles give them in this business; the others by key. The old
    // dashboard lives on, unchanged, as "Classic".
    Route::get('dashboard', [RoleDashboardController::class, 'index'])->name('dashboard');
    Route::get('dashboard/classic', [DashboardController::class, 'index'])->name('dashboard.classic');
    Route::get('dashboard/widgets/{widget}', [DashboardWidgetController::class, 'show'])->name('dashboard.widgets.show');
    Route::put('dashboard/sound', [DashboardPreferenceController::class, 'sound'])->name('dashboard.sound');
    Route::put('dashboard/getting-started/dismiss', [DashboardPreferenceController::class, 'dismissGettingStarted'])->name('dashboard.getting-started.dismiss');
    Route::put('dashboard/{dashboard}/layout', [DashboardPreferenceController::class, 'layout'])
        ->whereIn('dashboard', DashboardKey::values())->name('dashboard.layout');
    Route::get('dashboard/{dashboard}', [RoleDashboardController::class, 'show'])
        ->whereIn('dashboard', DashboardKey::values())->name('dashboard.show');

    // MY WORK (Module 1, Slice 3): the personal queue - approvals, follow-ups,
    // to-dos, waiting-on-others, and the role-gated warning sections.
    Route::get('work', MyWorkController::class)->name('work');

    // ── The group layer (Module 6): reads THROUGH per-company records for
    // the businesses the viewer belongs to. Links, never migrations.
    Route::get('group/partners', [GroupPartnerController::class, 'index'])->name('group.partners');
    Route::post('group/partners/link', [GroupPartnerController::class, 'link'])->name('group.partners.link');
    Route::delete('group/partners/{link}', [GroupPartnerController::class, 'unlink'])->name('group.partners.unlink');
    Route::get('group/products', [GroupProductController::class, 'index'])->name('group.products');
    Route::post('group/products/link', [GroupProductController::class, 'link'])->name('group.products.link');
    Route::delete('group/products/{link}', [GroupProductController::class, 'unlink'])->name('group.products.unlink');
    Route::get('group/cockpit', [GroupCockpitController::class, 'index'])->name('group.cockpit');

    // Module 7: the morning walk around the shop, done by the system.
    Route::get('health', [BusinessHealthController::class, 'index'])->name('health');
    // Needs attention (plan C.4): one ranked list of what is waiting on
    // somebody. Open to every member; each source gates itself.
    Route::get('attention', [AttentionController::class, 'index'])->name('attention');
    // Today's rhythm (14 Oct 2026): the work schedule, read-only, for everybody.
    Route::get('schedule', [ScheduleController::class, 'index'])->name('schedule');
    Route::get('approvals', [ApprovalInboxController::class, 'index'])->name('approvals.index');
    // The read-only record of settled approvals, so an authority can see where a
    // request went after it left the inbox (28 Sep 2026).
    Route::get('approvals/history', [ApprovalInboxController::class, 'history'])->name('approvals.history');

    // The header search. A GET on purpose (the hand-written-fetch trap: a GET
    // needs no CSRF ceremony), JSON in and out, permission-filtered inside.
    Route::get('search', GlobalSearchController::class)->name('search');

    // Bangladesh's thanas and areas, for any ERP form that places something on
    // the map. Public administrative fact; no permission beyond signing in.
    // ⚠⚠ NOT `/geo/...`: the storefront already registers `/geo/thanas` and
    // `/geo/areas` on the same path with no domain, and a second route on an
    // identical method and URI silently REPLACES the first - one of the two
    // screens would have lost its list with nothing failing.
    Route::get('places/thanas', [GeoLookupController::class, 'thanas'])->name('places.thanas');
    Route::get('places/areas', [GeoLookupController::class, 'areas'])->name('places.areas');

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
