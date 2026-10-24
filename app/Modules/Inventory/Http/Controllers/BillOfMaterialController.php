<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\SaveBillOfMaterial;
use App\Modules\Inventory\Models\BillOfMaterial;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\MaxMakeable;
use App\Modules\Inventory\Support\RecipeCosting;
use App\Modules\Inventory\Support\UnitConverter;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Recipes: what goes into each packed product, and how much.
 *
 * ⚠⚠ C-BOM-1. NOTHING ON THIS SCREEN MOVES STOCK OR POSTS A JOURNAL. Saving a
 * recipe changes what the production run screen suggests, and nothing else. The
 * production run remains the sixth and last document that can move stock.
 *
 * ⚠ C-BOM-9. Route, screen and a nav entry ship together. Five engines on this
 * project have been finished and unreachable, the last of them for eleven days,
 * and the reachability guard reads this page's path out of the sidebar source.
 */
class BillOfMaterialController extends Controller
{
    public function index(UnitConverter $converter): Response
    {
        // ⭐ PR3 (18 Oct 2026): "can make now" per active recipe, from the
        // default warehouse's shelf, by the same helper the run form uses.
        $warehouse = Warehouse::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->first();
        $available = $warehouse === null ? [] : (MaxMakeable::available(MaxMakeable::layers($warehouse->id))[$warehouse->id] ?? []);

        $boms = BillOfMaterial::query()
            ->with(['outputProduct:id,name,sku,unit_of_measure_id,allows_fractional_stock', 'outputProduct.unitOfMeasure:id,abbreviation,dimension',
                'lines.componentProduct:id,name,sku,type,material_kind,unit_of_measure_id,is_active,allows_fractional_stock', 'lines.componentProduct.unitOfMeasure:id,abbreviation,dimension',
                'lines.enteredUnit:id,abbreviation'])
            ->withCount('runs')
            ->orderBy('output_product_id')
            ->orderByDesc('version')
            ->get();

        // ⭐ Initial plan B5 (24 Oct 2026; R25, R26): every component's unit
        // rate in two queries, for the saved recipes and the form's preview.
        $products = Product::query()->where('is_active', true)->stocked()
            ->with(['unitOfMeasure', 'category:id,name'])
            ->orderBy('sku')
            ->get(['id', 'name', 'sku', 'type', 'material_kind', 'unit_of_measure_id', 'category_id']);
        $rates = RecipeCosting::rates(array_merge(
            $boms->flatMap->lines->pluck('component_product_id')->all(),
            $products->pluck('id')->all(),
        ));

        $boms = $boms
            ->map(fn (BillOfMaterial $bom) => ['bom' => $bom, 'cost' => RecipeCosting::forRecipe($bom, $rates)])
            ->map(fn (array $row) => $this->recipeRow($row['bom'], $row['cost'], $warehouse, $available))
            ->values();

        $units = UnitOfMeasure::query()->orderBy('name')->get();
        $today = Carbon::today();

        return Inertia::render('inventory/bills-of-material/index', [
            'boms' => $boms,
            'canMakeWarehouse' => $warehouse?->name,
            // C-BOM-8. Stocked only, both ends: a service can neither be
            // produced nor consumed.
            'products' => $products
                ->map(fn (Product $p) => [
                    'id' => $p->id,
                    'sku' => $p->sku,
                    'name' => $p->name,
                    'unit' => $p->unitOfMeasure?->abbreviation,
                    'stock_unit_id' => $p->unit_of_measure_id,
                    // ⭐ Phase 1 Part B: the units a line for this component may
                    // be written in (its stock unit, same-dimension metric units,
                    // units a product row in force today converts from), with a
                    // factor for the screen's PREVIEW only. The server converts
                    // again on save; the screen never decides.
                    'units' => $converter->convertibleUnits($p, $today, $units)
                        ->map(fn (array $option) => [
                            'id' => $option['unit']->id,
                            'abbreviation' => $option['unit']->abbreviation,
                            'name' => $option['unit']->name,
                            'factor' => $option['factor'],
                        ])->values()->all(),
                    // ⭐ F10 (practice run, 17 Oct 2026): "Product being made"
                    // offered jars, lids and labels. Packaging is bought, never
                    // made here; a finished good or an in-between raw item can
                    // be. Every stocked item may still be a component.
                    // ⭐ B4 (24 Oct 2026): packaging is now the product's own
                    // kind (MaterialKind), not a guess from its category.
                    'can_be_made' => ! $p->isPackaging(),
                    'is_packaging' => $p->isPackaging(),
                    // ⭐ B5: the latest batch's exact cost per stock unit (Q20), and the
                    // on-hand average as a labelled hint.
                    'unit_rate' => $rates[$p->id]['rate'] ?? null,
                    'rate_basis' => $rates[$p->id]['basis'] ?? null,
                    'on_hand_average' => $rates[$p->id]['on_hand_average'] ?? null,
                ])->values(),
        ]);
    }

