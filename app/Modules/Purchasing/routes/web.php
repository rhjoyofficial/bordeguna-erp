<?php

use App\Modules\Purchasing\Http\Controllers\ExpenseImportController;
use App\Modules\Purchasing\Http\Controllers\ExpenseVoucherController;
use App\Modules\Purchasing\Http\Controllers\GoodsReceiptController;
use App\Modules\Purchasing\Http\Controllers\LandedCostController;
use App\Modules\Purchasing\Http\Controllers\OutstandingOrdersController;
use App\Modules\Purchasing\Http\Controllers\PurchaseApprovalController;
use App\Modules\Purchasing\Http\Controllers\PurchaseDocumentFileController;
use App\Modules\Purchasing\Http\Controllers\PurchaseOrderController;
use App\Modules\Purchasing\Http\Controllers\PurchaseReturnController;
use App\Modules\Purchasing\Http\Controllers\PurchasesOverviewController;
use App\Modules\Purchasing\Http\Controllers\QuickPurchaseController;
use App\Modules\Purchasing\Http\Controllers\RecurringExpenseController;
use App\Modules\Purchasing\Http\Controllers\SupplierAdvanceController;
use App\Modules\Purchasing\Http\Controllers\SupplierBillController;
use App\Modules\Purchasing\Http\Controllers\SupplierCertificationController;
use App\Modules\Purchasing\Http\Controllers\SupplierController;
use App\Modules\Purchasing\Http\Controllers\SupplierCorrectionController;
use App\Modules\Purchasing\Http\Controllers\SupplierCreditNoteController;
use App\Modules\Purchasing\Http\Controllers\SupplierDocumentController;
use App\Modules\Purchasing\Http\Controllers\SupplierImportController;
use App\Modules\Purchasing\Http\Controllers\SupplierPaymentController;
use App\Modules\Purchasing\Http\Controllers\SupplierQuickCreateController;
use App\Modules\Purchasing\Http\Controllers\SupplierRefundController;
use App\Modules\Purchasing\Http\Controllers\SupplierTypeController;
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
        // ⭐ Ruling P6: the credit warning the purchase order form asks for.
        Route::get('suppliers/{supplier}/credit', [SupplierController::class, 'credit'])
            ->whereNumber('supplier')->name('suppliers.credit');
        // ⭐ Initial plan B1 (R56): open advances for "Allocate advance".
        Route::get('suppliers/{supplier}/open-advances', [SupplierController::class, 'openAdvances'])
            ->middleware('can:purchasing.advance.record')->whereNumber('supplier')->name('suppliers.open-advances');
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
        // ⭐ B13 (24 Oct 2026): Reactivate on screen; Delete to the bin when never used.
        Route::post('suppliers/{supplier}/reactivate', [SupplierController::class, 'reactivate'])->whereNumber('supplier')->name('suppliers.reactivate');
        Route::post('suppliers/{supplier}/delete', [SupplierController::class, 'bin'])->whereNumber('supplier')->name('suppliers.bin');
        // Finance decision C4 (29 Sep 2026): TDS / VDS rates, dated, per supplier type.
        Route::get('withholding-rates', [WithholdingRateController::class, 'index'])->name('withholding-rates.index');
        Route::post('withholding-rates', [WithholdingRateController::class, 'store'])->name('withholding-rates.store');
        Route::put('withholding-rates/{rate}', [WithholdingRateController::class, 'update'])->name('withholding-rates.update');
        Route::delete('withholding-rates/{rate}', [WithholdingRateController::class, 'destroy'])->name('withholding-rates.destroy');
        // ⭐ 17 Oct 2026 (Joy): supplier types are a list each business keeps,
        // beside the tax rates they pick. A used type is switched off, never deleted.
        Route::get('supplier-types', [SupplierTypeController::class, 'index'])->name('supplier-types.index');
        Route::post('supplier-types', [SupplierTypeController::class, 'store'])->name('supplier-types.store');
        Route::put('supplier-types/{supplierType}', [SupplierTypeController::class, 'update'])
            ->whereNumber('supplierType')->name('supplier-types.update');
        Route::post('supplier-types/{supplierType}/deactivate', [SupplierTypeController::class, 'deactivate'])
            ->whereNumber('supplierType')->name('supplier-types.deactivate');
        Route::post('supplier-types/{supplierType}/reactivate', [SupplierTypeController::class, 'reactivate'])
            ->whereNumber('supplierType')->name('supplier-types.reactivate');
        Route::delete('supplier-types/{supplierType}', [SupplierTypeController::class, 'destroy'])
            ->whereNumber('supplierType')->name('supplier-types.destroy');
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
    // ⭐ Purchase redesign PR1 (18 Oct 2026): the full New purchase order
    // page and its edit. purchasing.po.create, the gate store already had.
    // ⚠ `new` is declared before `{purchaseOrder}`, and the parameter is
    // numeric, so neither can swallow the other.
    Route::middleware('can:purchasing.po.create')->group(function () {
        Route::get('purchase-orders/new', [PurchaseOrderController::class, 'create'])->name('purchase-orders.create');
        Route::get('purchase-orders/{purchaseOrder}/edit', [PurchaseOrderController::class, 'edit'])
            ->whereNumber('purchaseOrder')->name('purchase-orders.edit');
        Route::put('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])
            ->whereNumber('purchaseOrder')->name('purchase-orders.update');
        Route::post('purchase-orders/{purchaseOrder}/attachments', [PurchaseOrderController::class, 'storeAttachment'])
            ->whereNumber('purchaseOrder')->name('purchase-orders.attachments.store');
        Route::delete('purchase-orders/{purchaseOrder}/attachments/{attachment}', [PurchaseOrderController::class, 'destroyAttachment'])
            ->whereNumber(['purchaseOrder', 'attachment'])->name('purchase-orders.attachments.destroy');
    });
    // ⭐ Initial plan B1 (R54): a supplier's later charge, from the order's delivery.
    Route::post('purchase-orders/{purchaseOrder}/later-charges', [PurchaseOrderController::class, 'storeLaterCharge'])
        ->middleware('can:purchasing.landed_cost.manage')->whereNumber('purchaseOrder')->name('purchase-orders.later-charges.store');
    Route::middleware('can:purchasing.reports.view')->group(function () {
        Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index');
        Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])
            ->whereNumber('purchaseOrder')->name('purchase-orders.show');
        // The copy the supplier receives. Same permission as reading the order.
        Route::get('purchase-orders/{purchaseOrder}/print', [PurchaseOrderController::class, 'print'])->name('purchase-orders.print');
    });
    Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchase-orders.store');
    Route::post('purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve'])->name('purchase-orders.approve');
    Route::post('purchase-orders/{purchaseOrder}/reject', [PurchaseOrderController::class, 'reject'])->name('purchase-orders.reject');
    Route::post('purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel');
    // ⭐ Corrections wave 1 (19 Oct 2026): Withdraw a waiting order, Fix
    // details after receipt; a goods receipt's Fix batch details and its
    // Correct page (Reverse and Correct post through /records/...).
    Route::post('purchase-orders/{purchaseOrder}/withdraw', [PurchaseOrderController::class, 'withdraw'])
        ->whereNumber('purchaseOrder')->name('purchase-orders.withdraw');
    Route::post('purchase-orders/{purchaseOrder}/fix-details', [PurchaseOrderController::class, 'fixDetails'])
        ->whereNumber('purchaseOrder')->name('purchase-orders.fix-details');
    // ⭐ Initial plan B1 (R58): files on a goods receipt and on a supplier bill.
    Route::middleware('can:purchasing.receipt.record')->group(function () {
        Route::post('receipts/{goodsReceipt}/files', [PurchaseDocumentFileController::class, 'storeForReceipt'])
            ->whereNumber('goodsReceipt')->name('receipts.files.store');
        Route::delete('receipts/{goodsReceipt}/files/{attachment}', [PurchaseDocumentFileController::class, 'destroyForReceipt'])
            ->whereNumber(['goodsReceipt', 'attachment'])->name('receipts.files.destroy');
    });
    Route::middleware('can:purchasing.bill.record')->group(function () {
        Route::post('bills/{bill}/files', [PurchaseDocumentFileController::class, 'storeForBill'])
            ->whereNumber('bill')->name('bills.files.store');
        Route::delete('bills/{bill}/files/{attachment}', [PurchaseDocumentFileController::class, 'destroyForBill'])
            ->whereNumber(['bill', 'attachment'])->name('bills.files.destroy');
    });
    Route::post('receipts/{goodsReceipt}/fix-batch-details', [GoodsReceiptController::class, 'fixBatchDetails'])
        ->whereNumber('goodsReceipt')->name('receipts.fix-batch-details');
    Route::get('receipts/{goodsReceipt}/correct', [GoodsReceiptController::class, 'correct'])
        ->whereNumber('goodsReceipt')->name('receipts.correct');
    // ⚠⚠ No `payment-status` route any more (21 Sep 2026). It set a hand-typed
    // "Paid" on the order that moved no money and reached no ledger, so the
    // order could say Paid while Accounts Payable owed the supplier in full.
    // Paying is a supplier payment against a bill; the order page reads it.
    Route::post('purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive'])->name('purchase-orders.receive');
    Route::post('purchase-orders/{purchaseOrder}/returns', [PurchaseOrderController::class, 'returnGoods'])->name('purchase-orders.returns.store');

    // ⭐ Receipt Notes, purchase returns and outstanding orders (purchase
    // redesign PR2, 18 Oct 2026; Tally's Receipt Note, Rejection Out and
    // Purchase Order Outstanding). Reads only, behind the same read gate as
    // the orders they come from; recording stays on the order.
    Route::middleware('can:purchasing.reports.view')->group(function () {
        Route::get('receipts', [GoodsReceiptController::class, 'index'])->name('receipts.index');
        Route::get('receipts/{goodsReceipt}', [GoodsReceiptController::class, 'show'])
            ->whereNumber('goodsReceipt')->name('receipts.show');
        Route::get('returns', [PurchaseReturnController::class, 'index'])->name('returns.index');
        Route::get('returns/{purchaseReturn}', [PurchaseReturnController::class, 'show'])
            ->whereNumber('purchaseReturn')->name('returns.show');
        Route::get('outstanding-orders', [OutstandingOrdersController::class, 'index'])->name('outstanding-orders.index');
    });

    // ⭐ Buy and receive (supplier finance wave C, ruling P4, 18 Oct 2026):
    // order, receipt, extra costs, bill and payment from one screen. The
    // screen needs `purchasing.quick.purchase`; RecordQuickPurchase then needs
    // each step's own permission. The result page is read like the documents
    // it links. ⚠ `buy-and-receive/new` is declared before `{quickPurchase}`
    // and the parameter is numeric, so neither can swallow the other.
    Route::middleware('can:purchasing.quick.purchase')->group(function () {
        Route::get('buy-and-receive/new', [QuickPurchaseController::class, 'create'])->name('buy-and-receive.create');
        Route::post('buy-and-receive', [QuickPurchaseController::class, 'store'])->name('buy-and-receive.store');
    });
    Route::middleware('can:purchasing.reports.view')->group(function () {
        Route::get('buy-and-receive/{quickPurchase}', [QuickPurchaseController::class, 'show'])
            ->whereNumber('quickPurchase')->name('buy-and-receive.show');
    });

    // ⭐ Landed costs (Phase 1, 17 Oct 2026): costs beside or after a goods
    // receipt. Reading, writing a draft, and approving or posting are three
    // permissions, because the third moves stock values and the ledger.
    Route::middleware('can:purchasing.landed_cost.view')->group(function () {
        Route::get('landed-costs', [LandedCostController::class, 'index'])->name('landed-costs.index');
        Route::get('landed-costs/expense-check/{goodsReceipt}', [LandedCostController::class, 'expenseCheck'])
            ->whereNumber('goodsReceipt')->name('landed-costs.expense-check');
        Route::get('landed-costs/{landedCost}/preview', [LandedCostController::class, 'preview'])
            ->whereNumber('landedCost')->name('landed-costs.preview');
    });
    Route::middleware('can:purchasing.landed_cost.manage')->group(function () {
        Route::post('landed-costs', [LandedCostController::class, 'store'])->name('landed-costs.store');
        Route::put('landed-costs/{landedCost}', [LandedCostController::class, 'update'])
            ->whereNumber('landedCost')->name('landed-costs.update');
        Route::delete('landed-costs/{landedCost}', [LandedCostController::class, 'destroy'])
            ->whereNumber('landedCost')->name('landed-costs.destroy');
        Route::post('landed-costs/{landedCost}/submit', [LandedCostController::class, 'submit'])
            ->whereNumber('landedCost')->name('landed-costs.submit');
    });
    Route::middleware('can:purchasing.landed_cost.post')->group(function () {
        Route::post('landed-costs/{landedCost}/approve', [LandedCostController::class, 'approve'])
            ->whereNumber('landedCost')->name('landed-costs.approve');
        Route::post('landed-costs/{landedCost}/reject', [LandedCostController::class, 'reject'])
            ->whereNumber('landedCost')->name('landed-costs.reject');
        Route::post('landed-costs/{landedCost}/post', [LandedCostController::class, 'post'])
            ->whereNumber('landedCost')->name('landed-costs.post');
    });

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
        // ⚠ 24 Oct 2026 (wave 1 gaps): the old payments/{payment}/reverse route
        // is retired. It bypassed the corrections engine (no preview, no Tk
        // 20,000 second person, no closed-month rule) and defaulted to today.
        // A payment is reversed or corrected only through /records/
        // purchasing.supplier_payment/{id}/..., which lands on its own date.
    });

    // ⭐ Supplier advances (ruling P2, 18 Oct 2026): paid before the bill,
    // applied to bills, refunded. Read like payments; written by whoever may
    // pay a supplier (`purchasing.advance.record`, granted by the migration to
    // the roles that hold `purchasing.payment.record`).
    Route::middleware('can:purchasing.reports.view')->group(function () {
        Route::get('advances', [SupplierAdvanceController::class, 'index'])->name('advances.index');
        Route::get('advances/{advance}', [SupplierAdvanceController::class, 'show'])
            ->whereNumber('advance')->name('advances.show');
        Route::get('suppliers/{supplier}/advances', [SupplierAdvanceController::class, 'supplier'])
            ->whereNumber('supplier')->name('advances.supplier');
    });

    Route::middleware('can:purchasing.advance.record')->group(function () {
        Route::get('advances/new', [SupplierAdvanceController::class, 'create'])->name('advances.create');
        Route::post('advances', [SupplierAdvanceController::class, 'store'])->name('advances.store');
        Route::post('advances/{advance}/reverse', [SupplierAdvanceController::class, 'reverse'])
            ->whereNumber('advance')->name('advances.reverse');
        Route::post('bills/{bill}/advances', [SupplierAdvanceController::class, 'apply'])
            ->whereNumber('bill')->name('advances.apply');
        Route::post('advance-applications/{application}/reverse', [SupplierAdvanceController::class, 'reverseApplication'])
            ->whereNumber('application')->name('advances.applications.reverse');
        Route::post('advance-refunds', [SupplierAdvanceController::class, 'refund'])->name('advances.refunds.store');
        Route::post('advance-refunds/{refund}/reverse', [SupplierAdvanceController::class, 'reverseRefund'])
            ->whereNumber('refund')->name('advances.refunds.reverse');
        // ⭐ Ruling P5 (19 Oct 2026): the supplier pays back a debit balance on
        // Accounts Payable (they owe us), beside the advance refunds.
        Route::post('supplier-refunds', [SupplierRefundController::class, 'store'])->name('supplier-refunds.store');
        Route::post('supplier-refunds/{refund}/reverse', [SupplierRefundController::class, 'reverse'])
            ->whereNumber('refund')->name('supplier-refunds.reverse');
    });

    // ⭐ Purchasing approvals (ruling P7, Joy 18 Oct 2026): bills, payments,
    // advances and refunds waiting for a second person. Read by anyone who
    // reads purchasing; deciding is gated in PurchasingApprovalGate by the
    // Finance policy's approver roles and never the maker, so the POSTs carry
    // no permission of their own beyond reading.
    Route::middleware('can:purchasing.reports.view')->group(function () {
        Route::get('approvals', [PurchaseApprovalController::class, 'index'])->name('approvals.index');
        Route::get('approvals/{approval}', [PurchaseApprovalController::class, 'show'])
            ->whereNumber('approval')->name('approvals.show');
        Route::post('approvals/{approval}/approve', [PurchaseApprovalController::class, 'approve'])
            ->whereNumber('approval')->name('approvals.approve');
        Route::post('approvals/{approval}/reject', [PurchaseApprovalController::class, 'reject'])
            ->whereNumber('approval')->name('approvals.reject');
    });

    // Supplier bills - the document Accounts Payable is a ledger of, and the
    // step that lets GRNI clear. Same read/write split as payments and credit
    // notes: anyone in the business may READ what the business has been billed,
    // because a bill nobody can see is a bill nobody can question.
    Route::middleware('can:purchasing.reports.view')->group(function () {
        Route::get('bills', [SupplierBillController::class, 'index'])->name('bills.index');
        // ⭐ Ruling P6: bills overdue and falling due soon, by due date.
        Route::get('bills-due', [SupplierBillController::class, 'due'])->name('bills.due');
        Route::get('bills/{bill}', [SupplierBillController::class, 'show'])
            ->whereNumber('bill')->name('bills.show');
    });

    Route::middleware('can:purchasing.bill.record')->group(function () {
        // ⚠ D-SB-1: the form is always opened against ONE purchase order, which
        // is why the route carries it rather than offering a blank bill.
        Route::get('bills/new/{purchaseOrder}', [SupplierBillController::class, 'create'])
            ->whereNumber('purchaseOrder')->name('bills.create');
        // ⭐ Ruling P5 (19 Oct 2026): a bill for a supplier, across their
        // deliveries and orders, with services and carriage (?supplier=ID).
        Route::get('bills/new', [SupplierBillController::class, 'createForSupplier'])->name('bills.create-for-supplier');
        Route::post('bills', [SupplierBillController::class, 'store'])->name('bills.store');
        // ⚠ D-SB-6: correction is a reversal, never an edit and never a delete,
        // so there is deliberately no PUT and no DELETE on a bill.
        // ⚠ 24 Oct 2026 (wave 1 gaps): the old bills/{bill}/reverse route is
        // retired: it bypassed the corrections engine and dated the reversal
        // today. Bills are reversed and corrected only through /records/
        // purchasing.supplier_bill/{id}/..., on the bill's own date.
        // ⭐ Ruling P6: a recorded bill's due date changes only here, with a
        // reason, and the change is in the bill's history. No journal moves.
        Route::post('bills/{bill}/due-date', [SupplierBillController::class, 'changeDueDate'])
            ->whereNumber('bill')->name('bills.due-date');
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
        // ⚠ 24 Oct 2026 (wave 1 gaps): the old credit-notes/{creditNote}/reverse
        // route is retired for the same reason as the payment's: credit notes
        // are reversed and corrected only through /records/
        // purchasing.supplier_credit_note/{id}/..., on their own date.
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

    // ⭐ Corrections wave 1, B2 (20 Oct 2026): the Correct forms of bills,
    // payments and credit notes (nothing is saved here: the action dialog
    // posts to the generic /records/{type}/{id}/correct route), and a bill's
    // Fix details. Who may act is the corrections policy's question, asked on
    // every page and every post.
    Route::middleware('can:purchasing.reports.view')->group(function () {
        Route::get('bills/{bill}/correct', [SupplierCorrectionController::class, 'correctBill'])
            ->whereNumber('bill')->name('bills.correct');
        Route::post('bills/{bill}/fix-details', [SupplierCorrectionController::class, 'fixBillDetails'])
            ->whereNumber('bill')->name('bills.fix-details');
        Route::get('payments/{payment}/correct', [SupplierCorrectionController::class, 'correctPayment'])
            ->whereNumber('payment')->name('payments.correct');
        Route::get('credit-notes/{creditNote}/correct', [SupplierCorrectionController::class, 'correctCreditNote'])
            ->whereNumber('creditNote')->name('credit-notes.correct');
    });
});
