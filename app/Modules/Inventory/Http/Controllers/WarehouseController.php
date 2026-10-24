<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Corrections\Recycle\BinsFromList;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\MasterRecords;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WarehouseController extends Controller
{
    use BinsFromList;

    private const DEFAULT_STAYS_ON = 'The default warehouse cannot be switched off. Make another warehouse the default first.';

    public function index(): Response
    {
        $warehouses = Warehouse::orderBy('name')->get();

        return Inertia::render('inventory/warehouses/index', [
            'warehouses' => $warehouses,
            // ⭐ B24 (24 Oct 2026, Q5 = A): each row's Delete / Switch off / Reactivate,
            // greyed with what uses it.
            'recordActions' => MasterRecords::rows('warehouse', $warehouses, request()->user()),
            'deleteReasons' => MasterRecords::reasons(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules());

        // ⚠ The FIRST warehouse of a business becomes its default. Until
        // 21 Sep 2026 nothing on any screen could set `is_default` at all, so a
        // business set up by hand had no default and every "preselect the
        // default warehouse" quietly preselected nothing. Later warehouses
        // never take it over by being created, even in a business that still
        // has no default; that is the Make default button's job.
        // ⭐ B7: a Not for sale (quarantine) warehouse never counts, and never
        // becomes the default: the default is where unnamed stock goes.
        $isFirst = ! Warehouse::query()->saleable()->exists() && ! ($data['not_for_sale'] ?? false);

        Warehouse::create($data + ['is_active' => true, 'is_default' => $isFirst]);

        return back()->with('success', $isFirst ? 'Warehouse created. It is the default warehouse.' : 'Warehouse created.');
    }

    public function update(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $data = $request->validate($this->rules($warehouse) + [
            'is_active' => ['required', 'boolean'],
        ]);

        if ($warehouse->is_default && ! $data['is_active']) {
            return back()->with('error', self::DEFAULT_STAYS_ON);
        }

        // ⭐ B7: the default warehouse is where stock goes when a document
        // does not say, so it can never be the quarantine store.
        if ($warehouse->is_default && ($data['not_for_sale'] ?? false)) {
            return back()->with('error', 'The default warehouse sells, so it cannot be Not for sale. Make another warehouse the default first.');
        }

        $warehouse->update($data);

        return back()->with('success', 'Warehouse updated.');
    }

    /**
     * Where stock goes when a document does not say (21 Sep 2026: the column
     * existed from the first migration and no screen could set it).
     *
     * ⚠ Only an ACTIVE warehouse. The default is the one warehouse that can
     * never be switched off, so letting a switched-off one become it would
     * make that rule false the moment it was set.
     */
    public function makeDefault(Warehouse $warehouse): RedirectResponse
    {
        if (! $warehouse->is_active) {
            return back()->with('error', 'Switch this warehouse on before making it the default.');
        }

        if ($warehouse->not_for_sale) {
            return back()->with('error', 'A Not for sale warehouse cannot be the default: the default is where stock for sale goes.');
        }

        $warehouse->makeDefault();

        return back()->with('success', "{$warehouse->name} is now the default warehouse.");
    }

    /**
     * The status on the warehouses page is a control, not a label (30 Aug 2026
     * audit). Same shape as brands: switch off, never delete, because stock
     * batches and documents keep pointing at the warehouse.
     *
     * ⚠ THE DEFAULT WAREHOUSE STAYS ON. Every stock document that does not
     * name a warehouse lands in the default one; an inactive default would
     * make the next receipt fail somewhere far from this screen.
     */
    public function toggleActive(Warehouse $warehouse): RedirectResponse
    {
        if ($warehouse->is_default && $warehouse->is_active) {
            return back()->with('error', self::DEFAULT_STAYS_ON);
        }

        $warehouse->update(['is_active' => ! $warehouse->is_active]);

        return back()->with('success', $warehouse->is_active ? 'Warehouse switched on.' : 'Warehouse switched off. Its stock and documents are untouched.');
    }

    public function destroy(Request $request, Warehouse $warehouse): RedirectResponse
    {
        // ⭐ B24 (24 Oct 2026, Q5 = A): never used goes to the Recycle bin with a
        // reason (the Owner restores it); used is refused with WHAT uses it,
        // read from the schema, and the row is switched off instead. ⚠ The
        // soft delete no longer meets the RESTRICT keys that used to refuse.
        return $this->binFromList($request, $warehouse);
    }

    /**
     * ⚠ The code is unique per business in the database (and it is the
     * opening-stock import's matching key), but nothing here said so: a
     * repeated code reached the unique index and came back as a blank 500.
     *
     * @return array<string, mixed>
     */
    private function rules(?Warehouse $ignore = null): array
    {
        $unique = Rule::unique('warehouses', 'code')->where('business_id', app(BusinessContext::class)->id());

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', $ignore ? $unique->ignore($ignore) : $unique],
            // ⭐ Initial plan B7 (Joy, 24 Oct 2026): the quarantine flag.
            'not_for_sale' => ['sometimes', 'boolean'],
        ];
    }
}
