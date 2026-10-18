<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Actions\DeleteAttachment;
use App\Modules\Foundation\Actions\RejectApprovable;
use App\Modules\Foundation\Actions\StoreAttachment;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\BusinessIdentity;
use App\Modules\Inventory\Actions\ResolveReceiptConversion;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\ApprovePurchaseOrder;
use App\Modules\Purchasing\Actions\CancelPurchaseOrder;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RaiseOrderCharges;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Actions\RecordPurchaseReturn;
use App\Modules\Purchasing\Actions\RecordQuickPurchase;
use App\Modules\Purchasing\Actions\RecordSupplierAdvance;
use App\Modules\Purchasing\Actions\UpdatePurchaseOrder;
use App\Modules\Purchasing\Enums\PurchaseEntryMode;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Exceptions\PurchaseApprovalRequired;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Support\LastReceivedCost;
use App\Modules\Purchasing\Support\LikelyDuplicateBill;
use App\Modules\Purchasing\Support\PaymentTerms;
use App\Modules\Purchasing\Support\PurchaseLineEntry;
use App\Modules\Purchasing\Support\PurchaseOrderBilling;
use App\Modules\Purchasing\Support\PurchaseOrderCharges;
use App\Modules\Purchasing\Support\PurchaseOrderForm;
use App\Modules\Purchasing\Support\PurchasingApprovalGate;
use App\Modules\Purchasing\Support\ReceivedBatchDates;
use App\Modules\Purchasing\Support\RefusalMessage;
use App\Modules\Purchasing\Support\SupplierAdvanceBook;
use App\Modules\Purchasing\Support\SupplierChallans;
use App\Modules\Sales\Support\ProductPricing;
use App\Support\RecordTimeline;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

/**
 * ⚠⚠ C-PUR-E1. Every refusal below already carried a message written for a
 * person; none of them had anywhere to land, so each arrived as a blank 500.
 * The hierarchy is worth stating once, because it decides these catch clauses:
 * PeriodClosedException, MappingNotFound and InsufficientStockException all
 * extend RuntimeException; MissingUnitConversionException extends
 * DomainException. Catching RuntimeException, InvalidArgumentException and
 * DomainException therefore nets every business refusal these actions raise,
 * while ValidationException (which extends none of them) passes through
 * untouched because it already renders correctly.
 *
 * ⚠ Two surfaces on purpose. A form submission gets a validation error on a key
 * the form renders. A bare button has no form to attach one to, so it flashes
 * to the global error toast, the way Foundation already refuses button actions.
 */
