<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseBin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Placing batches on shelves (C-BIN-1/2): the floor act that gives the pick
 * list its shelf address. Gated by `inventory.stock.adjust` - the hands that
 * already move stock - and writes `bin_id` and an activity row, nothing else.
 */
class BinPlacementController extends Controller
{
    public function index(Request $request): Response
    {
        $warehouses = Warehouse::query()->where('is_active', true)
            ->orderBy('name')->get(['id', 'name', 'code', 'is_default']);

        $warehouseId = $request->integer('warehouse')
            ?: ($warehouses->firstWhere('is_default', true)?->id ?? $warehouses->first()?->id);

        $batches = $warehouseId === null ? collect() : StockBatch::query()
            ->where('warehouse_id', $warehouseId)
            ->where('status', StockBatchStatus::Active)
            ->where('current_quantity', '>', 0)
            ->with(['product:id,name,sku,unit_of_measure_id', 'product.unitOfMeasure:id,abbreviation', 'bin:id,code,name'])
            ->orderBy('product_id')->orderBy('received_at')->orderBy('id')
            ->get()
            ->map(fn (StockBatch $batch) => [
                'id' => $batch->id,
                'product' => $batch->product?->name,
                'sku' => $batch->product?->sku,
                'unit' => $batch->product?->unitOfMeasure?->abbreviation,
                'batch_number' => $batch->batch_number,
                'expiry_date' => $batch->expiry_date?->toDateString(),
                'quantity' => $batch->current_quantity,
                'bin_id' => $batch->bin_id,
                'bin' => $batch->bin?->code,
            ]);

        return Inertia::render('inventory/bin-placement/index', [
            'warehouses' => $warehouses->map(fn (Warehouse $warehouse) => [
                'id' => $warehouse->id, 'name' => $warehouse->name, 'code' => $warehouse->code,
            ]),
            'warehouseId' => $warehouseId,
            'batches' => $batches,
            'bins' => $warehouseId === null ? [] : WarehouseBin::query()
                ->where('warehouse_id', $warehouseId)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
        ]);
    }

    public function update(Request $request, StockBatch $batch): RedirectResponse
    {
        $data = $request->validate([
            // Scoped (bug hunt 16 Oct 2026): another business's bin is a field
            // error, not the bare 404 of the scoped lookup below.
            'bin_id' => ['nullable', 'integer', Rule::exists('warehouse_bins', 'id')->where('business_id', app(BusinessContext::class)->id())],
        ]);

        if ($data['bin_id'] !== null) {
            $bin = WarehouseBin::findOrFail($data['bin_id']);

            // C-BIN-2. A transfer creates a NEW batch at the destination, so a
            // batch's warehouse never changes; the bin must be under that roof.
            if ($bin->warehouse_id !== $batch->warehouse_id) {
                throw ValidationException::withMessages(['bin_id' => 'That bin belongs to a different warehouse.']);
            }

            if (! $bin->is_active) {
                throw ValidationException::withMessages(['bin_id' => 'That bin is switched off. Switch it on before placing stock in it.']);
            }
        }

        $batch->update(['bin_id' => $data['bin_id']]);

        return back()->with('success', $data['bin_id'] === null ? 'Placement cleared.' : 'Batch placed.');
    }
}
