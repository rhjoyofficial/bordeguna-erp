<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WarehouseController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('inventory/warehouses/index', [
            'warehouses' => Warehouse::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50'],
        ]);

        Warehouse::create($data + ['is_active' => true]);

        return back()->with('success', 'Warehouse created.');
    }

    public function update(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50'],
            'is_active' => ['required', 'boolean'],
        ]);

        $warehouse->update($data);

        return back()->with('success', 'Warehouse updated.');
    }

    public function destroy(Warehouse $warehouse): RedirectResponse
    {
        // ⚠ C-INV-E2. Same as units: a bare delete button renders no error key,
        // so both refusals were invisible. "This warehouse still holds stock" is
        // exactly the sentence somebody needs before the 31 August count.
        if ($warehouse->is_default) {
            return back()->with('error', 'The default warehouse cannot be deleted.');
        }

        if ($warehouse->stockBatches()->where('current_quantity', '>', 0)->exists()) {
            return back()->with('error', 'This warehouse still holds stock and cannot be deleted.');
        }

        $warehouse->delete();

        return back()->with('success', 'Warehouse deleted.');
    }
}
