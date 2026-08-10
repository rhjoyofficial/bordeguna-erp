<?php

use App\Modules\Purchasing\Http\Controllers\ExpenseVoucherController;
use App\Modules\Purchasing\Http\Controllers\PurchaseOrderController;
use App\Modules\Purchasing\Http\Controllers\SupplierController;
use App\Modules\Purchasing\Http\Controllers\SupplierCreditNoteController;
use App\Modules\Purchasing\Http\Controllers\SupplierImportController;
use App\Modules\Purchasing\Http\Controllers\SupplierPaymentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'business'])->prefix('purchasing')->name('purchasing.')->group(function () {
    // Suppliers: anyone in the business can view; only purchasing.suppliers.manage may mutate.
    Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');

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
    Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index');
    Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->name('purchase-orders.show');
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
    Route::get('payments', [SupplierPaymentController::class, 'index'])->name('payments.index');
    Route::get('payments/{payment}', [SupplierPaymentController::class, 'show'])
        ->whereNumber('payment')->name('payments.show');

    Route::middleware('can:purchasing.payment.record')->group(function () {
        // ⚠ Declared BEFORE the {payment} route would otherwise swallow it.
        // `whereNumber` above already prevents that, and both defences are kept
        // because losing either one silently turns /payments/new into a 404.
        Route::get('payments/new', [SupplierPaymentController::class, 'create'])->name('payments.create');
        Route::post('payments', [SupplierPaymentController::class, 'store'])->name('payments.store');
    });

    // Expense vouchers — rent, fuel, utilities, repairs.
    // Supplier credit notes — the claim a late return creates, settled.
    // Same read/write split as payments, and the same reasoning: a credit
    // reduces what leaves the bank, so recording one is a financial decision.
    Route::get('credit-notes', [SupplierCreditNoteController::class, 'index'])->name('credit-notes.index');
    Route::get('credit-notes/{creditNote}', [SupplierCreditNoteController::class, 'show'])
        ->whereNumber('creditNote')->name('credit-notes.show');

    Route::middleware('can:purchasing.credit_note.record')->group(function () {
        Route::get('credit-notes/new', [SupplierCreditNoteController::class, 'create'])->name('credit-notes.create');
        Route::post('credit-notes', [SupplierCreditNoteController::class, 'store'])->name('credit-notes.store');
    });

    // ⚠ Same read/write split as payments: anyone in the business may READ what
    // the business spent, because spending nobody can see is spending nobody can
    // question. Recording one moves money and needs the permission.
    Route::get('expenses', [ExpenseVoucherController::class, 'index'])->name('expenses.index');

    Route::middleware('can:purchasing.expense.record')->group(function () {
        Route::post('expenses', [ExpenseVoucherController::class, 'store'])->name('expenses.store');
    });
});
