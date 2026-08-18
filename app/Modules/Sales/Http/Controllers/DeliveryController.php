<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
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
            ->orderByDesc('delivered_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $deliveries->getCollection()->transform(function (Delivery $delivery) {
            // ⚠ Fully invoiced means every line is billed. Partly billed is a
            // normal state, not an error, and it is exactly what the GDNI
            // balance reports.
            $delivery->setAttribute('fully_invoiced', $delivery->lines->every(
                fn ($line) => (float) $line->quantity_invoiced >= (float) $line->quantity
            ));

            return $delivery;
        });

        if ($filters['status'] === 'uninvoiced') {
            $deliveries->setCollection($deliveries->getCollection()->reject->fully_invoiced->values());
        }

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
