<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\UnitOfMeasure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UnitOfMeasureController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('inventory/units/index', [
            'units' => UnitOfMeasure::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'abbreviation' => ['required', 'string', 'max:20'],
        ]);

        UnitOfMeasure::create($data);

        return back()->with('success', 'Unit created.');
    }

    public function update(Request $request, UnitOfMeasure $unit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'abbreviation' => ['required', 'string', 'max:20'],
        ]);

        $unit->update($data);

        return back()->with('success', 'Unit updated.');
    }

    public function destroy(UnitOfMeasure $unit): RedirectResponse
    {
        // ⚠ C-INV-E2. Delete is a bare button with no form, so a validation
        // error under `unit` was attached to a key nothing renders and the row
        // simply stayed put with no explanation. Flashed instead.
        if ($unit->products()->exists()) {
            return back()->with('error', 'This unit is used by existing products and cannot be deleted.');
        }

        $unit->delete();

        return back()->with('success', 'Unit deleted.');
    }
}
