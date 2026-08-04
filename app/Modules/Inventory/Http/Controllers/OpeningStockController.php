<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Actions\RecordOpeningStock;
use App\Modules\Inventory\Models\OpeningStock;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the warehouse already held on the day the books start.
 *
 * ⚠ Gated on `inventory.stock.adjust`, not `stock.record`. Opening stock sets
 * the cost layers FIFO will consume for the rest of the business's life, so it
 * decides every margin reported until that stock is sold through. That is an
 * owner's decision, not a counter task.
 */
class OpeningStockController extends Controller
{
    public function index(): Response
    {
        $recorded = OpeningStock::query()
            ->with(['warehouse:id,name', 'lines.product:id,name,sku'])
            ->orderByDesc('as_at')
            ->get();

        // ⚠ Once per warehouse, so the screen offers only the ones still
        // awaiting it rather than letting somebody discover the refusal after
        // typing fifty lines.
        $done = $recorded->pluck('warehouse_id')->all();

        return Inertia::render('inventory/opening-stock/index', [
            'openingStocks' => $recorded,
            'warehouses' => Warehouse::query()->where('is_active', true)
                ->whereNotIn('id', $done)
                ->orderBy('name')->get(['id', 'name', 'code']),
            'products' => Product::query()->where('is_active', true)->stocked()
                ->orderBy('name')->get(['id', 'name', 'sku']),
        ]);
    }

    public function store(Request $request, RecordOpeningStock $action): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'as_at' => ['required', 'date'],
            'note' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'lines.*.batch_number' => ['nullable', 'string', 'max:100'],
            'lines.*.expiry_date' => ['nullable', 'date'],
        ]);

        $products = Product::query()
            ->whereIn('id', collect($data['lines'])->pluck('product_id'))
            ->get()->keyBy('id');

        $action->handle(
            warehouse: Warehouse::findOrFail($data['warehouse_id']),
            lines: collect($data['lines'])->map(fn (array $line) => [
                'product' => $products[$line['product_id']],
                'quantity' => (string) $line['quantity'],
                'unitCost' => (string) $line['unit_cost'],
                'batchNumber' => $line['batch_number'] ?? null,
                'expiryDate' => $line['expiry_date'] ?? null,
            ])->all(),
            createdBy: $request->user(),
            asAt: $data['as_at'],
            note: $data['note'] ?? null,
        );

        return back()->with('success', 'Opening stock recorded.');
    }
}
