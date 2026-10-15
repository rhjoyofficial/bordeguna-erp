<?php

use App\Modules\Purchasing\Http\Controllers\ExpenseImportController;
use App\Modules\Purchasing\Http\Controllers\ExpenseVoucherController;
use App\Modules\Purchasing\Http\Controllers\PurchaseOrderController;
use App\Modules\Purchasing\Http\Controllers\PurchasesOverviewController;
use App\Modules\Purchasing\Http\Controllers\RecurringExpenseController;
use App\Modules\Purchasing\Http\Controllers\SupplierBillController;
use App\Modules\Purchasing\Http\Controllers\SupplierCertificationController;
use App\Modules\Purchasing\Http\Controllers\SupplierController;
use App\Modules\Purchasing\Http\Controllers\SupplierCreditNoteController;
use App\Modules\Purchasing\Http\Controllers\SupplierDocumentController;
use App\Modules\Purchasing\Http\Controllers\SupplierImportController;
use App\Modules\Purchasing\Http\Controllers\SupplierPaymentController;
use App\Modules\Purchasing\Http\Controllers\SupplierQuickCreateController;
use App\Modules\Purchasing\Http\Controllers\WithholdingRateController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'business'])->prefix('purchasing')->name('purchasing.')->group(function () {
    // ⚠ Reading purchasing WAS open to the whole business ("a bill nobody can
    // see is a bill nobody can question") - written before the zero-permission
    // Self-service role existed (24 Aug 2026). "The business" now includes
    // logins meant to reach only their own profile, so every read sits behind
    // purchasing.reports.view, which every operational role already holds.
    // The write gates below are unchanged.
    Route::middleware('can:purchasing.reports.view')->group(function () {
        // ⭐ The module's front page (Purchases redesign, 15 Oct 2026): the
        // chain, what needs somebody, what is owed, spent and coming up. Same
        // read gate as every list it links to; the payables card inside it
        // needs accounting.view and is absent without it.
        Route::get('overview', [PurchasesOverviewController::class, 'index'])->name('overview');

        Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
        // C-360: the supplier profile - deliberately WITHOUT bank details,
        // which stay behind suppliers.manage. ⚠ whereNumber, because
        // suppliers/template and suppliers/export follow below.
        Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])
            ->whereNumber('supplier')->name('suppliers.show');
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
        // ⭐ 27 Sep 2026 (Joy): create a supplier from inside a purchase order or
        // any supplier picker (JSON, name is enough, code auto-generated).
        Route::post('suppliers/quick', [SupplierQuickCreateController::class, 'store'])->name('suppliers.quick');
        Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
        Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])->name('suppliers.destroy');
        // Finance decision C4 (29 Sep 2026): TDS / VDS rates, dated, per supplier type.
        Route::get('withholding-rates', [WithholdingRateController::class, 'index'])->name('withholding-rates.index');
        Route::post('withholding-rates', [WithholdingRateController::class, 'store'])->name('withholding-rates.store');
        Route::put('withholding-rates/{rate}', [WithholdingRateController::class, 'update'])->name('withholding-rates.update');
        Route::delete('withholding-rates/{rate}', [WithholdingRateController::class, 'destroy'])->name('withholding-rates.destroy');
        // ⭐ Plan D11: trade licence and NID scans. Adding needs the same trust as
        // editing the supplier; opening or removing an NID also needs
        // purchasing.suppliers.identity.view (IdentityDocuments and the stream).
        Route::post('suppliers/{supplier}/documents', [SupplierDocumentController::class, 'store'])
            ->whereNumber('supplier')->name('suppliers.documents.store');
        Route::delete('suppliers/{supplier}/documents/{attachment}', [SupplierDocumentController::class, 'destroy'])
            ->whereNumber('supplier')->whereNumber('attachment')->name('suppliers.documents.destroy');

        // ⭐ Joy's answer 18: certifications, editable from the supplier screen.
        // ⚠ scopeBindings: a certification is found only UNDER its own supplier.
        Route::post('suppliers/{supplier}/certifications', [SupplierCertificationController::class, 'store'])
            ->whereNumber('supplier')->name('suppliers.certifications.store');
        Route::scopeBindings()->group(function () {
            Route::post('suppliers/{supplier}/certifications/{certification}', [SupplierCertificationController::class, 'update'])
                ->whereNumber(['supplier', 'certification'])->name('suppliers.certifications.update');
            Route::delete('suppliers/{supplier}/certifications/{certification}', [SupplierCertificationController::class, 'destroy'])
                ->whereNumber(['supplier', 'certification'])->name('suppliers.certifications.destroy');
            Route::delete('suppliers/{supplier}/certifications/{certification}/scans/{attachment}', [SupplierCertificationController::class, 'destroyScan'])
                ->whereNumber(['supplier', 'certification', 'attachment'])->name('suppliers.certifications.scans.destroy');
        });
    });

    // Purchase orders — index/show open to any business member; store/approve/
    // reject/cancel/receive each authorize internally.
    Route::middleware('can:purchasing.reports.view')->group(function () {
        Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index');
        Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->name('purchase-orders.show');
        // The copy the supplier receives. Same permission as reading the order.
        Route::get('purchase-orders/{purchaseOrder}/print', [PurchaseOrderController::class, 'print'])->name('purchase-orders.print');
    });
    Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchase-orders.store');
    Route::post('purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve'])->name('purchase-orders.approve');
    Route::post('purchase-orders/{purchaseOrder}/reject', [PurchaseOrderController::class, 'reject'])->name('purchase-orders.reject');
    Route::post('purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel');
    // ⚠⚠ No `payment-status` route any more (21 Sep 2026). It set a hand-typed
    // "Paid" on the order that moved no money and reached no ledger, so the
    // order could say Paid while Accounts Payable owed the supplier in full.
    // Paying is a supplier payment against a bill; the order page reads it.
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
        // ⚠ PUR-003 (owner decision 14 Oct 2026): correction is a reversal with
        // a reason, never an edit and never a delete, gated like recording.
        Route::post('payments/{payment}/reverse', [SupplierPaymentController::class, 'reverse'])
            ->whereNumber('payment')->name('payments.reverse');
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
        // PUR-003 (14 Oct 2026): reversed with a reason, as a payment is.
        Route::post('credit-notes/{creditNote}/reverse', [SupplierCreditNoteController::class, 'reverse'])
            ->whereNumber('creditNote')->name('credit-notes.reverse');
    });

    // ⚠ Same read/write split as payments: anyone in the business may READ what
    // the business spent, because spending nobody can see is spending nobody can
    // question. Recording one moves money and needs the permission.
    Route::middleware('can:purchasing.reports.view')->group(function () {
        Route::get('expenses', [ExpenseVoucherController::class, 'index'])->name('expenses.index');
        // ⚠ Printing sits with viewing, not recording: a voucher on paper is no
        // more secret than the voucher on the screen it was read from.
        Route::get('expenses/export', [ExpenseVoucherController::class, 'export'])->name('expenses.export');
        Route::get('expenses/{voucher}/print', [ExpenseVoucherController::class, 'print'])->name('expenses.print');

        // Standing costs. Reading what the business has committed to is the same
        // right as reading what it spent.
        Route::get('recurring-expenses', [RecurringExpenseController::class, 'index'])->name('recurring-expenses.index');
    });

    Route::middleware('can:purchasing.expense.record')->group(function () {
        Route::post('expenses', [ExpenseVoucherController::class, 'store'])->name('expenses.store');
        // PUR-003 (14 Oct 2026): reversed with a reason, as a payment is.
        Route::post('expenses/{voucher}/reverse', [ExpenseVoucherController::class, 'reverse'])
            ->whereNumber('voucher')->name('expenses.reverse');

        // The importer. Gated with RECORDING rather than with viewing, because
        // a file of thirty rows is thirty payments - the same act as pressing
        // the button thirty times, and the same permission.
        Route::get('expenses/import', [ExpenseImportController::class, 'create'])->name('expenses.import');
        Route::get('expenses/import/template', [ExpenseImportController::class, 'template'])->name('expenses.import.template');
        // ⚠ A preview is a POST's answer. Refreshing it, or a refused Apply
        // sending the browser "back", asks for this URL with GET: land on the
        // import page with any message kept, never on a 405 (29 Sep 2026).
        Route::get('expenses/import/preview', fn () => tap(redirect()->route('purchasing.expenses.import'), fn () => session()->reflash()))->name('expenses.import.preview.back');
        Route::post('expenses/import/preview', [ExpenseImportController::class, 'preview'])->name('expenses.import.preview');
        Route::post('expenses/import', [ExpenseImportController::class, 'store'])->name('expenses.import.store');
        Route::post('expenses/{voucher}/evidence', [ExpenseVoucherController::class, 'uploadEvidence'])->name('expenses.evidence.store');
        Route::delete('expenses/{voucher}/evidence/{attachment}', [ExpenseVoucherController::class, 'deleteEvidence'])->name('expenses.evidence.destroy');

        // ⚠ Committing the business to a monthly cost, and deciding a month was
        // not taken, are both decisions with money behind them - the same
        // permission that records the payment.
        Route::post('recurring-expenses', [RecurringExpenseController::class, 'store'])->name('recurring-expenses.store');
        Route::patch('recurring-expenses/{recurringExpense}', [RecurringExpenseController::class, 'update'])->name('recurring-expenses.update');
        Route::post('recurring-expenses/{recurringExpense}/status', [RecurringExpenseController::class, 'status'])->name('recurring-expenses.status');
        Route::post('recurring-occurrences/{occurrence}/skip', [RecurringExpenseController::class, 'skip'])->name('recurring-occurrences.skip');
    });
});
