<?php

use App\Modules\Sales\Http\Controllers\CounterSaleController;
use App\Modules\Sales\Http\Controllers\CustomerController;
use App\Modules\Sales\Http\Controllers\DeliveryController;
use App\Modules\Sales\Http\Controllers\ProductPriceController;
use App\Modules\Sales\Http\Controllers\SalesInvoiceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'business'])->prefix('sales')->name('sales.')->group(function () {
    // Reading is open to any business member who may view sales; changing
    // anything is gated per operation, the same split Purchasing uses.
    Route::middleware('can:sales.reports.view')->group(function () {
        Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('prices', [ProductPriceController::class, 'index'])->name('prices.index');
        Route::get('invoices', [SalesInvoiceController::class, 'index'])->name('invoices.index');
        Route::get('invoices/{invoice}', [SalesInvoiceController::class, 'show'])->name('invoices.show');
        Route::get('deliveries', [DeliveryController::class, 'index'])->name('deliveries.index');
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
    });

    Route::middleware('can:sales.receipt.record')->group(function () {
        Route::post('invoices/{invoice}/receipts', [SalesInvoiceController::class, 'receipt'])->name('invoices.receipts.store');
    });
});
