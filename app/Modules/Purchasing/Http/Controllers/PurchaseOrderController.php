<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Actions\RejectApprovable;
use App\Modules\Foundation\Support\BusinessIdentity;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\ApprovePurchaseOrder;
use App\Modules\Purchasing\Actions\CancelPurchaseOrder;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Actions\RecordPurchaseReturn;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Support\LastReceivedCost;
use App\Modules\Purchasing\Support\PurchaseOrderBilling;
use App\Modules\Purchasing\Support\ReceivedBatchDates;
use App\Modules\Purchasing\Support\SupplierChallans;
use App\Support\RecordTimeline;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->string('search')->toString(),
            'status' => $request->string('status')->toString(),
        ];

        $orders = PurchaseOrder::with(['supplier:id,name', 'warehouse:id,name', 'approvalRequest', ...PurchaseOrderBilling::eagerLoad()])
            ->withSum('lines as total_ordered', 'quantity_ordered')
            ->when($filters['search'], fn ($query, $search) => $query->where('po_number', 'like', "%{$search}%"))
            ->when($filters['status'], fn ($query, $status) => $query->where('status', $status))
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

        $lastCosts = LastReceivedCost::forProducts();

        return Inertia::render('purchasing/purchase-orders/index', [
            'orders' => $orders,
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            // ⚠ The unit comes with the product so the quantity and cost boxes
            // can say which unit they mean (the PURCHASE unit when the product
            // is bought in another one), and the last received cost so a new
            // price is typed against the last real one, not from memory.
            'products' => Product::where('is_active', true)
                ->with(['unitOfMeasure:id,abbreviation', 'purchaseUnit:id,abbreviation'])
                ->orderBy('name')
                ->get(['id', 'name', 'sku', 'unit_of_measure_id', 'purchase_unit_id'])
                ->map(fn (Product $product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'unit' => $product->purchaseUnit?->abbreviation ?? $product->unitOfMeasure?->abbreviation,
                    'last_cost' => $lastCosts[$product->id] ?? null,
                ]),
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
        ]);

        $subtotal = $purchaseOrder->lines->reduce(
            fn (Money $carry, $line) => $carry->plus(Money::ofProduct((string) $line->quantity_ordered, (string) $line->unit_cost)),
            Money::zero(),
        );

        $tax = Money::ofTaka((string) ($purchaseOrder->tax_total ?? '0'));

        return Inertia::render('purchasing/purchase-orders/print', [
            'order' => $purchaseOrder,
            // ⚠ Totalled on the server from the same Money type the ledger uses.
            // A page that adds its own lines up is a page that can disagree with
            // the bill this order becomes.
            'subtotal' => $subtotal->toTaka(),
            'tax' => $tax->toTaka(),
            'total' => $subtotal->plus($tax)->toTaka(),
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
            'lines.product:id,name,sku,type,unit_of_measure_id,purchase_unit_id',
            'lines.product.unitOfMeasure:id,abbreviation',
            'lines.product.purchaseUnit:id,abbreviation',
            'goodsReceipts.receivedBy:id,name',
            'goodsReceipts.lines.purchaseOrderLine:id,product_id',
            'goodsReceipts.lines.purchaseOrderLine.product:id,name,sku',
            // The batch each receipt line created: its label data is shown in
            // the receipt history, read from where FEFO reads it.
            'goodsReceipts.lines.stockMovement:id,batch_id',
            'goodsReceipts.lines.stockMovement.batch:id,batch_number,manufactured_date,expiry_date',
            'returns.returnedBy:id,name',
            'returns.lines.purchaseOrderLine:id,product_id',
            'returns.lines.purchaseOrderLine.product:id,name,sku',
            ...PurchaseOrderBilling::eagerLoad(),
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
            'lines' => $receipt->lines->map(fn ($line) => [
                'id' => $line->id,
                'product' => $line->purchaseOrderLine?->product?->name,
                'sku' => $line->purchaseOrderLine?->product?->sku,
                'quantity_received' => (string) $line->quantity_received,
                'batch_number' => $line->stockMovement?->batch?->batch_number,
                // ⚠ toDateString(), never the date cast: a Dhaka midnight
                // serialised in UTC is 18:00 the day before.
                'manufactured_date' => $line->stockMovement?->batch?->manufactured_date?->toDateString(),
                'expiry_date' => $line->stockMovement?->batch?->expiry_date?->toDateString(),
            ])->values(),
        ])->values();

        $bills = $purchaseOrder->bills->map(fn (SupplierBill $bill) => [
            'id' => $bill->id,
            'bill_number' => $bill->bill_number,
            'bill_date' => $bill->bill_date?->toDateString(),
            'total' => Money::ofTaka((string) $bill->total)->toTaka(),
            'outstanding' => PurchaseOrderBilling::outstandingOf($bill)->toTaka(),
            'is_reversed' => $bill->isReversed(),
        ])->values();

        $billing = PurchaseOrderBilling::summarise($purchaseOrder);

        $purchaseOrder->unsetRelation('goodsReceipts')->unsetRelation('bills')->makeHidden('payment_status');

        return Inertia::render('purchasing/purchase-orders/show', [
            'order' => $purchaseOrder,
            'receipts' => $receipts,
            'bills' => $bills,
            'billing' => $billing,
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
            // ⚠ The next step of the chain, offered only when there is something
            // to bill: received, not returned, not already invoiced. The bill
            // form opens prefilled with exactly those quantities at the agreed
            // price (SupplierBillController::create).
            'can_record_bill' => $user->can('purchasing.bill.record')
                && $purchaseOrder->lines->contains(fn (PurchaseOrderLine $line) => (float) $line->billableQuantity() > 0),
            'can_record_payment' => $user->can('purchasing.payment.record'),
            'can_return' => $canApprove,
            'can_cancel' => $canApprove
                && $purchaseOrder->status !== PurchaseOrderStatus::Cancelled
                && ($purchaseOrder->status === PurchaseOrderStatus::Open || $purchaseOrder->hasNoOutstandingReceivedStock()),
            // The record's own story (Module 1, Slice 4).
            'timeline' => RecordTimeline::for($purchaseOrder),
        ]);
    }

    public function store(Request $request, CreatePurchaseOrder $action): RedirectResponse
    {
        $this->authorize('purchasing.po.create');

        $data = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'expected_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ]);

        $lines = collect($data['lines'])->map(fn (array $line) => [
            'product' => Product::findOrFail($line['product_id']),
            'quantity' => $line['quantity'],
            'unit_cost' => $line['unit_cost'],
        ])->all();

        try {
            $order = $action->handle(
                supplier: Supplier::findOrFail($data['supplier_id']),
                warehouse: Warehouse::findOrFail($data['warehouse_id']),
                lines: $lines,
                createdBy: $request->user(),
                expectedDate: $data['expected_date'] ?? null,
                note: $data['note'] ?? null,
            );
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        return redirect()->route('purchasing.purchase-orders.show', $order)->with('success', "Purchase order {$order->po_number} created.");
    }

    public function approve(Request $request, PurchaseOrder $purchaseOrder, ApprovePurchaseOrder $action): RedirectResponse
    {
        $this->authorize('purchasing.po.approve');

        // ⚠ A bare button. Two managers pressing approve, or one double-click,
        // is the ordinary way this refusal happens.
        try {
            $action->handle($purchaseOrder, $request->user());
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            return back()->with('error', $e->getMessage());
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
            return back()->with('error', $e->getMessage());
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
            return back()->with('error', $e->getMessage());
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
            $action->handle(
                $purchaseOrder,
                $lineReceipts,
                $request->user(),
                $data['note'] ?? null,
                $data['supplier_challan_number'] ?? null,
                $data['received_on'] ?? null,
                $data['backdate_reason'] ?? null,
            );
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        $response = back()->with('success', 'Goods receipt recorded.');

        return $warnings === [] ? $response : $response->with('warning', implode(' ', $warnings));
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
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        return back()->with('success', 'Purchase return recorded.');
    }
}
