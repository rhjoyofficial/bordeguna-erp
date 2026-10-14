<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Models\UnitOfMeasure;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UnitOfMeasureController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('inventory/units/index', [
            'units' => UnitOfMeasure::orderBy('name')->withCount('products')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        UnitOfMeasure::create($request->validate($this->rules()));

        return back()->with('success', 'Unit created.');
    }

    /**
     * ⚠ The route existed and the screen offered only Delete (21 Sep 2026), so
     * a misspelt unit already used by products could be neither fixed nor
     * removed. Renaming is safe: products point at the row, not at the text.
     */
    public function update(Request $request, UnitOfMeasure $unit): RedirectResponse
    {
        $unit->update($request->validate($this->rules($unit)));

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

        // ⚠ INV-004 (14 Oct 2026): `products()` is only the STOCK unit. A unit
        // used as a product's content or purchase unit, in a conversion, on a
        // batch or a production line is held by a RESTRICT key, and the
        // refusal reached the person as an error page. It is a sentence now.
        try {
            $unit->delete();
        } catch (QueryException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }

            return back()->with('error', 'This unit is still used (as a content or purchase unit, in a conversion, or on past stock), so it cannot be deleted.');
        }

        return back()->with('success', 'Unit deleted.');
    }

    /**
     * ⚠ The abbreviation is unique per business in the database and nothing
     * here said so: a second "kg" reached the unique index and came back as a
     * blank 500 instead of a sentence under the box.
     *
     * @return array<string, mixed>
     */
    private function rules(?UnitOfMeasure $ignore = null): array
    {
        $unique = Rule::unique('units_of_measure', 'abbreviation')->where('business_id', app(BusinessContext::class)->id());

        return [
            'name' => ['required', 'string', 'max:255'],
            'abbreviation' => ['required', 'string', 'max:20', $ignore ? $unique->ignore($ignore) : $unique],
        ];
    }
}