class PurchaseOrderController extends Controller
{
    /** ⭐ Phase 2 / 1B: the same converter the receipt uses, for the price proposal per stock unit. */
    public function __construct(private readonly ResolveReceiptConversion $conversion) {}

    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->string('search')->toString(),
            'status' => $request->string('status')->toString(),
            // Purchases redesign (15 Oct 2026): the overview's chain steps open
            // this list pre-filtered. `approval` is the request's own status;
            // `stage` is awaiting_goods (approved, not fully received) or
            // to_bill (received, not yet billed: the bill form's own test).
            'approval' => $request->string('approval')->toString(),
            'stage' => $request->string('stage')->toString(),
        ];

        $orders = PurchaseOrder::with(['supplier:id,name', 'warehouse:id,name', 'approvalRequest', ...PurchaseOrderBilling::eagerLoad()])
            ->withSum('lines as total_ordered', 'quantity_ordered')
            ->when($filters['search'], fn ($query, $search) => $query->where('po_number', 'like', "%{$search}%"))
            ->when($filters['status'], fn ($query, $status) => $query->where('status', $status))
            ->when($filters['approval'], fn ($query, $approval) => $query
                ->where('status', '!=', PurchaseOrderStatus::Cancelled->value)
                ->whereHas('approvalRequest', fn ($r) => $r->where('status', $approval)))
            ->when($filters['stage'] === 'awaiting_goods', fn ($query) => $query
                ->whereIn('status', [PurchaseOrderStatus::Open->value, PurchaseOrderStatus::PartiallyReceived->value])
                ->whereHas('approvalRequest', fn ($r) => $r->where('status', ApprovalStatus::Approved->value)))
            ->when($filters['stage'] === 'to_bill', fn ($query) => $query
                ->where('status', '!=', PurchaseOrderStatus::Cancelled->value)
                ->whereHas('lines', fn ($line) => $line->stillBillable()))
            ->latest()
            ->paginate(15)
            ->withQueryString()
            // ⚠⚠ "Billing" is DERIVED from the order's supplier bills, replacing
            // the hand-set payment status this column used to show (audit #11).
            // The retired column is hidden so no screen can read it by accident.
            ->through(function (PurchaseOrder $order) {
                $order->setAttribute('billing', PurchaseOrderBilling::summarise($order));

                return $order->unsetRelation('bills')->makeHidden('payment_status');
            });

        // ⭐ Purchase redesign PR1 (18 Oct 2026): the list is a list. The new
        // order is its own page (create()), with every default it can fill.
        return Inertia::render('purchasing/purchase-orders/index', [
            'orders' => $orders,
            'can_create' => request()->user()->can('purchasing.po.create'),
            'filters' => $filters,
        ]);
    }

    /**
     * The order as the supplier receives it.
     *
     * ⚠⚠ THE LINE UNIT IS THE PURCHASE UNIT and it is printed. Mustard oil is
     * ordered in kilograms while the warehouse holds litres; an order that says
     * "100" with no unit invites the supplier to send 100 of the wrong thing,
     * and the internal screen already carries this warning for the same reason.
     *
     * ⚠ Money IS on this document, unlike a delivery challan: a purchase order
     * is an offer to pay, and a supplier needs the rate they are being held to.
     */
    public function print(PurchaseOrder $purchaseOrder): Response
    {
        $this->authorize('purchasing.reports.view');

        $purchaseOrder->load([
            'supplier',
            'warehouse:id,name,code',
            'lines.product:id,name,sku,unit_of_measure_id,purchase_unit_id',
            'lines.product.unitOfMeasure:id,abbreviation',
            'lines.product.purchaseUnit:id,abbreviation',
            // ⭐ PR1: everything the full page saved prints.
            'buyer:id,name',
            'lines.unit:id,abbreviation',
            'charges.supplier:id,name',
            'charges.moneyAccount:id,name',
            'charges.orderLine.product:id,name',
            'paymentSchedule',
            'attachments',
            ...PurchaseOrderBilling::eagerLoad(),
        ]);

        // 17 Oct 2026: the stored line totals, which is what the supplier agreed.
        $subtotal = Money::sum($purchaseOrder->lines->map(fn ($line) => $line->lineTotal()));

        $tax = Money::ofTaka((string) ($purchaseOrder->tax_total ?? '0'));

        return Inertia::render('purchasing/purchase-orders/print', [
            // ⚠ Re-check F39, 17 Oct 2026: `created_at` serialises as a UTC
            // timestamp and the page sliced it, so an order raised before 6 am
            // Dhaka printed yesterday's date. Sent as the Dhaka date.
            // ⭐ D-G, 18 Oct 2026: the print shows `order_date`, the day the
            // order was placed; created_at stays for anything that reads it.
            'order' => array_merge($purchaseOrder->toArray(), [
                'created_at' => $purchaseOrder->created_at?->toDateString(),
                'order_date' => $purchaseOrder->orderDateString(),
            ]),
            // ⚠ Totalled on the server from the same Money type the ledger uses.
            // A page that adds its own lines up is a page that can disagree with
            // the bill this order becomes.
            'subtotal' => $subtotal->toTaka(),
            'tax' => $tax->toTaka(),
            // ⭐ PR1: the stored grand total (goods less discounts, plus VAT,
            // this supplier's charges and rounding); an older order's lines.
            'total' => $purchaseOrder->grandTotal()->toTaka(),
            'full' => $this->fullFigures($purchaseOrder),
            'issuer' => BusinessIdentity::forCurrent()->toArray(),
        ]);
    }

    public function show(PurchaseOrder $purchaseOrder): Response
    {
        $purchaseOrder->load([
            'supplier',
            'warehouse:id,name',
            'createdBy:id,name',
            'approvalRequest.requestedBy:id,name',
            'approvalRequest.reviewedBy:id,name',
            // ⚠⚠ The UNIT comes down with the line, and for a converted product
            // it is the PURCHASE unit. Mustard oil is ordered, received, invoiced
            // and returned in kilograms while the warehouse holds litres, and a
            // clerk typing 109 (the litres delivered) where 100 (the kilograms
            // invoiced) belongs stocks 9% too much at a 9% low cost, with every
            // number downstream balancing. The screen has to say which.
            // ⚠ `tracks_expiry` and the warning threshold ride along so the
            // Receive goods step can mark batch and expiry required, and warn
            // on a short shelf life, BEFORE the server refuses (the server
            // still decides: ReceivedBatchDates runs again in receive()).
            // ⭐ Phase 2 / 1B: `pricing_mode` so the Receive step shows a "Set
            // today's price" cell on a market product's line, with the batch
            // cost and previous price computed below (marketProposals); the receiver
            // types the price (18 Oct 2026: no rule columns, no suggestion).
            // ⚠ 18 Oct 2026: `business_id` too, or currentMarketPrice() matched
            // nothing and the previous price never showed on the receipt.
            'lines.product:id,business_id,name,sku,type,unit_of_measure_id,purchase_unit_id,tracks_expiry,shelf_life_warning_days,pricing_mode,category_id',
            'lines.product.unitOfMeasure:id,abbreviation',
            'lines.product.purchaseUnit:id,abbreviation',
            'goodsReceipts.receivedBy:id,name',
            'goodsReceipts.lines.purchaseOrderLine:id,product_id',
            'goodsReceipts.lines.purchaseOrderLine.product:id,name,sku,pricing_mode,unit_of_measure_id',
            'goodsReceipts.lines.purchaseOrderLine.product.unitOfMeasure:id,abbreviation',
            'goodsReceipts.lines.marketPriceHistory:id,new_price,cost_snapshot,confirmed_at',
            // The batch each receipt line created: its label data is shown in
            // the receipt history, read from where FEFO reads it.
            'goodsReceipts.lines.stockMovement:id,batch_id',
            'goodsReceipts.lines.stockMovement.batch:id,batch_number,manufactured_date,expiry_date',
            // ⭐ Landed costs on each delivery (Phase 1, 17 Oct 2026).
            'goodsReceipts.landedCosts:id,goods_receipt_id,number,status,total_paisa,incurred_on',
            'goodsReceipts.landedCosts.approvalRequest',
            'returns.returnedBy:id,name',
            'returns.lines.purchaseOrderLine:id,product_id',
            'returns.lines.purchaseOrderLine.product:id,name,sku',
            ...PurchaseOrderBilling::eagerLoad(),
            // ⭐ Purchase redesign PR1: the full page's fields.
            'buyer:id,name',
            'lines.unit:id,abbreviation',
            'charges.supplier:id,name',
            'charges.moneyAccount:id,name',
            'charges.orderLine.product:id,name',
            'paymentSchedule',
            'attachments.uploadedBy:id,name',
        ]);

        $user = request()->user();
        $canApprove = $user->can('purchasing.po.approve');

        $receipts = $purchaseOrder->goodsReceipts->map(fn ($receipt) => [
            'id' => $receipt->id,
            'received_by' => $receipt->receivedBy?->name,
            // ⚠ Formatted here, in the app's Asia/Dhaka clock. The datetime cast
            // serialises in UTC, which put a 9 a.m. delivery at 3 a.m.
            'received_at' => $receipt->received_at?->format($receipt->received_at->format('H:i') === '00:00' ? 'Y-m-d' : 'Y-m-d H:i'),
            'supplier_challan_number' => $receipt->supplier_challan_number,
            'note' => $receipt->note,
            'landed_costs' => $receipt->landedCosts->map(fn ($lc) => [
                'id' => $lc->id,
                'number' => $lc->number,
                'status' => $lc->isAwaitingApproval() ? 'waiting' : $lc->status->value,
                'total' => Money::ofMinor((int) $lc->total_paisa)->toTaka(),
                'incurred_on' => $lc->incurred_on?->toDateString(),
            ])->values(),
            'lines' => $receipt->lines->map(fn ($line) => [
                'id' => $line->id,
                'product' => $line->purchaseOrderLine?->product?->name,
                'sku' => $line->purchaseOrderLine?->product?->sku,
                'quantity_received' => (string) $line->quantity_received,
                'batch_id' => $line->stockMovement?->batch_id,
                'batch_number' => $line->stockMovement?->batch?->batch_number,
                // ⚠ toDateString(), never the date cast: a Dhaka midnight
                // serialised in UTC is 18:00 the day before.
                'manufactured_date' => $line->stockMovement?->batch?->manufactured_date?->toDateString(),
                'expiry_date' => $line->stockMovement?->batch?->expiry_date?->toDateString(),
                // ⭐ Phase 2 / 1B: on a market product's line, whether today's
                // price was confirmed from THIS lot. Unconfirmed stays visible
                // for ever, with a button to confirm it now.
                'product_id' => $line->purchaseOrderLine?->product?->id,
                'unit' => $line->purchaseOrderLine?->product?->unitOfMeasure?->abbreviation,
                'pricing_mode' => $line->purchaseOrderLine?->product?->pricing_mode,
                'market_price_status' => $line->market_price_status,
                'market_price' => $line->marketPriceHistory === null ? null : (string) $line->marketPriceHistory->new_price,
                'market_price_at' => $line->marketPriceHistory?->confirmed_at?->format('H:i'),
            ])->values(),
        ])->values();

        // ⭐ Phase 2 / 1B (17 Oct 2026): what the Receive step proposes for each
        // market product's line, from the order line's cost per stock unit as
        // it stands. The action prices from the batch it actually creates, so
        // this is the screen's preview; the server decides on confirm.
        $marketProposals = [];

        foreach ($purchaseOrder->lines as $line) {
            if ($line->product === null || ! $line->product->isMarketPriced() || ! $line->product->type->isStocked()) {
                continue;
            }

            $remaining = (float) $line->quantityRemaining() > 0 ? $line->quantityRemaining() : (string) $line->quantity_ordered;

            try {
                $converted = $this->conversion->handle(
                    product: $line->product,
                    purchaseQuantity: $remaining,
                    purchaseUnitCost: (string) $line->unit_cost,
                    on: now(),
                    purchaseValue: $line->valueOf($remaining),
                    unitCostFromValue: $line->entry_mode === PurchaseEntryMode::Total,
                );
            } catch (\Throwable) {
                // A missing unit conversion refuses the receipt itself; the
                // proposal simply stays away.
                continue;
            }

            $marketProposals[$line->id] = ProductPricing::proposal($line->product, $converted['unitCost']);
        }

        $bills = $purchaseOrder->bills->map(fn (SupplierBill $bill) => [
            'id' => $bill->id,
            'bill_number' => $bill->bill_number,
            'bill_date' => $bill->bill_date?->toDateString(),
            'total' => Money::ofTaka((string) $bill->total)->toTaka(),
            'outstanding' => PurchaseOrderBilling::outstandingOf($bill)->toTaka(),
            'is_reversed' => $bill->isReversed(),
        ])
            // ⭐ Ruling P5: a bill covering this order and others, by its share here.
            ->concat(PurchaseOrderBilling::sharedBills($purchaseOrder)->map(fn (SupplierBill $bill) => [
                'id' => $bill->id,
                'bill_number' => $bill->bill_number,
                'bill_date' => $bill->bill_date?->toDateString(),
                'total' => PurchaseOrderBilling::shareOf($bill, $purchaseOrder)->toTaka(),
                'outstanding' => $bill->outstanding()->toTaka(),
                'is_reversed' => false,
                'shared' => true,
            ]))
            ->values();

        $billing = PurchaseOrderBilling::summarise($purchaseOrder);

        $purchaseOrder->unsetRelation('goodsReceipts')->unsetRelation('bills')->makeHidden('payment_status');

        return Inertia::render('purchasing/purchase-orders/show', [
            'order' => $purchaseOrder,
            // ⭐ Ruling P6: the terms this order's bills default to, and whether
            // they are the order's own or the supplier's.
            'terms' => [
                'effective' => PaymentTerms::forOrder($purchaseOrder, $purchaseOrder->supplier)?->toArray(),
                'own' => PaymentTerms::of($purchaseOrder->payment_terms_type, $purchaseOrder->payment_terms_days) !== null,
            ],
            'receipts' => $receipts,
            'bills' => $bills,
            'billing' => $billing,
            'marketProposals' => (object) $marketProposals,
            // ⭐ 18 Oct 2026 (Joy, D-E): whether this person may set a price
            // BELOW the batch cost (a markdown, with a reason). The receiver may
            // type any price at or above cost.
            'can_mark_down' => $user->can(ProductPricing::MARKDOWN_PERMISSION),
            // What each product last arrived at on ANOTHER order, beside this
            // order's own agreed cost.
            'last_costs' => (object) LastReceivedCost::forProducts(
                $purchaseOrder->lines->pluck('product_id')->unique()->values()->all(),
                excludingOrderId: $purchaseOrder->id,
            ),
            // The storekeeper's date, for the date pickers' limits. The server
            // still decides; the limit only saves a round trip.
            'today' => now()->toDateString(),
            'can_approve' => $canApprove,
            'can_receive' => $user->can('purchasing.receipt.record'),
            // Landed costs: read the tab, or add one from the delivery (17 Oct 2026).
            'can_view_landed_costs' => $user->can('purchasing.landed_cost.view'),
            'can_manage_landed_costs' => $user->can('purchasing.landed_cost.manage'),
            // ⚠ The next step of the chain, offered only when there is something
            // to bill: received, not returned, not already invoiced. The bill
            // form opens prefilled with exactly those quantities at the agreed
            // price (SupplierBillController::create).
            'can_record_bill' => $user->can('purchasing.bill.record')
                && $purchaseOrder->lines->contains(fn (PurchaseOrderLine $line) => (float) $line->billableQuantity() > 0),
            'can_record_payment' => $user->can('purchasing.payment.record'),
            // ⭐ Supplier advances (ruling P2): paid / applied / open on this order.
            'advances' => SupplierAdvanceBook::forPurchaseOrder($purchaseOrder),
            'can_record_advance' => $user->can('purchasing.advance.record') && $purchaseOrder->status !== PurchaseOrderStatus::Cancelled,
            'can_return' => $canApprove,
            'can_cancel' => $canApprove
                && $purchaseOrder->status !== PurchaseOrderStatus::Cancelled
                && ($purchaseOrder->status === PurchaseOrderStatus::Open || $purchaseOrder->hasNoOutstandingReceivedStock()),
            // The record's own story (Module 1, Slice 4).
            'timeline' => RecordTimeline::for($purchaseOrder),
            // ⭐ Purchase redesign PR1 (18 Oct 2026).
            'full' => $this->fullFigures($purchaseOrder),
            'can_edit' => $user->can('purchasing.po.create') && $purchaseOrder->isEditable(),
            'can_attach' => $user->can('purchasing.po.create'),
        ]);
    }

    /**
     * ⭐ PR1: the order as the full page saved it, for the show page and the
     * print: each line's typed unit, discount and VAT, the totals as stored,
     * the charges with how much of each has reached a receipt, the payment
     * plan, the advances paid against it and what is still due.
     *
     * ⚠ An order saved before the full page has none of these: its lines
     * read as typed (no discount, no VAT) and its total is its lines.
     *
     * @return array<string, mixed>
     */
    private function fullFigures(PurchaseOrder $order): array
    {
        $taka = fn (int $paisa) => Money::ofMinor($paisa)->toTaka();
        $advances = SupplierAdvanceBook::forPurchaseOrder($order);
        $grand = $order->grandTotal();
        $billed = PurchaseOrderBilling::summarise($order);

        return [
            'supplier_reference' => $order->supplier_reference,
            'buyer' => $order->buyer?->name,
            'terms_text' => $order->terms_text,
            'vat_in_cost' => (bool) $order->vat_in_cost,
            'discount_type' => $order->discount_type,
            'discount_value' => $order->discount_value === null ? null : rtrim(rtrim((string) $order->discount_value, '0'), '.'),
            'goods' => $taka((int) ($order->goods_paisa ?: $order->lines->sum('line_total_paisa'))),
            'discount' => $taka((int) $order->discount_paisa),
            'tax' => Money::ofTaka((string) ($order->tax_total ?? '0'))->toTaka(),
            'charges_total' => $taka((int) $order->charges_paisa),
            'supplier_charges' => $taka((int) $order->supplier_charges_paisa),
            'rounding' => $taka((int) $order->rounding_paisa),
            'grand_total' => $grand->toTaka(),
            'advance_paid' => $advances['paid'],
            // What the supplier is still owed on this order, before any bill:
            // the total less the advances paid against it.
            'balance_due' => Money::ofMinor($grand->minor - Money::ofTaka($advances['paid'])->minor)->toTaka(),
            'billed' => $billed,
            'lines' => $order->lines->sortBy('id')->values()->map(fn (PurchaseOrderLine $line) => [
                'id' => $line->id,
                'description' => $line->description,
                'unit' => $line->unit?->abbreviation,
                'entered_quantity' => $line->entered_quantity === null ? null : (string) $line->entered_quantity,
                'entered_rate' => $line->entered_rate === null ? null : (string) $line->entered_rate,
                'gross' => $taka((int) ($line->gross_paisa ?: $line->line_total_paisa)),
                'discount_label' => $line->discount_type === 'percent' ? rtrim(rtrim((string) $line->discount_value, '0'), '.').'%' : null,
                'discount' => $line->totalDiscount()->toTaka(),
                'tax_rate' => rtrim(rtrim((string) $line->tax_rate, '0'), '.') ?: '0',
                'tax' => Money::ofTaka((string) ($line->tax_amount ?? '0'))->toTaka(),
                'net' => $taka((int) ($line->gross_paisa ?: $line->line_total_paisa) - $line->totalDiscount()->minor + Money::ofTaka((string) ($line->tax_amount ?? '0'))->minor),
            ])->all(),
            'charges' => $order->charges->map(fn ($charge) => [
                'id' => $charge->id,
                'type' => $charge->cost_type->value,
                'type_label' => $charge->cost_type->label(),
                'description' => $charge->description,
                'amount' => $charge->money()->toTaka(),
                'into_cost' => $charge->effectiveTreatment()->value === 'capitalize',
                'basis' => $charge->allocation_method->value,
                'basis_label' => $charge->allocation_method->label(),
                'item' => $charge->orderLine?->product?->name,
                'payee' => $charge->moneyAccount?->name ?? $charge->supplier?->name,
                'paid_from_account' => $charge->money_account_id !== null,
                'landed' => $taka((int) $charge->landed_paisa),
                'remaining' => $charge->remaining()->toTaka(),
            ])->values()->all(),
            'schedule' => $order->paymentSchedule->map(fn ($row) => [
                'due_on' => $row->due_on?->toDateString(),
                'amount' => $row->money()->toTaka(),
                'note' => $row->note,
            ])->values()->all(),
            'attachments' => $order->attachments->map(fn ($file) => [
                'id' => $file->id,
                'name' => $file->original_name ?? $file->file_name,
                'uploaded_by' => $file->uploadedBy?->name,
                'url' => route('attachments.show', $file->id),
            ])->values()->all(),
        ];
    }

    /**
     * ⭐ Purchase redesign PR1 (18 Oct 2026, Joy: "when I click New purchase
     * order a new page comes with all the important fields"). The full page,
     * Tally's purchase voucher order: supplier, items with discount and VAT,
     * the charges beside the goods, rounding, the supplier's total, and what
     * is paid now. Every default the page can fill, it fills
     * (PurchaseOrderForm).
     */
    public function create(Request $request): Response
    {
        $this->authorize('purchasing.po.create');

        return Inertia::render('purchasing/purchase-orders/form', PurchaseOrderForm::props($request->user()));
    }

    /** ⭐ PR1: the same page on an order nothing has happened to yet. */
    public function edit(Request $request, PurchaseOrder $purchaseOrder): Response|RedirectResponse
    {
        $this->authorize('purchasing.po.create');

        $purchaseOrder->load('lines');

        if (! $purchaseOrder->isEditable()) {
            return redirect()->route('purchasing.purchase-orders.show', $purchaseOrder)
                ->with('error', "{$purchaseOrder->po_number} can no longer be edited: goods have been received or billed against it, or it is closed.");
        }

        return Inertia::render('purchasing/purchase-orders/form', PurchaseOrderForm::props($request->user(), $purchaseOrder));
    }

    /**
     * Saves the order, and on the same page whatever the switches say:
     *
     *   nothing          a purchase order, as always (plus an advance when
     *                    "Pay now" carries an amount: SADV, linked to it);
     *   Goods received   the order and its goods receipt, with the order's
     *                    charges raised as the receipt's landed cost;
     *   + Bill now       the one-screen purchase (RecordQuickPurchase):
     *                    order, receipt, landed cost, bill with the order's
     *                    discount and VAT, and "Pay now" paying that bill.
     *
     * ⚠ Each step keeps its own permission and approval rules; the switches
     * are a shorter path, never a way round them.
     */
    public function store(Request $request, CreatePurchaseOrder $action): RedirectResponse
    {
        $this->authorize('purchasing.po.create');

        $data = $this->validatedOrder($request, withSwitches: true);
        [$supplier, $warehouse, $lines, $details] = $this->orderInput($data);

        $receiveNow = (bool) ($data['receive_now'] ?? false);
        $billNow = $receiveNow && (bool) ($data['bill_now'] ?? false);
        $payNow = (bool) ($data['pay_now'] ?? false) && (float) ($data['payment_amount'] ?? 0) > 0;

        if ($billNow) {
            return $this->storeDirect($request, $data, $supplier, $warehouse, $lines, $details, $payNow);
        }

        $warnings = [];

        try {
            $order = DB::transaction(function () use ($action, $request, $data, $supplier, $warehouse, $lines, $details, $receiveNow, &$warnings) {
                $order = $action->handle(
                    supplier: $supplier,
                    warehouse: $warehouse,
                    lines: $lines,
                    createdBy: $request->user(),
                    expectedDate: $data['expected_date'] ?? null,
                    note: $data['note'] ?? null,
                    orderDate: $data['order_date'],
                    terms: PaymentTerms::of($data['payment_terms_type'] ?? null, $data['payment_terms_days'] ?? null),
                    details: $details,
                );

                if ($receiveNow) {
                    $warnings = [...$warnings, ...$this->receiveWholeOrder($order, $data, $request)];
                }

                return $order;
            });
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            throw ValidationException::withMessages(['lines' => RefusalMessage::of($e)]);
        }

        $this->attachFiles($request, $order);

        if ($payNow) {
            $warnings[] = $this->payAdvance($request, $data, $supplier, $order);
        }

        $response = redirect()->route('purchasing.purchase-orders.show', $order)->with('success', "Purchase order {$order->po_number} created.");
        $warnings = array_values(array_filter($warnings));

        return $warnings === [] ? $response : $response->with('warning', implode(' ', $warnings));
    }

    /** ⭐ PR1: an open order, changed on the full page (UpdatePurchaseOrder). */
    public function update(Request $request, PurchaseOrder $purchaseOrder, UpdatePurchaseOrder $action): RedirectResponse
    {
        $this->authorize('purchasing.po.create');

        $data = $this->validatedOrder($request, withSwitches: false);
        [$supplier, $warehouse, $lines, $details] = $this->orderInput($data);

        try {
            $order = $action->handle(
                order: $purchaseOrder,
                supplier: $supplier,
                warehouse: $warehouse,
                lines: $lines,
                by: $request->user(),
                expectedDate: $data['expected_date'] ?? null,
                note: $data['note'] ?? null,
                orderDate: $data['order_date'],
                terms: PaymentTerms::of($data['payment_terms_type'] ?? null, $data['payment_terms_days'] ?? null),
                details: $details,
            );
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            throw ValidationException::withMessages(['lines' => RefusalMessage::of($e)]);
        }

        $this->attachFiles($request, $order);

        return redirect()->route('purchasing.purchase-orders.show', $order)->with('success', "Purchase order {$order->po_number} updated.");
    }

    /** ⭐ PR1: a file on the order after it was saved (a quotation, a Mushak 6.3). */
    public function storeAttachment(Request $request, PurchaseOrder $purchaseOrder, StoreAttachment $store): RedirectResponse
    {
        $this->authorize('purchasing.po.create');

        $request->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,csv,xls,xlsx,doc,docx', 'max:5120']]);

        $store->handle($purchaseOrder, $request->file('file'), PurchaseOrder::ATTACHMENTS_COLLECTION, $request->user());

        return back()->with('success', "File attached to {$purchaseOrder->po_number}.");
    }

    /** ⚠ Removing a file is allowed but logged (DeleteAttachment); the order stays. */
    public function destroyAttachment(PurchaseOrder $purchaseOrder, Attachment $attachment, DeleteAttachment $delete): RedirectResponse
    {
        $this->authorize('purchasing.po.create');

        abort_unless($attachment->attachable_type === $purchaseOrder->getMorphClass() && (int) $attachment->attachable_id === (int) $purchaseOrder->id, 404);

        $delete->handle($attachment);

        return back()->with('success', 'File removed.');
    }

    /**
     * The full page's fields. The older list-page form sent a subset of these
     * and still validates (every new field is optional).
     *
     * @return array<string, mixed>
     */
    private function validatedOrder(Request $request, bool $withSwitches): array
    {
        $businessId = app(BusinessContext::class)->id();
        $scoped = fn (string $table) => Rule::exists($table, 'id')->where('business_id', $businessId);

        $rules = [
            'supplier_id' => ['required', 'integer', $scoped('suppliers')],
            'warehouse_id' => ['required', 'integer', $scoped('warehouses')],
            // ⭐ D-G (Joy, 18 Oct 2026): the day the order was placed, today by
            // default, an earlier day when it is entered late. Never the future.
            'order_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'expected_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
            // ⭐ PR1: the supplier's quotation or reference number, the buyer,
            // terms and conditions.
            'supplier_reference' => ['nullable', 'string', 'max:100'],
            'buyer_id' => ['nullable', 'integer', 'exists:users,id'],
            'terms_text' => ['nullable', 'string', 'max:5000'],
            'discount_type' => ['nullable', 'in:percent,amount'],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'rounding' => ['nullable', 'numeric'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', $scoped('products')],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.unit_id' => ['nullable', 'integer', $scoped('units_of_measure')],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            // ⭐ 17 Oct 2026 (Phase 1 part A): EITHER the unit price OR the
            // line total is typed; `entry_mode` says which when both arrive
            // (the form sends both, the derived one for display only). A
            // request with only `unit_cost` is a rate entry, as before.
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.line_total' => ['nullable', 'numeric', 'min:0'],
            'lines.*.entry_mode' => ['nullable', 'in:rate,total'],
            'lines.*.discount_type' => ['nullable', 'in:percent,amount'],
            'lines.*.discount_value' => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'lt:100'],
            'charges' => ['nullable', 'array'],
            'charges.*.cost_type' => ['required', Rule::in(PurchaseOrderCharges::ORDER_TYPES)],
            'charges.*.description' => ['nullable', 'string', 'max:255'],
            'charges.*.amount' => ['required', 'numeric', 'gt:0'],
            'charges.*.allocation_method' => ['nullable', 'in:value,quantity,weight,direct'],
            'charges.*.line_index' => ['nullable', 'integer', 'min:0'],
            // this = owed to this order's supplier; supplier = another one
            // (the transporter); money = paid from a money account.
            'charges.*.payee' => ['required', 'in:this,supplier,money'],
            'charges.*.supplier_id' => ['nullable', 'integer', $scoped('suppliers')],
            'charges.*.money_account_id' => ['nullable', 'integer', $scoped('money_accounts')],
            'charges.*.treatment' => ['nullable', 'in:capitalize,expense'],
            'charges.*.override_reason' => ['nullable', 'string', 'max:500'],
            'charges.*.expense_account_id' => ['nullable', 'integer', $scoped('accounts')],
            'schedule' => ['nullable', 'array'],
            'schedule.*.due_on' => ['required', 'date_format:Y-m-d'],
            'schedule.*.amount' => ['required', 'numeric', 'gt:0'],
            'schedule.*.note' => ['nullable', 'string', 'max:255'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'mimes:pdf,jpg,jpeg,png,webp,csv,xls,xlsx,doc,docx', 'max:5120'],
            // ⭐ Ruling P6: this order's own terms; blank means the supplier's.
            ...PaymentTerms::rules(),
        ];

        if ($withSwitches) {
            $rules += [
                // ⭐ PR1: the short path, on the same page.
                'receive_now' => ['nullable', 'boolean'],
                'bill_now' => ['nullable', 'boolean'],
                'pay_now' => ['nullable', 'boolean'],
                'request_key' => ['nullable', 'string', 'max:64'],
                'challan_number' => ['nullable', 'string', 'max:100'],
                'backdate_reason' => ['nullable', 'string', 'max:500'],
                'bill_number' => ['nullable', 'required_if_accepted:bill_now', 'string', 'max:100'],
                'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:order_date'],
                'due_date_reason' => ['nullable', 'string', 'max:500'],
                'duplicate_reason' => ['nullable', 'string', 'max:1000'],
                'lines.*.batch_number' => ['nullable', 'string', 'max:100'],
                'lines.*.manufactured_date' => ['nullable', 'date_format:Y-m-d'],
                'lines.*.expiry_date' => ['nullable', 'date_format:Y-m-d'],
                'payment_money_account_id' => ['nullable', 'required_if_accepted:pay_now', 'integer', $scoped('money_accounts')],
                'payment_amount' => ['nullable', 'numeric', 'min:0'],
                'payment_reference' => ['nullable', 'string', 'max:255'],
                'excess_to_advance' => ['nullable', 'boolean'],
            ];
        }

        $data = $request->validate($rules, [
            'order_date.before_or_equal' => 'The order date cannot be in the future: an order is dated the day it was placed.',
            'bill_number.required_if_accepted' => "Type the number on the supplier's invoice or cash memo.",
            'payment_money_account_id.required_if_accepted' => 'Pick the account the money is paid from.',
            'due_date.after_or_equal' => 'The due date cannot be before the order date.',
            ...PaymentTerms::messages(),
        ], [
            'order_date' => 'order date',
            'supplier_reference' => 'supplier reference',
            'terms_text' => 'terms and conditions',
            'discount_value' => 'discount',
            'lines.*.product_id' => 'product',
            'lines.*.quantity' => 'quantity',
            'lines.*.unit_cost' => 'unit price',
            'lines.*.line_total' => 'line total',
            'lines.*.discount_value' => 'discount',
            'lines.*.tax_rate' => 'VAT rate',
            'charges.*.amount' => 'amount',
            'schedule.*.due_on' => 'due date',
            'schedule.*.amount' => 'amount',
            'payment_amount' => 'amount paid',
        ]);

        // ⚠ Each refusal under the box it is about: "type the unit price" lands
        // on that line's price box, not in one message at the bottom. The
        // action resolves the same figures again; this pass is for the typist.
        $problems = [];

        foreach ($data['lines'] as $index => $line) {
            foreach (PurchaseLineEntry::problems((string) $line['quantity'], $line['unit_cost'] ?? null, $line['line_total'] ?? null, $line['entry_mode'] ?? null) as $field => $problem) {
                $problems["lines.{$index}.{$field}"] = $problem;
            }
        }

        // ⚠ A day inside closed books lands under the date box, in the period
        // check's own words (it names the period). The action checks again.
        try {
            CreatePurchaseOrder::resolveOrderDate($data['order_date']);
        } catch (RuntimeException|InvalidArgumentException $e) {
            $problems['order_date'] = RefusalMessage::of($e);
        }

        if ($withSwitches && ($data['receive_now'] ?? false) && $data['order_date'] < now()->toDateString() && trim((string) ($data['backdate_reason'] ?? '')) === '') {
            $problems['backdate_reason'] = 'The goods are received with an earlier date: say why it is being entered now.';
        }

        if ($withSwitches && ($data['receive_now'] ?? false)) {
            foreach ($data['lines'] as $index => $line) {
                $product = Product::find($line['product_id']);

                foreach (ReceivedBatchDates::problems($line['manufactured_date'] ?? null, $line['expiry_date'] ?? null) as $field => $problem) {
                    $problems["lines.{$index}.{$field}"] = $problem;
                }

                foreach (ReceivedBatchDates::required((bool) $product?->tracks_expiry, $line['batch_number'] ?? null, $line['expiry_date'] ?? null) as $field => $problem) {
                    $problems["lines.{$index}.{$field}"] ??= $problem;
                }
            }

            if (($refusal = SupplierChallans::refusal((int) $data['supplier_id'], $data['challan_number'] ?? null, Supplier::find($data['supplier_id'])?->name ?? 'this supplier')) !== null) {
                $problems['challan_number'] = $refusal;
            }
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }

        return $data;
    }

    /**
     * The validated request as CreatePurchaseOrder takes it.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: Supplier, 1: Warehouse, 2: list<array<string, mixed>>, 3: array<string, mixed>}
     */
    private function orderInput(array $data): array
    {
        $supplier = Supplier::findOrFail($data['supplier_id']);

        $lines = collect($data['lines'])->map(fn (array $line) => [
            'product' => Product::findOrFail($line['product_id']),
            'quantity' => (string) $line['quantity'],
            'unit_cost' => $line['unit_cost'] ?? null,
            'line_total' => $line['line_total'] ?? null,
            'entry_mode' => $line['entry_mode'] ?? null,
            'description' => $line['description'] ?? null,
            'unit' => empty($line['unit_id']) ? null : UnitOfMeasure::findOrFail($line['unit_id']),
            'discount_type' => $line['discount_type'] ?? null,
            'discount_value' => isset($line['discount_value']) ? (string) $line['discount_value'] : null,
            // ⚠ Always the key, even blank: blank means the product's tax class rate.
            'tax_rate' => isset($line['tax_rate']) ? (string) $line['tax_rate'] : '',
            'batch_number' => $line['batch_number'] ?? null,
            'manufactured_date' => $line['manufactured_date'] ?? null,
            'expiry_date' => $line['expiry_date'] ?? null,
        ])->values()->all();

        $details = [
            'supplier_reference' => $data['supplier_reference'] ?? null,
            'buyer_id' => isset($data['buyer_id']) ? (int) $data['buyer_id'] : null,
            'terms_text' => $data['terms_text'] ?? null,
            'discount' => ['type' => $data['discount_type'] ?? null, 'value' => isset($data['discount_value']) ? (string) $data['discount_value'] : null],
            'rounding' => isset($data['rounding']) ? (string) $data['rounding'] : null,
            'charges' => collect($data['charges'] ?? [])->map(fn (array $charge) => [
                'cost_type' => $charge['cost_type'],
                'description' => $charge['description'] ?? null,
                'amount' => (string) $charge['amount'],
                'allocation_method' => $charge['allocation_method'] ?? 'value',
                'line_index' => $charge['line_index'] ?? null,
                'supplier_id' => match ($charge['payee']) {
                    'this' => $supplier->id,
                    'supplier' => $charge['supplier_id'] ?? null,
                    default => null,
                },
                'money_account_id' => $charge['payee'] === 'money' ? ($charge['money_account_id'] ?? null) : null,
                'treatment' => $charge['treatment'] ?? null,
                'override_reason' => $charge['override_reason'] ?? null,
                'expense_account_id' => $charge['expense_account_id'] ?? null,
            ])->values()->all(),
            'schedule' => array_values($data['schedule'] ?? []),
        ];

        return [$supplier, Warehouse::findOrFail($data['warehouse_id']), $lines, $details];
    }

    /**
     * "Goods received now" without a bill: every line in full, with its
     * batch and dates, on the order date. An order waiting for approval
     * cannot be received yet, and says so.
     *
     * @param  array<string, mixed>  $data
     * @return list<string> warnings
     */
    private function receiveWholeOrder(PurchaseOrder $order, array $data, Request $request): array
    {
        $order = $order->fresh(['approvalRequest', 'lines', 'supplier', 'warehouse']);

        if (! $order->isReceivable()) {
            return ["{$order->po_number} waits for approval, so the goods were not received yet. Receive them from the order once it is approved."];
        }

        $byIndex = array_values($data['lines']);

        $receipt = app(RecordGoodsReceipt::class)->handle(
            order: $order,
            lineReceipts: $order->lines->sortBy('id')->values()->map(fn (PurchaseOrderLine $line, int $index) => [
                'line' => $line,
                'quantity' => (string) $line->quantity_ordered,
                'batch_number' => $byIndex[$index]['batch_number'] ?? null,
                'manufactured_date' => $byIndex[$index]['manufactured_date'] ?? null,
                'expiry_date' => $byIndex[$index]['expiry_date'] ?? null,
            ])->all(),
            receivedBy: $request->user(),
            note: $data['note'] ?? null,
            supplierChallanNumber: $data['challan_number'] ?? null,
            receivedOn: $data['order_date'],
            backdateReason: $data['backdate_reason'] ?? null,
        );

        return RaiseOrderCharges::notesFor($receipt->id);
    }

    /**
     * "Pay now" with no bill yet: a supplier advance (SADV) linked to the
     * order, through RecordSupplierAdvance, dated today. Over the approval
     * limit it is saved as a PA- row and posts when approved. A refusal never
     * takes the order back with it: the order is saved, the sentence says
     * what did not happen.
     *
     * @param  array<string, mixed>  $data
     */
    private function payAdvance(Request $request, array $data, Supplier $supplier, PurchaseOrder $order): ?string
    {
        if (! $request->user()->can('purchasing.advance.record')) {
            return 'The advance was not recorded: you may not pay suppliers in advance.';
        }

        try {
            $advance = app(RecordSupplierAdvance::class)->pay(
                supplier: $supplier,
                moneyAccount: MoneyAccount::findOrFail($data['payment_money_account_id']),
                paidOn: now()->toDateString(),
                amount: (string) $data['payment_amount'],
                createdBy: $request->user(),
                purchaseOrder: $order,
                reference: $data['payment_reference'] ?? null,
                note: "Advance on {$order->po_number}",
            );
        } catch (PurchaseApprovalRequired $e) {
            $approval = app(PurchasingApprovalGate::class)->hold($e, $request->user());

            return "The advance needs a second person's approval, so it is saved as {$approval->number()} and posts when they approve.";
        } catch (ValidationException $e) {
            return 'The advance was not recorded: '.collect($e->errors())->flatten()->first();
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            return 'The advance was not recorded: '.RefusalMessage::of($e);
        }

        session()->flash('success', "Purchase order {$order->po_number} created, and advance {$advance->number} paid.");

        return null;
    }

    /**
     * Order, receipt, bill (and payment) on one page: RecordQuickPurchase,
     * with the full page's details. Its own approval holds apply.
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $details
     */
    private function storeDirect(Request $request, array $data, Supplier $supplier, Warehouse $warehouse, array $lines, array $details, bool $payNow): RedirectResponse
    {
        if (! $request->user()->can('purchasing.quick.purchase')) {
            throw ValidationException::withMessages(['bill_now' => 'Billing on this page is the one-screen purchase, which your role may not use. Save the order and record the bill from it.']);
        }

        $payment = $payNow ? [
            'money_account' => MoneyAccount::findOrFail($data['payment_money_account_id']),
            'amount' => (string) $data['payment_amount'],
            'reference' => $data['payment_reference'] ?? null,
            'excess_to_advance' => (bool) ($data['excess_to_advance'] ?? false),
        ] : null;

        try {
            $quick = app(RecordQuickPurchase::class)->handle(
                requestKey: $data['request_key'] ?? (string) Str::uuid(),
                supplier: $supplier,
                warehouse: $warehouse,
                purchasedOn: $data['order_date'],
                lines: $lines,
                billNumber: trim((string) $data['bill_number']),
                by: $request->user(),
                challanNumber: $data['challan_number'] ?? null,
                dueDate: $data['due_date'] ?? null,
                note: $data['note'] ?? null,
                backdateReason: $data['backdate_reason'] ?? null,
                payment: $payment,
                dueDateReason: $data['due_date_reason'] ?? null,
                duplicateReason: $data['duplicate_reason'] ?? null,
                orderDetails: $details + ['terms' => PaymentTerms::of($data['payment_terms_type'] ?? null, $data['payment_terms_days'] ?? null)],
            );
        } catch (AuthorizationException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        } catch (LikelyDuplicateBill $e) {
            throw ValidationException::withMessages(['duplicate' => $e->getMessage()]);
        } catch (ValidationException $e) {
            // The one-screen purchase names its own boxes; the page's are these.
            $messages = [];

            foreach ($e->errors() as $key => $list) {
                $messages[match (true) {
                    $key === 'purchased_on' => 'order_date',
                    str_starts_with($key, 'costs.') => 'charges.'.substr($key, 6),
                    default => $key,
                }] = $list;
            }

            throw ValidationException::withMessages($messages);
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            throw ValidationException::withMessages(['lines' => RefusalMessage::of($e).' Nothing was saved.']);
        }

        if ($quick->purchaseOrder !== null) {
            $this->attachFiles($request, $quick->purchaseOrder);
        }

        $message = match (true) {
            ! $quick->wasRecentlyCreated => 'This purchase was already saved; here it is.',
            $quick->isCompleted() => 'Saved: order, receipt'.($quick->landed_cost_id ? ', landed cost' : '').', bill'.($quick->supplier_payment_id ? ' and payment' : '').'.',
            default => 'Saved, and part of it waits for approval.',
        };

        $response = redirect()->route('purchasing.buy-and-receive.show', $quick)->with('success', $message);

        return $quick->held_reason ? $response->with('warning', $quick->held_reason) : $response;
    }

    /** The files sent with the page, onto the order. */
    private function attachFiles(Request $request, PurchaseOrder $order): void
    {
        foreach ((array) $request->file('attachments', []) as $file) {
            app(StoreAttachment::class)->handle($order, $file, PurchaseOrder::ATTACHMENTS_COLLECTION, $request->user());
        }
    }

    public function approve(Request $request, PurchaseOrder $purchaseOrder, ApprovePurchaseOrder $action): RedirectResponse
    {
        $this->authorize('purchasing.po.approve');

        // ⚠ A bare button. Two managers pressing approve, or one double-click,
        // is the ordinary way this refusal happens.
        try {
            $action->handle($purchaseOrder, $request->user());
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            return back()->with('error', RefusalMessage::of($e));
        }

        return back()->with('success', 'Purchase order approved.');
    }

    public function reject(Request $request, PurchaseOrder $purchaseOrder, RejectApprovable $action): RedirectResponse
    {
        $this->authorize('purchasing.po.approve');

        $data = $request->validate(['review_note' => ['nullable', 'string']]);

        try {
            $action->handle($purchaseOrder, $request->user(), $data['review_note'] ?? null);
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            return back()->with('error', RefusalMessage::of($e));
        }

        return back()->with('success', 'Purchase order rejected.');
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder, CancelPurchaseOrder $action): RedirectResponse
    {
        $this->authorize('purchasing.po.approve');

        // ⚠ "still has received stock on hand, return it before cancelling" is a
        // real business refusal, not an error. It must be readable.
        try {
            $action->handle($purchaseOrder, $request->user());
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            return back()->with('error', RefusalMessage::of($e));
        }

        return back()->with('success', 'Purchase order cancelled.');
    }

    public function receive(Request $request, PurchaseOrder $purchaseOrder, RecordGoodsReceipt $action): RedirectResponse
    {
        $this->authorize('purchasing.receipt.record');

        $data = $request->validate([
            'note' => ['nullable', 'string'],
            'supplier_challan_number' => ['nullable', 'string', 'max:100'],
            // ⭐ 26 Sep 2026 (Joy, Q11): an earlier received-on date, never the
            // future, with a reason when it is before today. The action re-checks
            // both and the period lock.
            'received_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'backdate_reason' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_line_id' => ['required', 'exists:purchase_order_lines,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
            // ⚠ All three optional: plenty of what is bought (cartons, labels,
            // jars) carries no batch or date at all. Whether a food product
            // MUST carry an expiry is an owner question, not a rule to guess.
            'lines.*.batch_number' => ['nullable', 'string', 'max:100'],
            'lines.*.manufactured_date' => ['nullable', 'date_format:Y-m-d'],
            'lines.*.expiry_date' => ['nullable', 'date_format:Y-m-d'],
            // ⭐ Phase 2 / 1B: today's price the receiver confirms for a market
            // product's line (blank = not confirmed, the old price stays), and
            // the reason when it goes under the minimum margin.
            'lines.*.market_price' => ['nullable', 'numeric', 'gt:0', 'decimal:0,4'],
            'lines.*.market_price_reason' => ['nullable', 'string', 'max:500'],
        ], [], [
            // ⚠ Named for a person. Without these the refusal reads
            // "The lines.2.expiry_date field must match the format Y-m-d."
            'supplier_challan_number' => 'supplier challan number',
            'received_on' => 'received on',
            'backdate_reason' => 'reason for the earlier date',
            'lines.*.quantity' => 'quantity',
            'lines.*.batch_number' => 'batch number',
            'lines.*.manufactured_date' => 'manufacture date',
            'lines.*.expiry_date' => 'expiry date',
            'lines.*.market_price' => "today's price",
            'lines.*.market_price_reason' => 'reason for the price',
        ]);

        // ⚠⚠ The label checks, per line, so each sentence lands under the box
        // it is about (`lines.2.expiry_date`) rather than in one message at the
        // bottom. The SAME class runs again inside RecordGoodsReceipt, which is
        // what actually protects the stock; this pass is for the person typing.
        // A line left at 0 is "not in this delivery" and is not checked.
        $purchaseOrder->load('lines.product');
        $dateProblems = [];
        $warnings = [];

        if (! empty($data['received_on']) && $data['received_on'] < now()->toDateString() && trim((string) ($data['backdate_reason'] ?? '')) === '') {
            $dateProblems['backdate_reason'] = 'This receipt is dated earlier than today: say why it is being entered now.';
        }

        foreach ($data['lines'] as $index => $entry) {
            if ((float) $entry['quantity'] <= 0) {
                continue;
            }

            foreach (ReceivedBatchDates::problems($entry['manufactured_date'] ?? null, $entry['expiry_date'] ?? null) as $field => $problem) {
                $dateProblems["lines.{$index}.{$field}"] = $problem;
            }

            // ⭐ 26 Sep 2026 (Joy, Q8/Q9): required where the product tracks
            // expiry; a short shelf life only warns. The action checks the
            // requirement again, because the screen is only one way in.
            $product = $purchaseOrder->lines->firstWhere('id', $entry['purchase_order_line_id'])?->product;

            if ($product !== null) {
                foreach (ReceivedBatchDates::required((bool) $product->tracks_expiry, $entry['batch_number'] ?? null, $entry['expiry_date'] ?? null) as $field => $problem) {
                    $dateProblems["lines.{$index}.{$field}"] ??= $problem;
                }

                if ($product->tracks_expiry && ($warning = ReceivedBatchDates::shelfLifeWarning($entry['expiry_date'] ?? null, $product->shelfLifeWarningDays())) !== null) {
                    $warnings[] = "{$product->name}: {$warning}";
                }

                // ⭐ Phase 2 / 1B: a typed market price is checked here against the
                // cost the screen proposed from, so the sentence lands under its
                // own box. ConfirmMarketPrice checks again against the batch it
                // actually creates; a blank is "not confirmed", warned below.
                if ($product->isMarketPriced() && trim((string) ($entry['market_price'] ?? '')) !== '') {
                    $proposal = $this->proposalForLine($purchaseOrder->lines->firstWhere('id', $entry['purchase_order_line_id']), (string) $entry['quantity']);

                    if ($proposal !== null && ($refusal = ProductPricing::refusalFor($product, (string) $entry['market_price'], $proposal['cost'], $entry['market_price_reason'] ?? null, $request->user())) !== null) {
                        $dateProblems["lines.{$index}.market_price"] = $refusal;
                    }
                }
            }
        }

        // ⭐ 26 Sep 2026 (Joy, Q10): a challan number this supplier already sent
        // is refused under its own box. The action refuses it again.
        $purchaseOrder->loadMissing('supplier');
        $challanRefusal = SupplierChallans::refusal((int) $purchaseOrder->supplier_id, $data['supplier_challan_number'] ?? null, $purchaseOrder->supplier?->name ?? 'this supplier');

        if ($challanRefusal !== null) {
            $dateProblems['supplier_challan_number'] = $challanRefusal;
        }

        if ($dateProblems !== []) {
            throw ValidationException::withMessages($dateProblems);
        }

        $purchaseOrder->load('lines');

        $lineReceipts = collect($data['lines'])
            ->map(fn (array $entry) => [
                'line' => $purchaseOrder->lines->firstWhere('id', $entry['purchase_order_line_id']),
                'quantity' => $entry['quantity'],
                'batch_number' => $entry['batch_number'] ?? null,
                'manufactured_date' => $entry['manufactured_date'] ?? null,
                'expiry_date' => $entry['expiry_date'] ?? null,
                'market_price' => $entry['market_price'] ?? null,
                'market_price_reason' => $entry['market_price_reason'] ?? null,
            ])
            ->filter(fn (array $entry) => $entry['line'] !== null)
            ->all();

        // ⚠⚠ THE DENSEST REFUSAL CLUSTER IN THE SYSTEM, and step 2 of the first
        // real morning. Receiving can be refused for over-receiving, for an
        // order not open, for an empty receipt, for a missing unit conversion,
        // for a closed period, and for a missing account mapping. Every one of
        // those was a blank 500. Over-receiving is the commonest of them: a
        // storekeeper types 50 where 20 remains.
        try {
            $receipt = $action->handle(
                $purchaseOrder,
                $lineReceipts,
                $request->user(),
                $data['note'] ?? null,
                $data['supplier_challan_number'] ?? null,
                $data['received_on'] ?? null,
                $data['backdate_reason'] ?? null,
            );
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            throw ValidationException::withMessages(['lines' => RefusalMessage::of($e)]);
        }

        // ⭐ Phase 2 / 1B: a market product received without its price confirmed
        // keeps the old price in force; say so, and the receipt line says so
        // for ever with a button to confirm it.
        $unconfirmed = $receipt->lines
            ->filter(fn ($line) => $line->market_price_status === ProductPricing::STATUS_UNCONFIRMED)
            ->map(fn ($line) => $purchaseOrder->lines->firstWhere('id', $line->purchase_order_line_id)?->product?->name)
            ->filter()
            ->values();

        if ($unconfirmed->isNotEmpty()) {
            $warnings[] = 'Price not confirmed for '.$unconfirmed->implode(', ').': the old price stays in force until today\'s price is confirmed from the receipt.';
        }

        // ⚠ D-G (18 Oct 2026): goods dated before the order was placed are
        // possible (a late-entered order, a wrong box), so this warns and
        // never refuses. Either date may be the one to correct.
        $orderDate = $purchaseOrder->order_date;

        if ($orderDate !== null && $receipt->received_at !== null && $receipt->received_at->toDateString() < $orderDate->toDateString()) {
            $warnings[] = "Received before the order date ({$orderDate->format('j M Y')}): check the dates.";
        }

        $response = back()->with('success', 'Goods receipt recorded.');

        return $warnings === [] ? $response : $response->with('warning', implode(' ', $warnings));
    }

    /**
     * The proposal the Receive step priced from for one line, at the quantity
     * being received (a line typed as a total prices its lot from the value's
     * share). Null when the product is not market-priced or cannot convert.
     *
     * @return array<string, mixed>|null
     */
    private function proposalForLine(?PurchaseOrderLine $line, string $quantity): ?array
    {
        if ($line === null || $line->product === null || ! $line->product->isMarketPriced() || (float) $quantity <= 0) {
            return null;
        }

        try {
            $converted = $this->conversion->handle(
                product: $line->product,
                purchaseQuantity: $quantity,
                purchaseUnitCost: (string) $line->unit_cost,
                on: now(),
                purchaseValue: $line->valueOf($quantity),
                unitCostFromValue: $line->entry_mode === PurchaseEntryMode::Total,
            );
        } catch (\Throwable) {
            return null;
        }

        return ProductPricing::proposal($line->product, $converted['unitCost']);
    }

    public function returnGoods(Request $request, PurchaseOrder $purchaseOrder, RecordPurchaseReturn $action): RedirectResponse
    {
        $this->authorize('purchasing.po.approve');

        $data = $request->validate([
            'note' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_line_id' => ['required', 'exists:purchase_order_lines,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
        ]);

        $purchaseOrder->load('lines');

        $lineReturns = collect($data['lines'])
            ->map(fn (array $entry) => [
                'line' => $purchaseOrder->lines->firstWhere('id', $entry['purchase_order_line_id']),
                'quantity' => $entry['quantity'],
            ])
            ->filter(fn (array $entry) => $entry['line'] !== null)
            ->all();

        // ⚠ Returning reverses stock, so on top of its own refusals it inherits
        // everything ReverseStockReceipt can raise, including insufficient stock
        // when the goods have already moved on.
        try {
            $action->handle($purchaseOrder, $lineReturns, $request->user(), $data['note'] ?? null);
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            throw ValidationException::withMessages(['lines' => RefusalMessage::of($e)]);
        }

        return back()->with('success', 'Purchase return recorded.');
    }
}
