<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Actions\RejectApprovable;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\ApproveSalesOrder;
use App\Modules\Sales\Actions\CancelSalesOrder;
use App\Modules\Sales\Actions\CloseSalesOrder;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\Actions\CustomerExposure;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            ->when($filters['search'], fn ($query, $search) => $query->where(
                fn ($q) => $q->where('order_number', 'like', "%{$search}%")
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

    public function show(Request $request, SalesOrder $order): Response
    {
        $order->load([
            'customer',
            'warehouse:id,name',
            'createdBy:id,name',
            'approvalRequest.requestedBy:id,name',
            'approvalRequest.reviewedBy:id,name',
            'lines.product:id,name,sku',
            'lines.product.unitOfMeasure:id,abbreviation',
            'lines.deliveryLines.delivery:id,delivery_number,delivered_at',
        ]);

        $user = $request->user();

        return Inertia::render('sales/orders/show', [
            'order' => $order,
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

        $delivery = $action->handle(
            customer: $order->customer,
            warehouse: Warehouse::findOrFail($data['warehouse_id']),
            lines: $lines,
            createdBy: $request->user(),
            deliveredAt: $data['delivered_at'] ?? null,
            note: $data['note'] ?? null,
        );

        return back()->with('success', "Challan {$delivery->delivery_number} recorded against {$order->order_number}.");
    }
}
