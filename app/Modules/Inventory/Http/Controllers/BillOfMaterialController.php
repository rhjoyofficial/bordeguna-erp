<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\SaveBillOfMaterial;
use App\Modules\Inventory\Models\BillOfMaterial;
use App\Modules\Inventory\Models\Product;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    public function index(): Response
    {
        $boms = BillOfMaterial::query()
            ->with(['outputProduct:id,name,sku,unit_of_measure_id', 'outputProduct.unitOfMeasure:id,abbreviation',
                'lines.componentProduct:id,name,sku,unit_of_measure_id,is_active', 'lines.componentProduct.unitOfMeasure:id,abbreviation'])
            ->withCount('runs')
            ->orderBy('output_product_id')
            ->orderByDesc('version')
            ->get()
            ->map(fn (BillOfMaterial $bom) => [
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
                'lines' => $bom->lines->map(fn ($line) => [
                    'component_product_id' => $line->component_product_id,
                    'sku' => $line->componentProduct?->sku,
                    'component' => $line->componentProduct?->name,
                    'unit' => $line->componentProduct?->unitOfMeasure?->abbreviation,
                    'component_active' => (bool) $line->componentProduct?->is_active,
                    'quantity_per_output' => $line->quantity_per_output,
                    'wastage_percent' => $line->wastage_percent,
                    'note' => $line->note,
                ])->all(),
            ]);

        return Inertia::render('inventory/bills-of-material/index', [
            'boms' => $boms,
            // C-BOM-8. Stocked only, both ends: a service can neither be
            // produced nor consumed.
            'products' => Product::query()->where('is_active', true)->stocked()
                ->with('unitOfMeasure:id,abbreviation')
                ->orderBy('sku')
                ->get(['id', 'name', 'sku', 'unit_of_measure_id'])
                ->map(fn (Product $p) => [
                    'id' => $p->id,
                    'sku' => $p->sku,
                    'name' => $p->name,
                    'unit' => $p->unitOfMeasure?->abbreviation,
                ])->values(),
        ]);
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
