<?php

namespace App\Support\Documents;

use App\Models\User;
use App\Modules\Accounting\Models\IncomeVoucher;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\OwnerWithdrawal;
use App\Modules\Accounting\Models\PartyAdjustment;
use App\Modules\Accounting\Models\SetOff;
use App\Modules\Accounting\Models\Voucher;
use App\Modules\Hrm\Models\StaffPurchaseReplacement;
use App\Modules\Hrm\Models\StaffPurchaseRequest;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\OpeningStock;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductionRun;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Purchasing\Models\ExpenseVoucher;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseReturn;
use App\Modules\Purchasing\Models\QuickPurchase;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierAdvance;
use App\Modules\Purchasing\Models\SupplierAdvanceApplication;
use App\Modules\Purchasing\Models\SupplierAdvanceRefund;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierCreditNote;
use App\Modules\Purchasing\Models\SupplierDiscount;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Models\SupplierRefund;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\CustomerRefund;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * ⭐ WHERE EVERY DOCUMENT LIVES, IN ONE PLACE (PR5 connected-360, 18 Oct 2026).
 *
 * Joy: "using any one connection I can fetch out all its related
 * transactions". Tally's rule (research A11) is that every row on every
 * report opens the voucher behind it. Before this, each screen built its own
 * links (SupplierLedger::documentUrl, the batch trace, the customer
 * statement), so a document type added later was linkable on one page and a
 * dead row on the next.
 *
 * One registry: type key, plain label, the attribute that carries the
 * document's own number, its date, and the route that opens it with the
 * permission that route checks. Every 360 page, the stock card and the
 * related-documents panel ask here.
 *
 * ⚠⚠ A LINK THE VIEWER CANNOT OPEN IS NOT A LINK. `url()` returns null when
 * the route is missing or the viewer lacks the route's permission, so a row
 * shows its number as plain text instead of a link that answers 403.
 *
 * ⚠ Some documents have no page of their own yet (a goods receipt, a
 * purchase return, a landed cost). They open the page that shows them today
 * (the purchase order, the landed-costs list) and switch to their own page by
 * itself the day a route with the expected name is registered (PR2 adds
 * receipt pages), because the preferred route is tried first.
 */