    /**
     * One recipe as the screen shows it, with its costing (B5).
     *
     * @param  array<string, mixed>  $cost  RecipeCosting::forRecipe()
     * @param  array<int, mixed>  $available
     * @return array<string, mixed>
     */
    private function recipeRow(BillOfMaterial $bom, array $cost, ?Warehouse $warehouse, array $available): array
    {
        return [
            'id' => $bom->id,
            'output_product_id' => $bom->output_product_id,
            'output_sku' => $bom->outputProduct?->sku,
            'output_product' => $bom->outputProduct?->name,
            'output_unit' => $bom->outputProduct?->unitOfMeasure?->abbreviation,
            'output_quantity' => $bom->output_quantity,
            'version' => $bom->version,
            'name' => $bom->name,
            'is_active' => $bom->is_active,
            'effective_from' => $bom->effective_from?->toDateString(),
            'note' => $bom->note,
            // ⚠ How many runs were built from THIS version. It decides
            // whether editing mutates the row or spawns the next version,
            // and whether it may be deleted at all, so the screen says so
            // instead of letting the refusal be a surprise.
            'runs' => $bom->runs_count,
            'can_make' => $bom->is_active && $warehouse !== null ? MaxMakeable::forRecipe($bom, $available) : null,
            'lines' => $bom->lines->values()->map(fn ($line, int $index) => [
                'component_product_id' => $line->component_product_id,
                'sku' => $line->componentProduct?->sku,
                'component' => $line->componentProduct?->name,
                'unit' => $line->componentProduct?->unitOfMeasure?->abbreviation,
                'component_active' => (bool) $line->componentProduct?->is_active,
                'quantity_per_output' => $line->quantity_per_output,
                // ⭐ Phase 1 Part B (17 Oct 2026): what was typed, beside the
                // stock-unit figure above. A row from before entered units
                // existed reads as its stock figure in its stock unit.
                'entered_quantity' => $line->enteredQuantity(),
                'entered_unit_id' => $line->enteredUnitId(),
                'entered_unit' => $line->enteredUnit?->abbreviation ?? $line->componentProduct?->unitOfMeasure?->abbreviation,
                'wastage_percent' => $line->wastage_percent,
                'note' => $line->note,
                // ⭐ B5 (24 Oct 2026): the line's section, its unit rate per
                // stock unit (and which figure that is), and its cost.
                ...$cost['lines'][$index],
            ])->all(),
            // ⭐ B5: Raw materials and Packaging subtotals, Direct materials
            // and the cost of one unit of output. `cost_complete` is false
            // when a component was never stocked and so has no rate yet.
            'raw_total' => $cost['raw_total'],
            'packaging_total' => $cost['packaging_total'],
            'direct_materials' => $cost['direct_materials'],
            'cost_per_unit' => $cost['cost_per_unit'],
            'cost_complete' => $cost['complete'],
        ];
    }

    public function store(Request $request, SaveBillOfMaterial $action): RedirectResponse
    {
        $data = $this->validated($request);

        return $this->save($action, $request, $data, null);
    }

    /**
     * C-BOM-6. Whether this mutates the row or writes the next version is the
     * action's decision, not the caller's: it depends on whether a run was ever
     * built from this exact version.
     */
    public function update(Request $request, BillOfMaterial $billOfMaterial, SaveBillOfMaterial $action): RedirectResponse
    {
        $data = $this->validated($request);
        $billOfMaterial->load('lines');

        // ⚠ MFC-008 (audit 14 Oct 2026): a recipe is the recipe OF one product.
        // The screen locks the field, but a request naming another product was
        // taken: the cycle check ran against that product while the row kept
        // its own (A could be stored as a component of itself), or B's recipe
        // was versioned while A's edit was dropped. Refused, by name.
        if ((int) $data['output_product_id'] !== (int) $billOfMaterial->output_product_id) {
            throw ValidationException::withMessages([
                'output_product_id' => 'A recipe cannot change the product it makes. Make a new recipe for the other product instead.',
            ]);
        }

        return $this->save($action, $request, $data, $billOfMaterial);
    }

