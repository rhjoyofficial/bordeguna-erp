<?php

use App\Modules\Commerce\Http\Controllers\GatewayRefundController;
use App\Modules\Sales\Couriers\Http\Controllers\CourierBulkBookingController;
use App\Modules\Sales\Couriers\Http\Controllers\CourierFeesController;
use App\Modules\Sales\Couriers\Http\Controllers\CourierLocationsController;
use App\Modules\Sales\Couriers\Http\Controllers\CourierParcelController;
use App\Modules\Sales\Couriers\Http\Controllers\CourierRateCardController;
use App\Modules\Sales\Couriers\Http\Controllers\CourierSettingsController;
use App\Modules\Sales\Couriers\Http\Controllers\CourierWebhookController;
use App\Modules\Sales\Http\Controllers\ConsignmentController;
use App\Modules\Sales\Http\Controllers\CounterSaleController;
use App\Modules\Sales\Http\Controllers\CourierStatementController;
use App\Modules\Sales\Http\Controllers\CreditTermsController;
use App\Modules\Sales\Http\Controllers\CustomerController;
use App\Modules\Sales\Http\Controllers\CustomerDocumentController;
use App\Modules\Sales\Http\Controllers\CustomerImportController;
use App\Modules\Sales\Http\Controllers\CustomerQuickCreateController;
use App\Modules\Sales\Http\Controllers\DeliveryController;
use App\Modules\Sales\Http\Controllers\DiscountController;
use App\Modules\Sales\Http\Controllers\MarketPriceController;
use App\Modules\Sales\Http\Controllers\OwnerCustomerController;
use App\Modules\Sales\Http\Controllers\PosShiftController;
use App\Modules\Sales\Http\Controllers\PriceGroupController;
use App\Modules\Sales\Http\Controllers\PriceImportController;
use App\Modules\Sales\Http\Controllers\ProductGroupPriceController;
use App\Modules\Sales\Http\Controllers\ProductPriceController;
use App\Modules\Sales\Http\Controllers\PromotionController;
use App\Modules\Sales\Http\Controllers\QuotationController;
use App\Modules\Sales\Http\Controllers\ReceiptController;
use App\Modules\Sales\Http\Controllers\RetailSellerController;
use App\Modules\Sales\Http\Controllers\SalesCorrectionController;
use App\Modules\Sales\Http\Controllers\SalesInvoiceController;
use App\Modules\Sales\Http\Controllers\SalesOrderController;
use App\Modules\Sales\Http\Controllers\SalesOverviewController;
use App\Modules\Sales\Http\Controllers\SalesReturnController;
use App\Modules\Sales\Http\Controllers\SalesTargetController;
use App\Modules\Sales\Http\Controllers\ShopOrderImportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'business'])->prefix('sales')->name('sales.')->group(function () {
    // ⭐ The module's front page (redesign, 15 Oct 2026). `sales.overview` is
    // a gate (SalesServiceProvider): any of sales.reports.view, sales.sell,
    // sales.orders.record. The page then sends each card only with the
    // permission its widget checks.
    Route::get('overview', [SalesOverviewController::class, 'index'])
        ->middleware('can:sales.overview')->name('overview');

    // Reading is open to any business member who may view sales; changing
    // anything is gated per operation, the same split Purchasing uses.
    Route::middleware('can:sales.reports.view')->group(function () {
        Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('credit-terms', [CreditTermsController::class, 'index'])->name('credit-terms.index');
        Route::get('credit-terms/{creditTerms}', [CreditTermsController::class, 'show'])->whereNumber('creditTerms')->name('credit-terms.show');
        // C-360: the customer profile. ⚠ whereNumber, because
        // customers/template and customers/export are real routes below that a
        // bare {customer} would swallow.
        Route::get('customers/{customer}', [CustomerController::class, 'show'])
            ->whereNumber('customer')->name('customers.show');
        Route::get('prices', [ProductPriceController::class, 'index'])->name('prices.index');
        Route::get('invoices', [SalesInvoiceController::class, 'index'])->name('invoices.index');
        Route::get('deliveries', [DeliveryController::class, 'index'])->name('deliveries.index');
    });

    // ⭐ 26 Sep 2026 (Q21): read sales, or record orders and see your own.
    Route::middleware('can:sales.orders.see')->group(function () {
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
        // Quotations: a promise of a price. Same trust as taking the order
        // that accepting one creates.
        Route::get('quotations', [QuotationController::class, 'index'])->name('quotations.index');
        Route::post('quotations', [QuotationController::class, 'store'])->name('quotations.store');
        Route::post('quotations/{quotation}/send', [QuotationController::class, 'send'])->name('quotations.send');
        Route::post('quotations/{quotation}/accept', [QuotationController::class, 'accept'])->name('quotations.accept');
        Route::post('quotations/{quotation}/reject', [QuotationController::class, 'reject'])->name('quotations.reject');
        Route::get('quotations/{quotation}/print', [QuotationController::class, 'print'])->name('quotations.print');
        Route::post('orders', [SalesOrderController::class, 'store'])->name('orders.store');
        // ⭐ Corrections wave 1: Edit an order (the controller asks who, by stage).
        Route::put('orders/{order}', [SalesOrderController::class, 'update'])->whereNumber('order')->name('orders.update');
        // C-SO-3: exposure is SHOWN at order time, enforced by nobody.
        Route::post('orders/exposure', [SalesOrderController::class, 'exposure'])->name('orders.exposure');
        // The order form's price per line, as it is typed (21 Sep 2026). Reads
        // and writes nothing, so a GET, like the counter's price check.
        Route::get('orders/price', [SalesOrderController::class, 'price'])->name('orders.price');

        // ⭐ Shop orders by import sheet (Joy, 11 Oct 2026): order-book data
        // entry in bulk, the same trust as taking one order. A changed price,
        // an advance and auto-approval each check their own permission.
        Route::get('orders/import', [ShopOrderImportController::class, 'index'])->name('orders.import');
        Route::get('orders/import/template', [ShopOrderImportController::class, 'template'])->name('orders.import.template');
        Route::get('orders/import/preview', fn () => tap(redirect()->route('sales.orders.import'), fn () => session()->reflash()))->name('orders.import.preview.back');
        Route::post('orders/import/preview', [ShopOrderImportController::class, 'preview'])->name('orders.import.preview');
        Route::post('orders/import', [ShopOrderImportController::class, 'store'])->name('orders.import.store');
        Route::get('orders/imports/{import}', [ShopOrderImportController::class, 'show'])->whereNumber('import')->name('orders.imports.show');
    });

    // ⚠ C-SO-1: goods leave against an APPROVED order only. Owner and Manager.
    Route::middleware('can:sales.credit.approve')->group(function () {
        Route::post('credit-terms/{creditTerms}/approve', [CreditTermsController::class, 'approve'])->whereNumber('creditTerms')->name('credit-terms.approve');
        Route::post('credit-terms/{creditTerms}/reject', [CreditTermsController::class, 'reject'])->whereNumber('creditTerms')->name('credit-terms.reject');
    });

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

    // ⭐ D-B (18 Oct 2026): the proprietor's own customer account, from
    // Settings > Company; the controller asks foundation.organization.manage.
    Route::post('owner-customer', [OwnerCustomerController::class, 'store'])->name('owner-customer.store');

    // ⚠ The customer master carries the credit limit and the price group, so
    // editing it is a financial decision rather than an operational one.
    Route::middleware('can:sales.customers.manage')->group(function () {
        Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
        // ⭐ 27 Sep 2026 (Joy): create a customer from inside a sale (JSON, name
        // is enough). Same permission as managing customers.
        Route::post('customers/quick', [CustomerQuickCreateController::class, 'store'])->name('customers.quick');
        Route::put('customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
        Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy');
        // ⭐ B13 (24 Oct 2026): Reactivate on screen; Delete to the bin when never used.
        Route::post('customers/{customer}/reactivate', [CustomerController::class, 'reactivate'])->whereNumber('customer')->name('customers.reactivate');
        Route::post('customers/{customer}/delete', [CustomerController::class, 'bin'])->whereNumber('customer')->name('customers.bin');
        // ⭐ Plan D11: trade licence and NID scans. Adding needs the same trust as
        // editing the customer; opening or removing an NID also needs
        // sales.customers.identity.view (checked in IdentityDocuments and on the stream).
        Route::post('customers/{customer}/documents', [CustomerDocumentController::class, 'store'])
            ->whereNumber('customer')->name('customers.documents.store');
        Route::delete('customers/{customer}/documents/{attachment}', [CustomerDocumentController::class, 'destroy'])
            ->whereNumber('customer')->whereNumber('attachment')->name('customers.documents.destroy');

        // The list in and out of a spreadsheet. Same permission as editing one
        // by hand: a file that sets price groups and credit limits is the same
        // financial decision taken in bulk.
        Route::get('customers/template', [CustomerImportController::class, 'template'])->name('customers.template');
        Route::get('customers/export', [CustomerImportController::class, 'export'])->name('customers.export');
        Route::post('customers/import/review', [CustomerImportController::class, 'review'])->name('customers.import.review');
        Route::post('customers/import/apply', [CustomerImportController::class, 'apply'])->name('customers.import.apply');
        // ⭐ Credit terms by file, applied only after approval (26 Sep 2026).
        Route::get('credit-terms/template', [CreditTermsController::class, 'template'])->name('credit-terms.template');
        Route::post('credit-terms/review', [CreditTermsController::class, 'review'])->name('credit-terms.review');
        Route::post('credit-terms', [CreditTermsController::class, 'submit'])->name('credit-terms.submit');
    });

    // ⚠⚠ Setting a price is a margin decision. Deliberately NOT given to Staff.
    // C-IMP-4. Replacing the whole price list is not the same power as adding
    // one rule: prices decide what every future sale is worth.
    Route::middleware('can:sales.prices.import')->group(function () {
        Route::get('prices/import', [PriceImportController::class, 'create'])->name('prices.import');
        // ⚠ A preview is a POST's answer. Refreshing it, or a refused Apply
        // sending the browser "back", asks for this URL with GET: land on the
        // import page with any message kept, never on a 405 (29 Sep 2026).
        Route::get('prices/import/preview', fn () => tap(redirect()->route('sales.prices.import'), fn () => session()->reflash()))->name('prices.import.preview.back');
        Route::post('prices/import/preview', [PriceImportController::class, 'preview'])->name('prices.import.preview');
        Route::post('prices/import', [PriceImportController::class, 'store'])->name('prices.import.store');
    });

    // ⚠ Export is gated with viewing the price screen, not with importing:
    // it holds nothing /sales/prices does not already show. ⚠⚠ It said so and
    // had NO gate at all until the 13 Oct 2026 audit, so a self-service login
    // could download every group price in force.
    Route::get('prices/export', [PriceImportController::class, 'export'])
        ->middleware('can:sales.reports.view')->name('prices.export');

    // ⭐ Market pricing (Phase 2 / 1B, 17 Oct 2026). The proposal and the
    // after-the-fact confirmation of a receipt line open to the receiver OR the
    // price-setter (checked in the controller: `can:` takes one ability); a
    // manual reprice is a price decision and sits with the prices below.
    Route::get('market-prices/proposal', [MarketPriceController::class, 'proposal'])->name('market-prices.proposal');
    Route::post('market-prices/receipt-lines/{receiptLine}', [MarketPriceController::class, 'confirmReceiptLine'])
        ->whereNumber('receiptLine')->name('market-prices.confirm-receipt-line');

    // ⭐ Initial plan B21 (24 Oct 2026, Joy's Q3 = A): retail sellers on
    // consignment. Owner and Manager (sales.sellers.manage) for everything; a
    // seller opens his OWN statement and sheets with his login alone, so the
    // gate is in RetailSellerController, not on the group.
    Route::get('sellers', [RetailSellerController::class, 'index'])->name('sellers.index');
    Route::post('sellers', [RetailSellerController::class, 'store'])->name('sellers.store');
    Route::get('sellers/{employee}', [RetailSellerController::class, 'show'])->whereNumber('employee')->name('sellers.show');
    Route::post('sellers/{employee}/handover', [RetailSellerController::class, 'handover'])->whereNumber('employee')->name('sellers.handover');
    Route::post('sellers/{employee}/return', [RetailSellerController::class, 'returnGoods'])->whereNumber('employee')->name('sellers.return');
    Route::post('sellers/{employee}/sales', [RetailSellerController::class, 'sales'])->whereNumber('employee')->name('sellers.sales');
    Route::get('sellers/sheets/{sheet}', [RetailSellerController::class, 'sheet'])->whereNumber('sheet')->name('sellers.sheet');

    Route::middleware('can:sales.prices.manage')->group(function () {
        Route::post('market-prices', [MarketPriceController::class, 'store'])->name('market-prices.store');
        // Targets + commission: a compensation decision, so the same gate
        // as pricing. Calculation only - payroll pays, by a human hand.
        Route::get('targets', [SalesTargetController::class, 'index'])->name('targets');
        Route::post('targets', [SalesTargetController::class, 'store'])->name('targets.store');
        // Buy X Get Y free: a pricing decision, so the pricing permission.
        Route::get('promotions', [PromotionController::class, 'index'])->name('promotions');
        Route::post('promotions', [PromotionController::class, 'store'])->name('promotions.store');
        Route::post('promotions/{promotion}/toggle', [PromotionController::class, 'toggle'])->name('promotions.toggle');
        Route::post('prices', [ProductPriceController::class, 'store'])->name('prices.store');
        // The product dialog's "Prices" section (21 Sep 2026): one price per
        // group, JSON beside the product. ⚠ A GET that reads and a PUT that
        // writes; the dialog stamps X-Business-Id like every other write.
        Route::get('group-prices', [ProductGroupPriceController::class, 'show'])->name('group-prices.show');
        Route::put('group-prices/{product}', [ProductGroupPriceController::class, 'update'])->name('group-prices.update');
        // Price groups: which exist, and which fall back to MRP.
        Route::get('price-groups', [PriceGroupController::class, 'index'])->name('price-groups.index');
        Route::post('price-groups', [PriceGroupController::class, 'store'])->name('price-groups.store');
        Route::put('price-groups/{priceGroup}', [PriceGroupController::class, 'update'])->name('price-groups.update');
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
        // A challan handed to a courier, and the courier's answer. Same trust as
        // recording the challan and billing it, which is what these replace.
        Route::post('deliveries/{delivery}/consignment', [ConsignmentController::class, 'store'])
            ->whereNumber('delivery')->name('consignments.store');
        Route::post('consignments/{consignment}/outcome', [ConsignmentController::class, 'outcome'])
            ->whereNumber('consignment')->name('consignments.outcome');
        // Courier hub slice 8: a parcel the courier lost or damaged.
        Route::post('consignments/{consignment}/loss', [ConsignmentController::class, 'reportLoss'])
            ->whereNumber('consignment')->name('consignments.loss');
    });

    // Courier hub slice 8: the Owner's decision on a door discount above the
    // cap. The workflow stage decides who may; the action checks it.
    Route::post('courier-door-discounts/{discount}/{decision}', [ConsignmentController::class, 'decideDiscount'])
        ->whereNumber('discount')->whereIn('decision', ['approve', 'reject'])->name('courier-door-discounts.decide');

    // ⭐ Courier hub slice 4: booking through the courier's API, and settling a
    // booking whose answer was lost. Technical authority (couriers.parcels.book)
    // beside the trust of recording the challan; none of it posts money.
    Route::middleware(['can:sales.sell', 'can:couriers.parcels.book'])->group(function () {
        Route::post('deliveries/{delivery}/courier-booking', [CourierParcelController::class, 'book'])
            ->whereNumber('delivery')->name('consignments.book');
        Route::post('courier-bookings/{bookingRequest}/look-up', [CourierParcelController::class, 'lookUp'])
            ->whereNumber('bookingRequest')->name('courier-bookings.look-up');
        Route::post('courier-bookings/{bookingRequest}/booked', [CourierParcelController::class, 'markBooked'])
            ->whereNumber('bookingRequest')->name('courier-bookings.booked');
        Route::post('courier-bookings/{bookingRequest}/not-booked', [CourierParcelController::class, 'markNotBooked'])
            ->whereNumber('bookingRequest')->name('courier-bookings.not-booked');
        Route::post('consignments/{consignment}/cancel', [CourierParcelController::class, 'cancel'])
            ->whereNumber('consignment')->name('consignments.cancel');

        // ⭐ Parcels to book (Joy, 11 Oct 2026): approved orders, challans made
        // and booked in one queued run, with labels to print. Same authority as
        // booking one parcel from the order screen.
        Route::get('parcels-to-book', [CourierBulkBookingController::class, 'index'])->name('parcels-to-book.index');
        Route::post('parcels-to-book', [CourierBulkBookingController::class, 'store'])->name('parcels-to-book.store');
        Route::get('parcels-to-book/labels', [CourierBulkBookingController::class, 'labels'])->name('parcels-to-book.labels');
        Route::get('parcels-to-book/runs/{run}', [CourierBulkBookingController::class, 'show'])
            ->whereNumber('run')->name('parcels-to-book.runs.show');
        Route::get('parcels-to-book/runs/{run}/progress', [CourierBulkBookingController::class, 'progress'])
            ->whereNumber('run')->name('parcels-to-book.runs.progress');
    });

    // ⭐ Slice 5: what a challan would cost at the courier, live on the
    // booking form. JSON, read-only (a Pathao price quote books nothing).
    Route::get('deliveries/{delivery}/courier-estimate', [CourierParcelController::class, 'estimate'])
        ->middleware('can:couriers.parcels.view')->whereNumber('delivery')->name('consignments.estimate');

    // ⭐ Slice 5: estimate vs the courier's quote vs the statement, per parcel.
    Route::get('courier-fees', [CourierFeesController::class, 'index'])
        ->middleware('can:couriers.ratecards.propose')->name('courier-fees.index');

    // Asking the courier where a parcel is: read-only at the courier.
    Route::post('consignments/{consignment}/refresh', [CourierParcelController::class, 'refresh'])
        ->middleware('can:couriers.parcels.view')->whereNumber('consignment')->name('consignments.refresh');

    // The parcels list: for whoever reads sales, or follows parcels.
    Route::get('consignments', [ConsignmentController::class, 'index'])
        ->middleware('can:sales.parcels.see')->name('consignments.index');

    Route::middleware('can:sales.reports.view')->group(function () {
        Route::get('courier-statements', [CourierStatementController::class, 'index'])->name('courier-statements.index');
        Route::get('courier-statements/{courierStatement}', [CourierStatementController::class, 'show'])
            ->whereNumber('courierStatement')->name('courier-statements.show');
        // v2: the rows the file could not be read on, and the exception workbook.
        // Neither carries a customer's name or phone.
        Route::get('courier-statements/{courierStatement}/errors', [CourierStatementController::class, 'errors'])
            ->whereNumber('courierStatement')->name('courier-statements.errors');
        Route::get('courier-statements/{courierStatement}/workbook', [CourierStatementController::class, 'workbook'])
            ->whereNumber('courierStatement')->name('courier-statements.workbook');
    });

    // A courier's payout: money arriving and its fees, the trust level of
    // recording a customer receipt.
    Route::middleware('can:sales.receipt.record')->group(function () {
        Route::post('courier-statements', [CourierStatementController::class, 'store'])->name('courier-statements.store');
        // Courier hub slice 8: a loss claim's answer and money paid outside a statement.
        Route::post('courier-claims/{claim}/decision', [ConsignmentController::class, 'decideClaim'])
            ->whereNumber('claim')->name('courier-claims.decide');
        Route::post('courier-claims/{claim}/compensation', [ConsignmentController::class, 'compensation'])
            ->whereNumber('claim')->name('courier-claims.compensation');
        // ⚠ The courier's original file carries customer names and phones.
        Route::get('courier-statements/{courierStatement}/source', [CourierStatementController::class, 'source'])
            ->whereNumber('courierStatement')->name('courier-statements.source');
        Route::post('courier-statements/{courierStatement}/lines/{line}/resolution', [CourierStatementController::class, 'resolve'])
            ->whereNumber(['courierStatement', 'line'])->name('courier-statements.resolve');
        Route::post('courier-statements/{courierStatement}/post', [CourierStatementController::class, 'post'])
            ->whereNumber('courierStatement')->name('courier-statements.post');
        Route::delete('courier-statements/{courierStatement}', [CourierStatementController::class, 'destroy'])
            ->whereNumber('courierStatement')->name('courier-statements.destroy');
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
        // The 80mm counter slip - same reading permission, reprint = revisit.
        Route::get('invoices/{invoice}/slip', [SalesInvoiceController::class, 'slip'])
            ->whereNumber('invoice')->name('invoices.slip');
        Route::get('deliveries/{delivery}/print', [DeliveryController::class, 'print'])
            ->whereNumber('delivery')->name('deliveries.print');
        // Every receipt, with what it paid: the list that did not exist.
        Route::get('receipts', [ReceiptController::class, 'index'])->name('receipts.index');
        Route::get('receipts/{receipt}/print', [SalesInvoiceController::class, 'printReceipt'])
            ->whereNumber('receipt')->name('receipts.print');
        // The same receipt on a till roll. Same permission - a thermal copy of a
        // document is the same document.
        Route::get('receipts/{receipt}/slip', [SalesInvoiceController::class, 'receiptSlip'])
            ->whereNumber('receipt')->name('receipts.slip');
    });

    Route::middleware('can:sales.receipt.record')->group(function () {
        // ⭐ Receive payment from the customer's side, over several invoices at
        // once, oldest first (21 Sep 2026). The per-invoice form stays too.
        Route::get('receipts/new', [ReceiptController::class, 'create'])->name('receipts.create');
        Route::post('receipts', [ReceiptController::class, 'store'])->name('receipts.store');
        Route::post('invoices/{invoice}/receipts', [SalesInvoiceController::class, 'receipt'])->name('invoices.receipts.store');
        // Money paid for an order, before or after its invoice (SSLCommerz
        // slice P1). The same trust level as a receipt, because it is one.
        Route::post('orders/{order}/payments', [SalesOrderController::class, 'payment'])
            ->whereNumber('order')->name('orders.payments.store');
    });

    // ⭐ Corrections wave 1 (20 Oct 2026, FINAL-PLAN 3.2). Reverse and Correct
    // themselves go through the generic /records/{type}/... routes; these are
    // the two Correct pages and the three actions that are documents of their
    // own. Each controller method asks its own permission.
    Route::middleware('can:sales.reports.view')->group(function () {
        Route::get('invoices/{invoice}/correct', [SalesCorrectionController::class, 'correctInvoice'])
            ->whereNumber('invoice')->name('invoices.correct');
        // ⭐ 24 Oct 2026: Price credit and Extra charge moved to the engine
        // (/records/sales.price_credit/... and /records/sales.extra_charge/...),
        // so these two plain posts, which skipped its preview, the Tk 20,000
        // second person and the closed-month rule, are gone.
        Route::get('receipts/{receipt}/correct', [SalesCorrectionController::class, 'correctReceipt'])
            ->whereNumber('receipt')->name('receipts.correct');
        Route::post('receipts/{receipt}/reallocate', [SalesCorrectionController::class, 'reallocate'])
            ->whereNumber('receipt')->name('receipts.reallocate');
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
        // Money an order holds, paid back (SSLCommerz slice P1).
        Route::post('orders/{order}/refunds', [SalesOrderController::class, 'refund'])
            ->whereNumber('order')->name('orders.refunds.store');
    });

    // ⚠⚠ Money sent back THROUGH SSLCommerz (slice P4): the Owner only (Joy, 16
    // September), because here the ERP itself moves the money.
    Route::middleware('can:sales.gateway.refund')->group(function () {
        Route::post('orders/{order}/gateway-refunds', [GatewayRefundController::class, 'store'])
            ->whereNumber('order')->name('orders.gateway-refunds.store');
        Route::post('orders/{order}/gateway-refunds/{refund}/check', [GatewayRefundController::class, 'check'])
            ->whereNumber(['order', 'refund'])->name('orders.gateway-refunds.check');
    });
});

// ⭐ Settings > Couriers (courier hub slice 1, 11 Oct 2026). Under /settings with
// the other connections, owned by Sales. Owner only by default: credentials and
// webhook secrets, never accounting authority (ChatGPT risk 11).
Route::middleware(['web', 'auth', 'business', 'can:couriers.settings.manage'])
    ->prefix('settings/couriers')->name('couriers.settings.')->group(function () {
        Route::get('/', [CourierSettingsController::class, 'index'])->name('index');
        Route::post('/', [CourierSettingsController::class, 'store'])->name('store');

        // Locations (slice 2): the courier's own lists, the reviewed thana
        // maps and the courier's price bands. Provider-wide data.
        Route::prefix('locations')->name('locations.')->group(function () {
            Route::get('/', [CourierLocationsController::class, 'index'])->name('index');
            Route::get('options', [CourierLocationsController::class, 'options'])->name('options');
            Route::get('suggest', [CourierLocationsController::class, 'suggest'])->name('suggest');
            Route::post('sync', [CourierLocationsController::class, 'sync'])->name('sync');
            Route::post('match', [CourierLocationsController::class, 'match'])->name('match');
            Route::post('approve-high', [CourierLocationsController::class, 'approveHigh'])->name('approve-high');
            Route::put('maps', [CourierLocationsController::class, 'save'])->name('save');
            Route::post('maps/{map}/approve', [CourierLocationsController::class, 'approve'])->whereNumber('map')->name('approve');
            Route::post('bands', [CourierLocationsController::class, 'storeBand'])->name('bands.store');
            Route::put('bands/{band}', [CourierLocationsController::class, 'updateBand'])->whereNumber('band')->name('bands.update');
            Route::delete('bands/{band}', [CourierLocationsController::class, 'destroyBand'])->whereNumber('band')->name('bands.destroy');
        });
        Route::put('{courierAccount}', [CourierSettingsController::class, 'update'])->whereNumber('courierAccount')->name('update');
        Route::post('{courierAccount}/test', [CourierSettingsController::class, 'test'])->whereNumber('courierAccount')->name('test');
        Route::post('{courierAccount}/activate', [CourierSettingsController::class, 'activate'])->whereNumber('courierAccount')->name('activate');
        Route::post('{courierAccount}/deactivate', [CourierSettingsController::class, 'deactivate'])->whereNumber('courierAccount')->name('deactivate');
        Route::post('{courierAccount}/auto-post', [CourierSettingsController::class, 'autoPost'])->whereNumber('courierAccount')->name('auto-post');
        Route::post('{courierAccount}/rotate-webhook-secret', [CourierSettingsController::class, 'rotateSecret'])->whereNumber('courierAccount')->name('rotate-secret');
        Route::put('{courierAccount}/business', [CourierSettingsController::class, 'linkBusiness'])->whereNumber('courierAccount')->name('link');
        Route::delete('{courierAccount}/business', [CourierSettingsController::class, 'unlinkBusiness'])->whereNumber('courierAccount')->name('unlink');
    });

// ⭐ Settings > Couriers > Rate cards (courier hub slice 5). Not under the
// Owner-only settings group: a Manager proposes and edits drafts; making a
// card the price the ERP estimates with is the Owner's (approve).
Route::middleware(['web', 'auth', 'business', 'can:couriers.ratecards.propose'])
    ->prefix('settings/couriers/rate-cards')->name('couriers.ratecards.')->group(function () {
        Route::get('/', [CourierRateCardController::class, 'index'])->name('index');
        Route::post('/', [CourierRateCardController::class, 'store'])->name('store');
        Route::post('propose', [CourierRateCardController::class, 'propose'])->name('propose');
        Route::put('{rateCard}', [CourierRateCardController::class, 'update'])->whereNumber('rateCard')->name('update');
        Route::post('{rateCard}/import', [CourierRateCardController::class, 'import'])->whereNumber('rateCard')->name('import');
        Route::get('{rateCard}/export', [CourierRateCardController::class, 'export'])->whereNumber('rateCard')->name('export');
        Route::delete('{rateCard}', [CourierRateCardController::class, 'destroy'])->whereNumber('rateCard')->name('destroy');

        Route::middleware('can:couriers.ratecards.approve')->group(function () {
            Route::post('{rateCard}/approve', [CourierRateCardController::class, 'approve'])->whereNumber('rateCard')->name('approve');
            Route::post('{rateCard}/retire', [CourierRateCardController::class, 'retire'])->whereNumber('rateCard')->name('retire');
        });
    });

// ⭐ Courier webhooks (courier hub slice 4). OUTSIDE the web group on purpose:
// no session, no CSRF, no business middleware. The courier proves itself with
// the account's shared header secret, and the parcel's business comes from our
// own record, never from the body (ruling 12). Throttled per address.
Route::post('webhooks/couriers/{uuid}', CourierWebhookController::class)
    ->middleware('throttle:300,1')
    ->whereUuid('uuid')
    ->name('couriers.webhook');
