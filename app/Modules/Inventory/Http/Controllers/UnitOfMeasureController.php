<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Corrections\Recycle\BinsFromList;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\MasterRecords;
use App\Modules\Inventory\Models\UnitOfMeasure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UnitOfMeasureController extends Controller
{
    use BinsFromList;

    public function index(): Response
    {
        $units = UnitOfMeasure::orderBy('name')->withCount('products')->get();

        return Inertia::render('inventory/units/index', [
            'units' => $units,
            // ⭐ B24 (24 Oct 2026, Q5 = A): each row's Delete / Switch off / Reactivate,
            // greyed with what uses it.
            'recordActions' => MasterRecords::rows('unit', $units, request()->user()),
            'deleteReasons' => MasterRecords::reasons(),
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

    public function destroy(Request $request, UnitOfMeasure $unit): RedirectResponse
    {
        // ⭐ B24 (24 Oct 2026, Q5 = A): never used goes to the Recycle bin with a
        // reason (the Owner restores it); used is refused with WHAT uses it,
        // read from the schema, and the row is switched off instead. ⚠ The
        // soft delete no longer meets the RESTRICT keys that used to refuse.
        return $this->binFromList($request, $unit);
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
