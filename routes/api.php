<?php

use App\Http\Controllers\Api\Hq\HqOrderReadController;
use App\Http\Controllers\Api\Hq\HqReadController;
use App\Http\Controllers\Api\Hq\HqSalesOrderController;
use App\Http\Controllers\Api\Hq\HqSalesOrderDraftController;
use App\Http\Middleware\HqApiContext;
use Illuminate\Support\Facades\Route;

/*
 | HQ read-first API - consumed by BordeGuna-HeadQuarter (docs/35 section D there).
 |
 | Authentication: a Sanctum personal access token issued to the dedicated `hq` user by
 | `php artisan hq:api:token`. Every token carries the ability `hq:business:{id}` for exactly one
 | business plus the ERP permission names it may exercise. HqApiContext checks the ability against
 | the {business} in the URL and enters the tenant scope from the TOKEN, never from a header.
 |
 | Read-first: the writes are POST tickets; since HQ docs/83 C.3, POST sales-order-drafts (a draft and
 | nothing after it, behind `sales.orders.draft`); and since HQ docs/88 A point 3, POST sales-orders
 | (an approved draft becomes an order that WAITS in the ERP's approval inbox, behind
 | `sales.orders.record`) and POST sales-orders/{reference}/cancel (only an order HQ placed, only
 | while nothing has been delivered, behind `sales.orders.cancel`). Each write ability is issued only
 | by name with `hq:api:token --write`. POST reports/{key}/run, POST order-reads/customer-eligibility
 | and POST sales-orders/quote are reads sent as POST. Payroll, salary, journals, stock adjustments,
 | approving anything, and any order HQ did not place are never reachable here.
 */
Route::prefix('hq/v1')->name('hq.')->middleware(['auth:sanctum', 'throttle:120,1'])->group(function () {
    Route::get('businesses', [HqReadController::class, 'businesses'])->name('businesses');

    Route::prefix('{business}')->middleware(HqApiContext::class)->group(function () {
        Route::get('overview', [HqReadController::class, 'overview'])->name('overview');
        Route::get('stock', [HqReadController::class, 'stock'])->name('stock');
        Route::get('stock/low', [HqReadController::class, 'lowStock'])->name('stock.low');
        Route::get('products', [HqReadController::class, 'products'])->name('products');
        Route::get('products/{sku}', [HqReadController::class, 'product'])->name('products.show');
        Route::get('customers', [HqReadController::class, 'customers'])->name('customers');
        Route::get('customers/{customer}', [HqReadController::class, 'customer'])->name('customers.show');
        Route::get('tickets', [HqReadController::class, 'tickets'])->name('tickets');
        Route::post('tickets', [HqReadController::class, 'storeTicket'])->name('tickets.store');
        Route::get('approvals/pending', [HqReadController::class, 'pendingApprovals'])->name('approvals.pending');
        Route::get('reports', [HqReadController::class, 'reports'])->name('reports');
        Route::post('reports/{key}/run', [HqReadController::class, 'runReport'])->name('reports.run');

        // Phase 4 read side (HQ docs/81 C.5): what HQ must know before it could ever take an order.
        // ⛔ Reads only. The customer check is a POST so the phone stays out of access logs; it writes nothing.
        Route::prefix('order-reads')->name('order-reads.')->group(function () {
            Route::get('products', [HqOrderReadController::class, 'products'])->name('products');
            Route::get('skus/{sku}', [HqOrderReadController::class, 'sku'])->name('skus.show');
            Route::get('prices/{sku}', [HqOrderReadController::class, 'price'])->name('prices.show');
            Route::get('availability/{sku}', [HqOrderReadController::class, 'availability'])->name('availability.show');
            Route::post('customer-eligibility', [HqOrderReadController::class, 'customerEligibility'])->name('customer-eligibility');
        });

        // Phase 4 draft write (HQ docs/83 C.3): a DRAFT sales order and nothing after it. No order, no
        // number, no approval, no customer, no stock, no journal, no notification. ⛔ No route changes or
        // cancels a draft; the draft stays exactly as written even after an order is made from it.
        Route::post('sales-order-drafts', [HqSalesOrderDraftController::class, 'store'])->name('sales-order-drafts.store');
        Route::get('sales-order-drafts/{key}', [HqSalesOrderDraftController::class, 'show'])->name('sales-order-drafts.show');

        // Phase 4 order completion (HQ docs/88 A point 3): the quote (a read), an order made from an
        // approved draft (it WAITS for approval here; HQ never approves), the read-back of orders HQ
        // placed, and HQ's cancellation of one while nothing has left. ⛔ `by-key` before `{reference}`.
        Route::post('sales-orders/quote', [HqSalesOrderController::class, 'quote'])->name('sales-orders.quote');
        Route::post('sales-orders', [HqSalesOrderController::class, 'store'])->name('sales-orders.store');
        Route::get('sales-orders/by-key/{key}', [HqSalesOrderController::class, 'byKey'])->name('sales-orders.by-key');
        Route::get('sales-orders/{reference}', [HqSalesOrderController::class, 'show'])->name('sales-orders.show');
        Route::post('sales-orders/{reference}/cancel', [HqSalesOrderController::class, 'cancel'])->name('sales-orders.cancel');
    });
});
