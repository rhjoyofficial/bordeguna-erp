<?php

use App\Modules\Sales\Http\Controllers\CounterSaleController;
use App\Modules\Sales\Http\Controllers\PosShiftController;
use App\Modules\Sales\Http\Controllers\CustomerController;
use App\Modules\Sales\Http\Controllers\CustomerImportController;
use App\Modules\Sales\Http\Controllers\DeliveryController;
use App\Modules\Sales\Http\Controllers\DiscountController;
use App\Modules\Sales\Http\Controllers\PriceImportController;
use App\Modules\Sales\Http\Controllers\ProductPriceController;
use App\Modules\Sales\Http\Controllers\SalesInvoiceController;
use App\Modules\Sales\Http\Controllers\SalesOrderController;
use App\Modules\Sales\Http\Controllers\SalesReturnController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'business'])->prefix('sales')->name('sales.')->group(function () {
    // Reading is open to any business member who may view sales; changing
    // anything is gated per operation, the same split Purchasing uses.
    Route::middleware('can:sales.reports.view')->group(function () {
        Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
        // C-360: the customer profile. ⚠ whereNumber, because
        // customers/template and customers/export are real routes below that a
        // bare {customer} would swallow.
        Route::get('customers/{customer}', [CustomerController::class, 'show'])
            ->whereNumber('customer')->name('customers.show');
        Route::get('prices', [ProductPriceController::class, 'index'])->name('prices.index');
        Route::get('invoices', [SalesInvoiceController::class, 'index'])->name('invoices.index');
        Route::get('deliveries', [DeliveryController::class, 'index'])->name('deliveries.index');
        Route::get('orders', [SalesOrderController::class, 'index'])->name('orders.index');
        Route::get('orders/{order}', [SalesOrderController::class, 'show'])
            ->whereNumber('order')->name('orders.show');

        // The sheet the warehouse walks around with. Reading it is the same
        // permission as reading the order it picks.
        Route::get('orders/{order}/pick-list', [SalesOrderController::class, 'pickList'])
            ->whereNumber('order')->name('orders.pick-list');
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

        // The list in and out of a spreadsheet. Same permission as editing one
        // by hand: a file that sets price groups and credit limits is the same
        // financial decision taken in bulk.
        Route::get('customers/template', [CustomerImportController::class, 'template'])->name('customers.template');
        Route::get('customers/export', [CustomerImportController::class, 'export'])->name('customers.export');
        Route::post('customers/import/review', [CustomerImportController::class, 'review'])->name('customers.import.review');
        Route::post('customers/import/apply', [CustomerImportController::class, 'apply'])->name('customers.import.apply');
    });

    // ⚠⚠ Setting a price is a margin decision. Deliberately NOT given to Staff.
    // C-IMP-4. Replacing the whole price list is not the same power as adding
    // one rule: prices decide what every future sale is worth.
    Route::middleware('can:sales.prices.import')->group(function () {
        Route::get('prices/import', [PriceImportController::class, 'create'])->name('prices.import');
        Route::post('prices/import/preview', [PriceImportController::class, 'preview'])->name('prices.import.preview');
        Route::post('prices/import', [PriceImportController::class, 'store'])->name('prices.import.store');
    });

    // ⚠ Export is gated with viewing the price screen, not with importing.
    Route::get('prices/export', [PriceImportController::class, 'export'])->name('prices.export');

    Route::middleware('can:sales.prices.manage')->group(function () {
        Route::post('prices', [ProductPriceController::class, 'store'])->name('prices.store');
        Route::post('prices/{productPrice}/close', [ProductPriceController::class, 'close'])->name('prices.close');

        // Discounts. Same table, same resolver, a friendlier door: set a lower
        // price for a period, optionally from a quantity up. Gated with the
        // prices it writes, because that is exactly what it writes.
        Route::get('discounts', [DiscountController::class, 'index'])->name('discounts.index');
        Route::post('discounts', [DiscountController::class, 'store'])->name('discounts.store');
        Route::post('discounts/{discount}/close', [DiscountController::class, 'close'])->name('discounts.close');
    });

    // Selling: the counter, and the distributor challan. Same engine.
    Route::middleware('can:sales.sell')->group(function () {
        // Cashier shifts (Module 3, Slice 1): custody of the till.
        Route::get('shifts', [PosShiftController::class, 'index'])->name('shifts');
        Route::post('shifts/open', [PosShiftController::class, 'open'])->name('shifts.open');
        Route::post('shifts/{shift}/movement', [PosShiftController::class, 'movement'])->name('shifts.movement');
        Route::post('shifts/{shift}/close', [PosShiftController::class, 'close'])->name('shifts.close');
        Route::get('counter', [CounterSaleController::class, 'create'])->name('counter.create');
        Route::post('counter', [CounterSaleController::class, 'store'])->name('counter.store');
        // Held carts: park and resume. A hold is a bookmark, never a document.
        Route::post('counter/hold', [CounterSaleController::class, 'hold'])->name('counter.hold');
        Route::delete('counter/hold/{cart}', [CounterSaleController::class, 'discard'])->name('counter.hold.discard');
        // ⚠ A price check for the counter screen, answered by the SAME resolver
        // the sale uses. A screen that computes its own price is a second
        // pricing engine that disagrees with the invoice.
        //
        // ⚠⚠ WAS A POST, AND SILENTLY NEVER WORKED. The screen sent
        // `X-CSRF-TOKEN` read from a `meta[name="csrf-token"]` tag that this
        // application's layout does not emit, so the header was always empty,
        // every call was rejected 419, and `if (!response.ok) return` swallowed
        // it. The counter simply never filled in a price, with no error
        // anywhere. Nothing caught it because no test can press a button, and
        // the counter has never been used in production.
        //
        // It reads and writes nothing, so it is a GET: no CSRF token to forget,
        // and no mutating fetch bypassing EnsurePageBusinessMatches, whose stamp
        // rides on Inertia's visit hook and never reaches a hand-written fetch.
        Route::get('counter/price', [CounterSaleController::class, 'price'])->name('counter.price');
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

        // The two documents that leave the building. Reading them is the same
        // permission as reading the record they print, and they are separate
        // pages rather than a print stylesheet over the internal view: the
        // internal view carries the journal and the cost released, which is
        // nobody's business outside.
        Route::get('invoices/{invoice}/print', [SalesInvoiceController::class, 'print'])
            ->whereNumber('invoice')->name('invoices.print');
        Route::get('deliveries/{delivery}/print', [DeliveryController::class, 'print'])
            ->whereNumber('delivery')->name('deliveries.print');
        Route::get('receipts/{receipt}/print', [SalesInvoiceController::class, 'printReceipt'])
            ->whereNumber('receipt')->name('receipts.print');
    });

    Route::middleware('can:sales.receipt.record')->group(function () {
        Route::post('invoices/{invoice}/receipts', [SalesInvoiceController::class, 'receipt'])->name('invoices.receipts.store');
    });

    // ⚠ Goods coming back. Recording is Staff-level data entry (C-SR-9, Joy:
    // goods arrive when they arrive); the literal create segment registers
    // before the numeric wildcard, the invoices lesson.
    Route::middleware('can:sales.returns.record')->group(function () {
        Route::get('returns/create', [SalesReturnController::class, 'create'])->name('returns.create');
        Route::post('returns', [SalesReturnController::class, 'store'])->name('returns.store');
    });

    Route::middleware('can:sales.reports.view')->group(function () {
        Route::get('returns', [SalesReturnController::class, 'index'])->name('returns.index');
        Route::get('returns/{salesReturn}', [SalesReturnController::class, 'show'])
            ->whereNumber('salesReturn')->name('returns.show');
    });

    // ⚠⚠ CASH LEAVING THE TILL is Owner/Manager only (C-SR-6/C-SR-9), the same
    // class as cancelling an order.
    Route::middleware('can:sales.returns.refund')->group(function () {
        Route::post('returns/{salesReturn}/refunds', [SalesReturnController::class, 'refund'])->name('returns.refunds.store');
    });
});
