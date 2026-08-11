<?php

use App\Modules\Sales\Http\Controllers\CounterSaleController;
use App\Modules\Sales\Http\Controllers\CustomerController;
use App\Modules\Sales\Http\Controllers\DeliveryController;
use App\Modules\Sales\Http\Controllers\ProductPriceController;
use App\Modules\Sales\Http\Controllers\SalesInvoiceController;
use App\Modules\Sales\Http\Controllers\SalesOrderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'business'])->prefix('sales')->name('sales.')->group(function () {
    // Reading is open to any business member who may view sales; changing
    // anything is gated per operation, the same split Purchasing uses.
    Route::middleware('can:sales.reports.view')->group(function () {
        Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('prices', [ProductPriceController::class, 'index'])->name('prices.index');
        Route::get('invoices', [SalesInvoiceController::class, 'index'])->name('invoices.index');
        Route::get('deliveries', [DeliveryController::class, 'index'])->name('deliveries.index');
        Route::get('orders', [SalesOrderController::class, 'index'])->name('orders.index');
        Route::get('orders/{order}', [SalesOrderController::class, 'show'])
            ->whereNumber('order')->name('orders.show');
    });

    // ⚠ Taking an order is order-book data entry, the same trust level as
    // selling (Staff included). Approving it is not - see below.
    Route::middleware('can:sales.orders.record')->group(function () {
        Route::post('orders', [SalesOrderController::class, 'store'])->name('orders.store');
        // C-SO-3: exposure is SHOWN at order time, enforced by nobody.
        Route::post('orders/exposure', [SalesOrderController::class, 'exposure'])->name('orders.exposure');
    });

    // ⚠ C-SO-1: goods leave against an APPROVED order only. Owner and Manager.
    Route::middleware('can:sales.orders.approve')->group(function () {
        Route::post('orders/{order}/approve', [SalesOrderController::class, 'approve'])->name('orders.approve');
        Route::post('orders/{order}/reject', [SalesOrderController::class, 'reject'])->name('orders.reject');
    });

    // ⚠ C-SO-6: erasing a commitment is a management decision. Owner and
    // Manager only, same class as an invoice-level discount.
    Route::middleware('can:sales.orders.cancel')->group(function () {
        Route::post('orders/{order}/cancel', [SalesOrderController::class, 'cancel'])->name('orders.cancel');
        Route::post('orders/{order}/close', [SalesOrderController::class, 'close'])->name('orders.close');
    });

    // ⚠ The customer master carries the credit limit and the price group, so
    // editing it is a financial decision rather than an operational one.
    Route::middleware('can:sales.customers.manage')->group(function () {
        Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
        Route::put('customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
        Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy');
    });

    // ⚠⚠ Setting a price is a margin decision. Deliberately NOT given to Staff.
    Route::middleware('can:sales.prices.manage')->group(function () {
        Route::post('prices', [ProductPriceController::class, 'store'])->name('prices.store');
        Route::post('prices/{productPrice}/close', [ProductPriceController::class, 'close'])->name('prices.close');
    });

    // Selling: the counter, and the distributor challan. Same engine.
    Route::middleware('can:sales.sell')->group(function () {
        Route::get('counter', [CounterSaleController::class, 'create'])->name('counter.create');
        Route::post('counter', [CounterSaleController::class, 'store'])->name('counter.store');
        // ⚠ A price check for the counter screen, answered by the SAME resolver
        // the sale uses. A screen that computes its own price is a second
        // pricing engine that disagrees with the invoice.
        Route::post('counter/price', [CounterSaleController::class, 'price'])->name('counter.price');
        Route::post('deliveries', [DeliveryController::class, 'store'])->name('deliveries.store');
        // Delivering against an order, from the order's own screen.
        Route::post('orders/{order}/deliver', [SalesOrderController::class, 'deliver'])->name('orders.deliver');
        // ⚠ Billing a challan (F1 of SALES-ORDER-ARCHITECTURE.md §11.1: the
        // engine action existed with no route, so a challan could never
        // actually be invoiced). GET before the {invoice} wildcard.
        Route::get('invoices/create', [SalesInvoiceController::class, 'create'])->name('invoices.create');
        Route::post('invoices', [SalesInvoiceController::class, 'store'])->name('invoices.store');
    });

    // ⚠ Registered AFTER invoices/create so the literal segment wins.
    Route::middleware('can:sales.reports.view')->group(function () {
        Route::get('invoices/{invoice}', [SalesInvoiceController::class, 'show'])
            ->whereNumber('invoice')->name('invoices.show');
    });

    Route::middleware('can:sales.receipt.record')->group(function () {
        Route::post('invoices/{invoice}/receipts', [SalesInvoiceController::class, 'receipt'])->name('invoices.receipts.store');
    });
});
