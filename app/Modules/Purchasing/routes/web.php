<?php

use App\Modules\Purchasing\Http\Controllers\ExpenseVoucherController;
use App\Modules\Purchasing\Http\Controllers\PurchaseOrderController;
use App\Modules\Purchasing\Http\Controllers\SupplierController;
use App\Modules\Purchasing\Http\Controllers\SupplierBillController;
use App\Modules\Purchasing\Http\Controllers\SupplierCreditNoteController;
use App\Modules\Purchasing\Http\Controllers\SupplierImportController;
use App\Modules\Purchasing\Http\Controllers\SupplierPaymentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'business'])->prefix('purchasing')->name('purchasing.')->group(function () {
    // ⚠ Reading purchasing WAS open to the whole business ("a bill nobody can
    // see is a bill nobody can question") - written before the zero-permission
    // Self-service role existed (24 Aug 2026). "The business" now includes
    // logins meant to reach only their own profile, so every read sits behind
    // purchasing.reports.view, which every operational role already holds.
    // The write gates below are unchanged.
    Route::middleware('can:purchasing.reports.view')->group(function () {
        Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
    });

    // ⚠ Exporting the supplier list hands over bank account numbers, BIN and
    // TIN in one file, so it follows the same permission as MANAGING suppliers
    // rather than the open read on the list screen - which deliberately hides
    // those columns from everyone else.
    Route::middleware('can:purchasing.suppliers.manage')->group(function () {
        Route::get('suppliers/template', [SupplierImportController::class, 'template'])->name('suppliers.template');
        Route::get('suppliers/export', [SupplierImportController::class, 'export'])->name('suppliers.export');
        Route::post('suppliers/import/review', [SupplierImportController::class, 'review'])->name('suppliers.import.review');
        Route::post('suppliers/import/apply', [SupplierImportController::class, 'apply'])->name('suppliers.import.apply');

        Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
        Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
        Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])->name('suppliers.destroy');
    });

    // Purchase orders — index/show open to any business member; store/approve/
    // reject/cancel/payment-status/receive each authorize internally.
    Route::middleware('can:purchasing.reports.view')->group(function () {
        Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index');
        Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->name('purchase-orders.show');
    });
    Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchase-orders.store');
    Route::post('purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve'])->name('purchase-orders.approve');
    Route::post('purchase-orders/{purchaseOrder}/reject', [PurchaseOrderController::class, 'reject'])->name('purchase-orders.reject');
    Route::post('purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel');
    Route::post('purchase-orders/{purchaseOrder}/payment-status', [PurchaseOrderController::class, 'updatePaymentStatus'])->name('purchase-orders.payment-status');
    Route::post('purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive'])->name('purchase-orders.receive');
    Route::post('purchase-orders/{purchaseOrder}/returns', [PurchaseOrderController::class, 'returnGoods'])->name('purchase-orders.returns.store');

    // Supplier payments — money actually leaving the business.
    // ⚠ Viewing follows the same rule as bills and orders: anyone in the
    // business may READ the payment history, because a payment nobody can see
    // is a payment nobody can question. Creating one needs the permission.
    Route::middleware('can:purchasing.reports.view')->group(function () {
        Route::get('payments', [SupplierPaymentController::class, 'index'])->name('payments.index');
        Route::get('payments/{payment}', [SupplierPaymentController::class, 'show'])
            ->whereNumber('payment')->name('payments.show');
    });

    Route::middleware('can:purchasing.payment.record')->group(function () {
        // ⚠ Declared BEFORE the {payment} route would otherwise swallow it.
        // `whereNumber` above already prevents that, and both defences are kept
        // because losing either one silently turns /payments/new into a 404.
        Route::get('payments/new', [SupplierPaymentController::class, 'create'])->name('payments.create');
        Route::post('payments', [SupplierPaymentController::class, 'store'])->name('payments.store');
    });

    // Supplier bills - the document Accounts Payable is a ledger of, and the
    // step that lets GRNI clear. Same read/write split as payments and credit
    // notes: anyone in the business may READ what the business has been billed,
    // because a bill nobody can see is a bill nobody can question.
    Route::middleware('can:purchasing.reports.view')->group(function () {
        Route::get('bills', [SupplierBillController::class, 'index'])->name('bills.index');
        Route::get('bills/{bill}', [SupplierBillController::class, 'show'])
            ->whereNumber('bill')->name('bills.show');
    });

    Route::middleware('can:purchasing.bill.record')->group(function () {
        // ⚠ D-SB-1: the form is always opened against ONE purchase order, which
        // is why the route carries it rather than offering a blank bill.
        Route::get('bills/new/{purchaseOrder}', [SupplierBillController::class, 'create'])
            ->whereNumber('purchaseOrder')->name('bills.create');
        Route::post('bills', [SupplierBillController::class, 'store'])->name('bills.store');
        // ⚠ D-SB-6: correction is a reversal, never an edit and never a delete,
        // so there is deliberately no PUT and no DELETE on a bill.
        Route::post('bills/{bill}/reverse', [SupplierBillController::class, 'reverse'])
            ->whereNumber('bill')->name('bills.reverse');
    });

    // Expense vouchers — rent, fuel, utilities, repairs.
    // Supplier credit notes — the claim a late return creates, settled.
    // Same read/write split as payments, and the same reasoning: a credit
    // reduces what leaves the bank, so recording one is a financial decision.
    Route::middleware('can:purchasing.reports.view')->group(function () {
        Route::get('credit-notes', [SupplierCreditNoteController::class, 'index'])->name('credit-notes.index');
        Route::get('credit-notes/{creditNote}', [SupplierCreditNoteController::class, 'show'])
            ->whereNumber('creditNote')->name('credit-notes.show');
    });

    Route::middleware('can:purchasing.credit_note.record')->group(function () {
        Route::get('credit-notes/new', [SupplierCreditNoteController::class, 'create'])->name('credit-notes.create');
        Route::post('credit-notes', [SupplierCreditNoteController::class, 'store'])->name('credit-notes.store');
    });

    // ⚠ Same read/write split as payments: anyone in the business may READ what
    // the business spent, because spending nobody can see is spending nobody can
    // question. Recording one moves money and needs the permission.
    Route::middleware('can:purchasing.reports.view')->group(function () {
        Route::get('expenses', [ExpenseVoucherController::class, 'index'])->name('expenses.index');
    });

    Route::middleware('can:purchasing.expense.record')->group(function () {
        Route::post('expenses', [ExpenseVoucherController::class, 'store'])->name('expenses.store');
    });
});
