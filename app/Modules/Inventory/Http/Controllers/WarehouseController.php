<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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

        return back();
    }

    public function update(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50'],
            'is_active' => ['required', 'boolean'],
        ]);

        $warehouse->update($data);

        return back();
    }

    public function destroy(Warehouse $warehouse): RedirectResponse
    {
        if ($warehouse->is_default) {
            throw ValidationException::withMessages(['warehouse' => 'The default warehouse cannot be deleted.']);
        }

        if ($warehouse->stockBatches()->where('current_quantity', '>', 0)->exists()) {
            throw ValidationException::withMessages(['warehouse' => 'This warehouse still holds stock and cannot be deleted.']);
        }

        $warehouse->delete();

        return back();
    }
}
