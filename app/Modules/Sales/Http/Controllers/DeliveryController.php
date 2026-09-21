<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Support\BusinessIdentity;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Delivery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Delivery challans - goods out ahead of the invoice.
 *
 * ⚠ The distributor half of the same engine the counter uses. A challan raised
 * here sits in Goods Delivered Not Invoiced until somebody bills it, and that
 * balance is the "out but not billed" report.
 */
class DeliveryController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->string('search')->toString(),
            'status' => $request->string('status')->toString(),
        ];

        $deliveries = Delivery::query()
            ->with(['customer:id,name', 'warehouse:id,name', 'lines'])
            ->when($filters['search'], fn ($query, $search) => $query->where(
                fn ($q) => $q->where('delivery_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"))
            ))
            // ⚠⚠ Filtered in SQL, BEFORE paginate: the badge's own rule below,
            // asked of the database. It used to drop fully invoiced challans
            // from the twenty already paginated, so "Not fully invoiced" showed
            // only what happened to be among the latest twenty (audit #12,
            // 21 Sep 2026) - and this is the list month-end billing starts from.
            ->when($filters['status'] === 'uninvoiced', fn ($query) => $query->whereHas(
                'lines',
                fn ($line) => $line->whereColumn('quantity_invoiced', '<', 'quantity'),
            ))
            ->orderByDesc('delivered_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Delivery $delivery) => array_merge($delivery->toArray(), [
                // ⚠ The Dhaka date. The datetime serialises in UTC and the list
                // sliced it, so a challan written before 6 am read as yesterday's.
                'delivered_at' => $delivery->delivered_at?->toDateString(),
                // ⚠ Fully invoiced means every line is billed. Partly billed is a
                // normal state, not an error, and it is exactly what the GDNI
                // balance reports.
                'fully_invoiced' => $delivery->lines->every(
                    fn ($line) => (float) $line->quantity_invoiced >= (float) $line->quantity
                ),
            ]));

        return Inertia::render('sales/deliveries/index', [
            'deliveries' => $deliveries,
            'filters' => $filters,
            'warehouses' => Warehouse::query()->where('is_active', true)
                ->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default']),
            'products' => Product::query()->where('is_active', true)->stocked()
                ->orderBy('name')->get(['id', 'name', 'sku']),
            'customers' => Customer::query()->active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * The challan that travels with the goods.
     *
     * ⚠⚠ THE DELIVERY HAD NO VIEW OF ANY KIND before this: an engine, an index
     * row and a journal, but nothing anybody could hand to the person receiving
     * the goods. A challan is not a nicety in this trade, it is what gets signed
     * at the door.
     *
     * ⚠ NO MONEY ON IT, deliberately. A challan says what left the warehouse; a
     * delivery line has no price column at all, and printing one would invite
     * the recipient to treat it as a bill.
     */
    public function print(Delivery $delivery): Response
    {
        $delivery->load(['customer', 'warehouse:id,code,name', 'lines.product:id,name,sku', 'lines.orderLine.order:id,order_number']);

        return Inertia::render('sales/deliveries/print', [
            'delivery' => $delivery,
            'issuer' => BusinessIdentity::forCurrent()->toArray(),
        ]);
    }

    public function store(Request $request, RecordDelivery $action): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'delivered_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        $products = Product::query()
            ->whereIn('id', collect($data['lines'])->pluck('product_id'))
            ->get()->keyBy('id');

        // ⚠ C-SLS-E1, same three refusals as the order-traced challan.
        try {
            $delivery = $action->handle(
                customer: Customer::findOrFail($data['customer_id']),
                warehouse: Warehouse::findOrFail($data['warehouse_id']),
                lines: collect($data['lines'])->map(fn (array $line) => [
                    'product' => $products[$line['product_id']],
                    'quantity' => (string) $line['quantity'],
                ])->all(),
                createdBy: $request->user(),
                deliveredAt: $data['delivered_at'] ?? null,
                note: $data['note'] ?? null,
            );
        } catch (InsufficientStockException|PeriodClosedException|MappingNotFound $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        return back()->with('success', "Challan {$delivery->delivery_number} recorded.");
    }
}