final class DocumentLinks
{
    /**
     * class => [type key, label, number attribute, date attribute]
     *
     * @var array<class-string, array{0: string, 1: string, 2: string|null, 3: string|null}>
     */
    private const TYPES = [
        PurchaseOrder::class => ['purchase_order', 'Purchase order', 'po_number', 'order_date'],
        GoodsReceipt::class => ['goods_receipt', 'Goods received', 'grn_number', 'received_at'],
        PurchaseReturn::class => ['purchase_return', 'Purchase return', 'return_number', 'returned_at'],
        SupplierBill::class => ['supplier_bill', 'Supplier bill', 'bill_number', 'bill_date'],
        SupplierPayment::class => ['supplier_payment', 'Supplier payment', 'reference', 'paid_on'],
        SupplierAdvance::class => ['supplier_advance', 'Supplier advance', 'number', 'paid_on'],
        SupplierAdvanceApplication::class => ['advance_application', 'Advance applied to bill', 'number', 'applied_on'],
        SupplierAdvanceRefund::class => ['advance_refund', 'Advance refunded', 'number', 'refunded_on'],
        SupplierRefund::class => ['supplier_refund', 'Supplier refund', 'number', 'received_on'],
        SupplierCreditNote::class => ['supplier_credit_note', 'Supplier credit note', 'credit_note_number', 'credit_date'],
        SupplierDiscount::class => ['supplier_discount', 'Supplier discount', 'number', 'discount_on'],
        LandedCost::class => ['landed_cost', 'Landed cost', 'number', 'incurred_on'],
        ExpenseVoucher::class => ['expense_voucher', 'Expense voucher', 'voucher_number', 'spent_on'],
        QuickPurchase::class => ['quick_purchase', 'Buy and receive', null, 'purchased_on'],
        PartyAdjustment::class => ['party_adjustment', 'Party adjustment', null, 'adjusted_on'],
        SetOff::class => ['set_off', 'Set-off', 'number', 'set_off_on'],
        Voucher::class => ['voucher', 'Voucher', 'voucher_number', 'voucher_date'],
        Journal::class => ['journal', 'Journal', 'journal_number', 'journal_date'],
        IncomeVoucher::class => ['income_voucher', 'Income voucher', 'voucher_number', 'received_on'],
        OwnerWithdrawal::class => ['owner_withdrawal', 'Owner withdrawal', 'number', 'withdrawn_on'],
        SalesOrder::class => ['sales_order', 'Sales order', 'order_number', 'ordered_at'],
        Delivery::class => ['delivery', 'Delivery challan', 'delivery_number', 'delivered_at'],
        SalesInvoice::class => ['sales_invoice', 'Sales invoice', 'invoice_number', 'invoice_date'],
        CustomerReceipt::class => ['customer_receipt', 'Customer receipt', 'reference', 'received_on'],
        SalesReturn::class => ['sales_return', 'Sales return', 'return_number', 'returned_at'],
        CustomerRefund::class => ['customer_refund', 'Customer refund', 'reference', 'refunded_on'],
        ProductionRun::class => ['production_run', 'Production run', 'run_number', 'occurred_at'],
        StockCount::class => ['stock_count', 'Stock count', 'count_number', 'completed_at'],
        InventoryAdjustment::class => ['stock_adjustment', 'Stock adjustment', null, 'effective_date'],
        OpeningStock::class => ['opening_stock', 'Opening stock', null, 'as_at'],
        StockTransfer::class => ['stock_transfer', 'Stock transfer', null, 'dispatched_at'],
        StockBatch::class => ['batch', 'Batch', 'batch_number', 'received_at'],
        StaffPurchaseRequest::class => ['staff_purchase', 'Staff purchase', null, 'created_at'],
        StaffPurchaseReplacement::class => ['staff_replacement', 'Staff purchase replacement', null, 'created_at'],
        Product::class => ['product', 'Product', 'sku', null],
        Supplier::class => ['supplier', 'Supplier', 'code', null],
        Customer::class => ['customer', 'Customer', 'code', null],
    ];

    /** Every type key, in registry order: the filter lists and the tests read it. */
    public static function types(): array
    {
        return array_values(array_map(fn (array $entry) => $entry[0], self::TYPES));
    }

    /** The plain label for a type key ("supplier_bill" => "Supplier bill"). */
    public static function labelFor(string $type): string
    {
        foreach (self::TYPES as $entry) {
            if ($entry[0] === $type) {
                return $entry[1];
            }
        }

        return ucfirst(str_replace('_', ' ', $type));
    }

    public static function knows(Model $document): bool
    {
        return isset(self::TYPES[$document::class]);
    }

    public static function type(Model $document): string
    {
        return self::TYPES[$document::class][0] ?? Str::snake(class_basename($document));
    }

    public static function label(Model $document): string
    {
        // A customer receipt against an ORDER, before any invoice, is the
        // customer's advance (2150); the person calls it that.
        if ($document instanceof CustomerReceipt && $document->sales_order_id !== null) {
            return 'Customer advance';
        }

        if ($document instanceof Voucher) {
            return $document->type->label();
        }

        return self::TYPES[$document::class][1] ?? Str::headline(class_basename($document));
    }

    /** The document's own number, or "#id" when it carries none. */
    public static function number(Model $document): string
    {
        $attribute = self::TYPES[$document::class][2] ?? null;
        $value = $attribute === null ? null : $document->getAttribute($attribute);

        if ($value !== null && $value !== '') {
            return (string) $value;
        }

        return '#'.$document->getKey();
    }

    public static function date(Model $document): ?string
    {
        $attribute = self::TYPES[$document::class][3] ?? null;
        $value = $attribute === null ? null : $document->getAttribute($attribute);

        if ($document instanceof PurchaseOrder && $value === null) {
            $value = $document->created_at;
        }

        if ($value === null) {
            return null;
        }

        return $value instanceof \DateTimeInterface ? Carbon::instance($value)->toDateString() : substr((string) $value, 0, 10);
    }