    /**
     * Turn a recipe off without losing it. The run screen stops suggesting it;
     * every run already built from it still says what it was built from.
     */
    public function deactivate(BillOfMaterial $billOfMaterial): RedirectResponse
    {
        $billOfMaterial->update(['is_active' => false]);

        return back()->with('success', 'Recipe v'.$billOfMaterial->version.' switched off.');
    }

    public function activate(BillOfMaterial $billOfMaterial): RedirectResponse
    {
        // One active version per output product (C-BOM-6).
        BillOfMaterial::query()
            ->where('output_product_id', $billOfMaterial->output_product_id)
            ->where('id', '!=', $billOfMaterial->id)
            ->update(['is_active' => false]);

        $billOfMaterial->update(['is_active' => true]);

        return back()->with('success', 'Recipe v'.$billOfMaterial->version.' is the one in use.');
    }

    /**
     * ⚠⚠ Only a recipe NO RUN WAS EVER BUILT FROM. A used version is the answer
     * to "what recipe was that run built from", and deleting it erases the
     * answer while leaving the run looking complete. The database refuses it
     * too; this refusal exists so the person reads a sentence instead of a
     * constraint violation.
     */
    public function destroy(BillOfMaterial $billOfMaterial): RedirectResponse
    {
        if ($billOfMaterial->hasBeenUsed()) {
            throw ValidationException::withMessages([
                'bom' => 'A production run was built from this recipe, so it can be switched off but never deleted.',
            ]);
        }

        $billOfMaterial->lines()->delete();
        $billOfMaterial->delete();

        return back()->with('success', 'Recipe deleted.');
    }

    /**
     * @return array<string,mixed>
     */
    private function validated(Request $request): array
    {
        $businessId = app(BusinessContext::class)->id();

        return $request->validate([
            'output_product_id' => ['required', Rule::exists('products', 'id')->where('business_id', $businessId)],
            'name' => ['nullable', 'string', 'max:255'],
            'output_quantity' => ['required', 'numeric', 'gt:0'],
            'effective_from' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', Rule::exists('products', 'id')->where('business_id', $businessId)],
            'lines.*.quantity_per_output' => ['required', 'numeric', 'gt:0'],
            // The unit the quantity is typed in; blank = the component's stock unit.
            'lines.*.unit_id' => ['nullable', Rule::exists('units_of_measure', 'id')->where('business_id', $businessId)],
            'lines.*.wastage_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.note' => ['nullable', 'string', 'max:255'],
        ], [], [
            'output_product_id' => 'product being made',
            'lines.*.product_id' => 'component',
            'lines.*.quantity_per_output' => 'quantity',
        ]);
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function save(SaveBillOfMaterial $action, Request $request, array $data, ?BillOfMaterial $editing): RedirectResponse
    {
        try {
            $bom = $action->handle(
                outputProduct: Product::findOrFail($data['output_product_id']),
                lines: collect($data['lines'])->map(fn (array $line) => [
                    'product_id' => (int) $line['product_id'],
                    'quantity_per_output' => (string) $line['quantity_per_output'],
                    'unit_id' => empty($line['unit_id']) ? null : (int) $line['unit_id'],
                    'wastage_percent' => (string) ($line['wastage_percent'] ?? '0'),
                    'note' => $line['note'] ?? null,
                ])->all(),
                by: $request->user(),
                editing: $editing,
                name: $data['name'] ?? null,
                outputQuantity: (string) $data['output_quantity'],
                effectiveFrom: $data['effective_from'] ?? null,
                note: $data['note'] ?? null,
            );
        } catch (DomainException $e) {
            // ⚠ The action's refusals are written for a person to read - "this
            // recipe would contain itself: Jar → Gift box → Jar" - so they are
            // surfaced verbatim rather than replaced with a generic message.
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        return back()->with('success', 'Recipe for '.$bom->outputProduct->sku.' saved as v'.$bom->version.'.');
    }
}
