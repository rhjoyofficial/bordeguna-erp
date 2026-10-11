<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Support\Realtime\LivePages;
use App\Support\Realtime\RealtimeEvents;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Guard: the pages Joy wants live keep their `live` declaration (realtime
 * everywhere, 11 Oct 2026). A page that loses it silently falls back to the
 * 30 s poll, which is exactly the regression this catches.
 */
const LIVE_REQUIRED = [
    'sales.orders.index' => 'sales_order',
    'sales.orders.show' => 'sales_order',
    'sales.deliveries.index' => 'delivery',
    'sales.consignments.index' => 'consignment',
    'sales.invoices.index' => 'sales_invoice',
    'sales.invoices.show' => 'sales_invoice',
    'sales.receipts.index' => 'customer_receipt',
    'sales.courier-statements.index' => 'courier_statement',
    'sales.courier-statements.show' => 'courier_statement',
    'purchasing.purchase-orders.index' => 'purchase_order',
    'purchasing.purchase-orders.show' => 'purchase_order',
    'purchasing.bills.index' => 'supplier_bill',
    'purchasing.bills.show' => 'supplier_bill',
    'inventory.stock.index' => 'stock',
    'inventory.low-stocks.index' => 'stock',
    'approvals.index' => 'approval_request',
    'approvals.history' => 'approval_request',
    'work' => 'approval_request',
    'notifications.index' => 'notification',
    'hrm.attendance.daily.index' => 'attendance_day',
    'hrm.leave.index' => 'leave_request',
    'hrm.expense-claims.index' => 'expense_claim',
    'accounting.vouchers.index' => 'voucher',
    'accounting.vouchers.show' => 'voucher',
];

it('keeps a live declaration on every page that must refresh itself', function () {
    foreach (LIVE_REQUIRED as $route => $kind) {
        expect(Route::has($route))->toBeTrue("route {$route} is gone")
            ->and(LivePages::forRoute($route))->toContain($kind);
    }
});

it('declares only kinds the server actually broadcasts', function () {
    $kinds = RealtimeEvents::kinds();

    foreach (LivePages::ROUTES as $route => $declared) {
        expect(Route::has($route))->toBeTrue("route {$route} is gone");

        foreach ($declared as $kind) {
            expect($kinds)->toContain($kind);
        }
    }

    foreach (RealtimeEvents::MODELS as $class) {
        expect(class_exists($class))->toBeTrue();
    }
});

it('shares the channels and the page kinds with every page', function () {
    $this->withoutVite();
    $business = Business::create(['name' => 'Live Co', 'slug' => 'live-co']);
    app(BusinessContext::class)->set($business->id);
    setPermissionsTeamId($business->id);
    $user = User::factory()->create();
    $user->businesses()->attach($business->id);
    config(['broadcasting.default' => 'reverb']);

    $this->actingAs($user)->withSession(['current_business_id' => $business->id])->get('/notifications')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('live', ['notification'])
            ->where('realtime.enabled', true)
            ->where('realtime.business_channel', "business.{$business->id}")
            ->where('realtime.user_channel', "user.{$user->id}"));
});

it('mounts the live refresh in the app shell and keeps the poll only as its fallback', function () {
    $layout = file_get_contents(resource_path('js/layouts/app/app-sidebar-layout.tsx'));
    $app = file_get_contents(resource_path('js/app.tsx'));
    $dashboardHook = file_get_contents(resource_path('js/components/dashboards/use-dashboard-channel.ts'));

    expect($layout)->toContain('useLiveReload()')->toContain('<LivePulse />')
        ->and($app)->toContain('socketCoversPage()')
        // Leaving a shared channel would silence the other listener on it.
        ->and($dashboardHook)->not->toContain('.leave(');
});