    /**
     * Whether the viewer may see this KIND of document at all. A 360 page
     * leaves out a row the viewer may not read, rather than showing a number
     * they cannot open.
     */
    public static function visible(Model|string $document, ?User $viewer): bool
    {
        if ($viewer === null) {
            return true;
        }

        $class = is_string($document) ? $document : $document::class;

        foreach (self::seePermissions($class) as $ability) {
            if (! $viewer->can($ability)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The address that opens this document for this viewer, or null.
     */
    public static function url(Model $document, ?User $viewer = null): ?string
    {
        if (! self::visible($document, $viewer)) {
            return null;
        }

        foreach (self::routes($document) as [$name, $parameters, $abilities]) {
            if (! Route::has($name)) {
                continue;
            }

            if ($viewer !== null && collect($abilities)->contains(fn (string $ability) => ! $viewer->can($ability))) {
                continue;
            }

            $url = route($name, $parameters, false);

            return $document instanceof GoodsReceipt && $name === 'purchasing.purchase-orders.show'
                ? $url.'#receipt-'.$document->getKey()
                : $url;
        }

        return null;
    }

    /**
     * The shape every screen receives for a document.
     *
     * @return array{type: string, label: string, number: string, date: string|null, url: string|null}|null
     */
    public static function ref(?Model $document, ?User $viewer = null): ?array
    {
        if ($document === null) {
            return null;
        }

        return [
            'type' => self::type($document),
            'label' => self::label($document),
            'number' => self::number($document),
            'date' => self::date($document),
            'url' => self::url($document, $viewer),
        ];
    }

    /**
     * The permission(s) needed to read this kind of document anywhere.
     *
     * @return list<string>
     */
    private static function seePermissions(string $class): array
    {
        return match ($class) {
            PurchaseOrder::class, GoodsReceipt::class, PurchaseReturn::class, SupplierBill::class,
            SupplierPayment::class, SupplierAdvance::class, SupplierAdvanceApplication::class,
            SupplierAdvanceRefund::class, SupplierRefund::class, SupplierCreditNote::class,
            SupplierDiscount::class, ExpenseVoucher::class, QuickPurchase::class, Supplier::class => ['purchasing.reports.view'],
            LandedCost::class => ['purchasing.landed_cost.view'],
            SalesOrder::class => ['sales.orders.see'],
            Delivery::class, SalesInvoice::class, CustomerReceipt::class, SalesReturn::class,
            CustomerRefund::class, Customer::class => ['sales.reports.view'],
            Voucher::class, Journal::class, IncomeVoucher::class, PartyAdjustment::class => ['accounting.view'],
            SetOff::class => ['purchasing.reports.view', 'sales.reports.view'],
            OwnerWithdrawal::class => ['accounting.owner_withdrawal.view'],
            ProductionRun::class => ['inventory.production.manage'],
            StockCount::class, InventoryAdjustment::class, OpeningStock::class, StockTransfer::class,
            StockBatch::class, Product::class => ['inventory.reports.view'],
            default => [],
        };
    }

    /**
     * Candidate routes, preferred first: [route name, parameters, abilities].
     *
     * @return list<array{0: string, 1: array<string, mixed>|int, 2: list<string>}>
     */
    private static function routes(Model $document): array
    {
        $id = $document->getKey();

        return match (true) {
            $document instanceof PurchaseOrder => [['purchasing.purchase-orders.show', $id, []]],
            $document instanceof GoodsReceipt => [
                ['purchasing.receipts.show', $id, []],
                ['purchasing.purchase-orders.show', (int) $document->purchase_order_id, []],
            ],
            $document instanceof PurchaseReturn => [
                ['purchasing.returns.show', $id, []],
                ['purchasing.purchase-orders.show', (int) $document->purchase_order_id, []],
            ],
            $document instanceof SupplierBill => [['purchasing.bills.show', $id, []]],
            $document instanceof SupplierPayment => [['purchasing.payments.show', $id, []]],
            $document instanceof SupplierAdvance => [['purchasing.advances.show', $id, []]],
            $document instanceof SupplierAdvanceApplication, $document instanceof SupplierAdvanceRefund => [
                ['purchasing.advances.show', (int) $document->supplier_advance_id, []],
            ],
            $document instanceof SupplierRefund => [['purchasing.advances.supplier', (int) $document->supplier_id, []]],
            $document instanceof SupplierCreditNote => [['purchasing.credit-notes.show', $id, []]],
            $document instanceof SupplierDiscount => array_values(array_filter([
                $document->supplier_bill_id === null ? null : ['purchasing.bills.show', (int) $document->supplier_bill_id, []],
                $document->supplier_payment_id === null ? null : ['purchasing.payments.show', (int) $document->supplier_payment_id, []],
                $document->journal_id === null ? null : ['accounting.journals.show', (int) $document->journal_id, ['accounting.view']],
            ])),
            $document instanceof LandedCost => [
                ['purchasing.landed-costs.show', $id, []],
                ['purchasing.landed-costs.index', ['search' => self::number($document)], []],
            ],
            $document instanceof ExpenseVoucher => [['purchasing.expenses.print', $id, []]],
            $document instanceof QuickPurchase => [['purchasing.buy-and-receive.show', $id, []]],
            $document instanceof PartyAdjustment => [['accounting.adjustments.index', [], ['accounting.journal.post']]],
            $document instanceof SetOff => [['accounting.set-offs.show', $id, []]],
            $document instanceof Voucher => [['accounting.vouchers.show', $id, []]],
            $document instanceof Journal => [['accounting.journals.show', $id, []]],
            $document instanceof IncomeVoucher => [['accounting.other-income.print', $id, []]],
            $document instanceof OwnerWithdrawal => [['accounting.owner-withdrawals.index', [], []]],
            $document instanceof SalesOrder => [['sales.orders.show', $id, []]],
            $document instanceof Delivery => [
                ['sales.deliveries.show', $id, []],
                ['sales.deliveries.print', $id, []],
            ],
            $document instanceof SalesInvoice => [['sales.invoices.show', $id, []]],
            $document instanceof CustomerReceipt => array_values(array_filter([
                $document->sales_order_id === null ? null : ['sales.orders.show', (int) $document->sales_order_id, ['sales.orders.see']],
                ['sales.receipts.print', $id, []],
            ])),
            $document instanceof SalesReturn => [['sales.returns.show', $id, []]],
            $document instanceof CustomerRefund => array_values(array_filter([
                $document->sales_return_id === null ? null : ['sales.returns.show', (int) $document->sales_return_id, []],
                $document->sales_order_id === null ? null : ['sales.orders.show', (int) $document->sales_order_id, ['sales.orders.see']],
            ])),
            $document instanceof ProductionRun => [['inventory.production-runs.show', $id, []]],
            $document instanceof StockCount => [['inventory.stock-counts.show', $id, []]],
            $document instanceof InventoryAdjustment => array_values(array_filter([
                $document->source instanceof StockCount ? ['inventory.stock-counts.show', (int) $document->source_id, []] : null,
                ['inventory.stock.index', [], []],
            ])),
            $document instanceof OpeningStock => [['inventory.opening-stock.index', [], ['inventory.stock.adjust']]],
            $document instanceof StockTransfer => [['inventory.stock.transfers.index', [], []]],
            $document instanceof StockBatch => [['inventory.batches.show', $id, []]],
            $document instanceof StaffPurchaseRequest, $document instanceof StaffPurchaseReplacement => [['hrm.staff-purchases.index', [], ['hrm.employees.manage']]],
            $document instanceof Product => [['inventory.products.show', $id, []]],
            $document instanceof Supplier => [['purchasing.suppliers.show', $id, []]],
            $document instanceof Customer => [['sales.customers.show', $id, []]],
            default => [],
        };
    }
}
