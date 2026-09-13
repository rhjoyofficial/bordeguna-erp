<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Foundation\Actions\RejectApprovable;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessIdentity;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\PickPlan;
use App\Modules\Sales\Actions\ApproveSalesOrder;
use App\Modules\Sales\Actions\CancelSalesOrder;
use App\Modules\Sales\Actions\CloseSalesOrder;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\Actions\CustomerExposure;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\Actions\ResolveSellingPrice;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Exceptions\NoSellingPriceException;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Modules\Sales\Support\DeliveryBilling;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The order book - what is promised, to whom, and how much of it has left.
 *
 * ⚠ A screen over CreateSalesOrder and RecordDelivery, not a parallel system.
 * Prices are resolved by the SERVER at store time and copied onto the lines;
 * this controller never computes one.
 */
class SalesOrderController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->string('search')->toString(),
            'status' => $request->string('status')->toString(),
        ];

        $orders = SalesOrder::query()
            ->with(['customer:id,name', 'warehouse:id,name', 'approvalRequest'])
            ->withSum('lines as total_ordered', 'quantity_ordered')
            ->withSum('lines as total_delivered', 'quantity_delivered')
            // ⚠ THE SHOP REFERENCE IS SEARCHABLE TOO, and it has to be: a
            // customer ringing up quotes `BNC-20260912-X7JWX2YT` and has never
            // seen `SO-FY2026-0001`. A number a customer holds that staff
            // cannot search for is a number that costs a support call.
            ->when($filters['search'], fn ($query, $search) => $query->where(
                fn ($q) => $q->where('order_number', 'like', "%{$search}%")
                    ->orWhere('shop_reference', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"))
            ))
            ->when($filters['status'], fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('ordered_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('sales/orders/index', [
            'orders' => $orders,
            'filters' => $filters,
            // ⚠ C-SO-2 (Joy): NO reservation. This report is the honest
            // substitute - what is promised against what is on the shelf, per
            // product, for a human deciding whether to take the next order.
            // Nothing anywhere enforces it.
            'committed' => $this->committedVersusOnHand(),
            'customers' => Customer::query()->active()->orderBy('name')->get(['id', 'name', 'credit_limit']),
            'warehouses' => Warehouse::query()->where('is_active', true)
                ->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default']),
            'products' => Product::query()->where('is_active', true)->stocked()
                ->orderBy('name')->get(['id', 'name', 'sku']),
            'can_create' => $request->user()->can('sales.orders.record'),
        ]);
    }

    /**
     * The undelivered promise per product, against what the shelves hold.
     *
     * ⚠ Only APPROVED, still-open orders count - a pending order is not yet a
     * commitment, and closed/cancelled remainders stopped being owed. Derived
     * fresh on every load, stored nowhere; a reservation table that must be
     * maintained is exactly what C-SO-2 declined.
     *
     * @return array<int, array{product_id: int, product_name: string, product_sku: string, committed: string, on_hand: string, short: bool}>
     */
    private function committedVersusOnHand(): array
    {
        $lines = SalesOrderLine::query()
            ->whereHas('order', fn ($q) => $q
                ->whereIn('status', [SalesOrderStatus::Open, SalesOrderStatus::PartiallyDelivered])
                ->whereHas('approvalRequest', fn ($a) => $a->where('status', ApprovalStatus::Approved)))
            ->with('product:id,name,sku')
            ->get();

        return $lines
            ->groupBy('product_id')
            ->map(function ($productLines) {
                $committed = $productLines->sum(fn (SalesOrderLine $line) => (float) $line->quantityRemaining());
                $product = $productLines->first()->product;
                $onHand = (float) $product->currentStockQuantity();

                return [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_sku' => $product->sku,
                    'committed' => number_format($committed, 4, '.', ''),
                    'on_hand' => number_format($onHand, 4, '.', ''),
                    'short' => $committed > $onHand,
                ];
            })
            ->filter(fn (array $row) => (float) $row['committed'] > 0)
            ->values()
            ->all();
    }

    /**
     * The sheet the warehouse walks around with.
     *
     * ⚠⚠ WHAT IS LEFT TO PICK, not what was ordered. An order part-delivered
     * last week must not send somebody back for the whole quantity, and the
     * line's own `quantityRemaining()` is what the delivery screen will accept
     * anyway.
     *
     * ⚠ The batches come from `PickPlan`, which reads the same FIFO scope the
     * ledger consumes, so the shelf and the books agree about which goods left.
     * It reserves nothing: between printing and picking somebody may sell the
     * same box at the counter, and the sheet says so.
     */
    public function pickList(SalesOrder $order): Response
    {
        $order->load([
            'customer',
            'warehouse:id,name,code',
            'lines.product:id,name,sku,unit_of_measure_id',
            'lines.product.unitOfMeasure:id,abbreviation',
        ]);

        $warehouse = $order->warehouse;

        $lines = $order->lines->map(function ($line) use ($warehouse) {
            $toPick = (float) $line->quantityRemaining();

            $plan = $warehouse === null
                ? ['on_hand' => 0.0, 'shortfall' => $toPick, 'batches' => []]
                : PickPlan::for($line->product, $warehouse, $toPick);

            return [
                'id' => $line->id,
                'product' => $line->product?->name,
                'sku' => $line->product?->sku,
                'unit' => $line->product?->unitOfMeasure?->abbreviation,
                'ordered' => (float) $line->quantity_ordered,
                'delivered' => (float) $line->quantity_delivered,
                'to_pick' => $toPick,
                'on_hand' => $plan['on_hand'],
                'shortfall' => $plan['shortfall'],
                'batches' => $plan['batches'],
            ];
        })->values();

        return Inertia::render('sales/orders/pick-list', [
            'order' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'ordered_at' => $order->ordered_at?->toDateString(),
                'expected_date' => $order->expected_date?->toDateString(),
                'status' => $order->status->value,
                'note' => $order->note,
                'customer' => $order->customer?->only(['id', 'name', 'code', 'address', 'phone']),
                'warehouse' => $order->warehouse?->only(['id', 'name', 'code']),
            ],
            'lines' => $lines,
            // Nothing left to pick is worth saying on the page rather than
            // leaving somebody to add the columns up themselves.
            'nothing_to_pick' => $lines->every(fn (array $line) => $line['to_pick'] <= 0),
            'issuer' => BusinessIdentity::forCurrent()->toArray(),
        ]);
    }

    public function show(Request $request, SalesOrder $order, ResolveSellingPrice $resolver): Response
    {
        $order->load([
            'customer',
            'warehouse:id,name',
            // ⚠ Website Phase 2, slice 2.4. A website order lands in this
            // screen's approval queue, and the person confirming it needs the
            // recipient's phone number and the destination - without them the
            // confirmation call the design depends on cannot be made. Both are
            // null on an order somebody typed in, and the page renders nothing.
            'store:id,name',
            'address',
            'createdBy:id,name',
            'approvalRequest.requestedBy:id,name',
            'approvalRequest.reviewedBy:id,name',
            'lines.product:id,name,sku',
            'lines.product.unitOfMeasure:id,abbreviation',
            'lines.deliveryLines.delivery:id,delivery_number,delivered_at',
        ]);

        $user = $request->user();

        $deliveryIds = $order->lines->flatMap(fn ($line) => $line->deliveryLines->pluck('delivery_id'))->unique()->values();

        // ⭐ Each challan with what a courier needs: its lines, its parcel, and
        // the amount to collect if it were delivered today. Shaped here, dates
        // as dates, never a model handed to the page.
        $challans = Delivery::query()
            ->whereIn('id', $deliveryIds)
            ->with(['lines.product:id,name,sku', 'lines.orderLine', 'customer', 'consignment.courier:id,name', 'consignment.invoice:id,invoice_number', 'consignment.salesReturn:id,return_number'])
            ->orderBy('delivered_at')
            ->orderBy('id')
            ->get()
            ->map(function (Delivery $delivery) use ($resolver) {
                $consignment = $delivery->consignment;
                $untouched = $delivery->lines->every(fn ($line) => (float) $line->quantity_invoiced <= 0 && (float) $line->quantity_returned <= 0);

                return [
                    'id' => $delivery->id,
                    'delivery_number' => $delivery->delivery_number,
                    'delivered_at' => $delivery->delivered_at->toDateString(),
                    'can_consign' => $consignment === null && $untouched,
                    'cod_preview' => $consignment === null && $untouched
                        ? DeliveryBilling::previewTotal($delivery, $resolver)?->toTaka()
                        : null,
                    'lines' => $delivery->lines->map(fn ($line) => [
                        'id' => $line->id,
                        'product' => $line->product?->name,
                        'sku' => $line->product?->sku,
                        'quantity' => $line->quantityUninvoiced(),
                    ])->values(),
                    'consignment' => $consignment === null ? null : [
                        'id' => $consignment->id,
                        'courier' => $consignment->courier?->name,
                        'consignment_number' => $consignment->consignment_number,
                        'cod_amount' => $consignment->codAmount()->toTaka(),
                        'status' => $consignment->status->value,
                        'status_label' => $consignment->status->label(),
                        'outcome_on' => $consignment->outcome_on?->toDateString(),
                        'collected_amount' => $consignment->collectedAmount()?->toTaka(),
                        'invoice' => $consignment->invoice?->only(['id', 'invoice_number']),
                        'sales_return' => $consignment->salesReturn?->only(['id', 'return_number']),
                    ],
                ];
            });

        return Inertia::render('sales/orders/show', [
            'order' => $order,
            'challans' => $challans,
            'couriers' => MoneyAccount::query()->active()->where('type', MoneyAccountType::Courier->value)
                ->orderBy('name')->get(['id', 'name']),
            'can_record_outcome' => $user->can('sales.sell') && $user->can('sales.receipt.record') && $user->can('sales.returns.record'),
            // ⚠ C-SO-3: exposure SHOWN beside the credit limit, enforced by
            // nobody. The human decides.
            'exposure' => app(CustomerExposure::class)->handle($order->customer),
            'can_approve' => $user->can('sales.orders.approve'),
            'can_deliver' => $user->can('sales.sell'),
            'can_cancel' => $user->can('sales.orders.cancel'),
            'warehouses' => Warehouse::query()->where('is_active', true)
                ->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default']),
        ]);
    }

    public function store(Request $request, CreateSalesOrder $action): RedirectResponse
    {
        $this->authorize('sales.orders.record');

        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'ordered_at' => ['nullable', 'date'],
            'expected_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            // ⚠ A manual price override, taken verbatim and marked as such on
            // the line - the same seam the counter has. Absent, the SERVER
            // resolves the price at the order date.
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $products = Product::query()
            ->whereIn('id', collect($data['lines'])->pluck('product_id'))
            ->get()->keyBy('id');

        // ⚠ C-SLS-E1. The server resolves every price that was not overridden,
        // so a product the price file never reached refuses the order here. The
        // exception names the product; say so on the form.
        try {
            $order = $action->handle(
                customer: Customer::findOrFail($data['customer_id']),
                lines: collect($data['lines'])->map(fn (array $line) => [
                    'product' => $products[$line['product_id']],
                    'quantity' => (string) $line['quantity'],
                    'unit_price' => isset($line['unit_price']) ? (string) $line['unit_price'] : null,
                ])->all(),
                createdBy: $request->user(),
                warehouse: isset($data['warehouse_id']) ? Warehouse::findOrFail($data['warehouse_id']) : null,
                orderedAt: $data['ordered_at'] ?? null,
                expectedDate: $data['expected_date'] ?? null,
                note: $data['note'] ?? null,
            );
        } catch (NoSellingPriceException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        return redirect()->route('sales.orders.show', $order)
            ->with('success', "Sales order {$order->order_number} recorded.");
    }

    /**
     * ⚠ C-SO-3, the "show" half: the exposure a customer would stand at,
     * answered by the server for the create form the moment a customer is
     * picked. Blocks nothing.
     */
    public function exposure(Request $request, CustomerExposure $action): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
        ]);

        return response()->json($action->handle(Customer::findOrFail($data['customer_id'])));
    }

    public function approve(Request $request, SalesOrder $order, ApproveSalesOrder $action): RedirectResponse
    {
        $this->authorize('sales.orders.approve');

        $action->handle($order, $request->user());

        return back()->with('success', 'Sales order approved.');
    }

    public function reject(Request $request, SalesOrder $order, RejectApprovable $action): RedirectResponse
    {
        $this->authorize('sales.orders.approve');

        $data = $request->validate(['review_note' => ['nullable', 'string']]);

        $action->handle($order, $request->user(), $data['review_note'] ?? null);

        return back()->with('success', 'Sales order rejected.');
    }

    public function cancel(SalesOrder $order, CancelSalesOrder $action): RedirectResponse
    {
        $this->authorize('sales.orders.cancel');

        $action->handle($order);

        return back()->with('success', 'Sales order cancelled.');
    }

    public function close(Request $request, SalesOrder $order, CloseSalesOrder $action): RedirectResponse
    {
        $this->authorize('sales.orders.cancel');

        $data = $request->validate(['reason' => ['required', 'string']]);

        $action->handle($order, $request->user(), $data['reason']);

        return back()->with('success', 'Sales order closed short.');
    }

    /**
     * Goods out against this order - the challan raised from the order screen,
     * where the person fulfilling it is standing.
     */
    public function deliver(Request $request, SalesOrder $order, RecordDelivery $action): RedirectResponse
    {
        $this->authorize('sales.sell');

        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'delivered_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sales_order_line_id' => ['required', 'integer', 'exists:sales_order_lines,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
        ]);

        $order->load('lines.product');

        $lines = collect($data['lines'])
            ->filter(fn (array $entry) => (float) $entry['quantity'] > 0)
            ->map(function (array $entry) use ($order) {
                $orderLine = $order->lines->firstWhere('id', $entry['sales_order_line_id']);

                return $orderLine === null ? null : [
                    'product' => $orderLine->product,
                    'quantity' => (string) $entry['quantity'],
                    'orderLine' => $orderLine,
                ];
            })
            ->filter()
            ->values()
            ->all();

        // ⚠ C-SLS-E1. Delivering more than the warehouse holds, or into a month
        // somebody has already reported on, is refused by the action. Both are
        // ordinary mistakes a picker makes and neither deserves a blank screen.
        try {
            $delivery = $action->handle(
                customer: $order->customer,
                warehouse: Warehouse::findOrFail($data['warehouse_id']),
                lines: $lines,
                createdBy: $request->user(),
                deliveredAt: $data['delivered_at'] ?? null,
                note: $data['note'] ?? null,
            );
        } catch (InsufficientStockException|PeriodClosedException|MappingNotFound $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        return back()->with('success', "Challan {$delivery->delivery_number} recorded against {$order->order_number}.");
    }
}
