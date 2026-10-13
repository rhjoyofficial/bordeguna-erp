<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseBin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bin definitions (C-BIN-3/4): warehouse structure, managed with the same
 * power that creates the warehouse around them. Nothing here moves stock.
 */
class WarehouseBinController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('inventory/bins/index', [
            'bins' => WarehouseBin::query()
                ->with('warehouse:id,name,code')
                ->withCount(['stockBatches as occupied_count' => fn ($query) => $query->where('current_quantity', '>', 0)])
                ->orderBy('warehouse_id')->orderBy('code')
                ->get()
                ->map(fn (WarehouseBin $bin) => [
                    'id' => $bin->id,
                    'warehouse_id' => $bin->warehouse_id,
                    'warehouse' => $bin->warehouse?->name,
                    'code' => $bin->code,
                    'name' => $bin->name,
                    'is_active' => $bin->is_active,
                    'occupied_count' => $bin->occupied_count,
                ]),
            'warehouses' => Warehouse::query()->where('is_active', true)
                ->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            // ⚠ Scoped: a bare exists accepted another business's warehouse id,
            // which create() then stored on this business's bin.
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('business_id', app(BusinessContext::class)->id())],
            'code' => [
                'required', 'string', 'max:20',
                // C-BIN-3: "A-1" may exist in every warehouse, never twice in one.
                Rule::unique('warehouse_bins', 'code')->where('warehouse_id', $request->integer('warehouse_id')),
            ],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        WarehouseBin::create($data);

        return back()->with('success', 'Bin created.');
    }

    public function update(Request $request, WarehouseBin $bin): RedirectResponse
    {
        $data = $request->validate([
            'code' => [
                'required', 'string', 'max:20',
                Rule::unique('warehouse_bins', 'code')
                    ->where('warehouse_id', $bin->warehouse_id)
                    ->ignore($bin->id),
            ],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $bin->update($data);

        return back()->with('success', 'Bin updated.');
    }

    public function toggleActive(WarehouseBin $bin): RedirectResponse
    {
        $bin->update(['is_active' => ! $bin->is_active]);

        return back()->with('success', $bin->is_active
            ? 'Bin switched on.'
            : 'Bin switched off. Batches already placed in it stay readable; new placements are refused.');
    }

    public function destroy(WarehouseBin $bin): RedirectResponse
    {
        // ⚠ An EMPTIED batch still blocks deletion: the bin is part of where
        // that batch's history happened. The FK refuses too (RESTRICT); this
        // check exists so the refusal is a sentence, not a blank 500.
        if ($bin->stockBatches()->exists()) {
            return back()->with('error', 'Batches have been placed in this bin. Switch it off instead of deleting it.');
        }

        $bin->delete();

        return back()->with('success', 'Bin deleted.');
    }
}
