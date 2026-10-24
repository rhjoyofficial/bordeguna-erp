<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Corrections\ValidatesActionReason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\ProductFamilies;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductFamily;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Support\ProductFamilyOverview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ⭐ Initial plan B22 (Joy, 24 Oct 2026; R10, Q9 = A): product families. A
 * family groups separate sizes (Miniket Rice 1 kg, 25 kg, 50 kg bosta); each
 * size stays its own product with its own SKU, stock, batches and prices.
 *
 * Reads under inventory.reports.view (as the product list); every change
 * under inventory.catalog.manage (as editing a product). Delete follows Q5:
 * an unused family (no sizes) to the Recycle bin with a reason, the Owner
 * restores; a family with sizes is refused until its sizes leave.
 */
class ProductFamilyController extends Controller
{
    use ValidatesActionReason;

    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString();

        $families = ProductFamily::query()
            ->with(['category:id,name', 'brand:id,name'])
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        $totals = ProductFamilyOverview::totals($families->getCollection()->pluck('id'));

        return Inertia::render('inventory/product-families/index', [
            'families' => $families->through(fn (ProductFamily $family) => [
                'id' => $family->id,
                'name' => $family->name,
                'sku' => $family->sku,
                'attribute_name' => $family->attribute_name,
                'category' => $family->category?->name,
                'brand' => $family->brand?->name,
                'sizes' => $totals[$family->id]['sizes'] ?? 0,
                'on_hand' => $totals[$family->id]['on_hand'] ?? '-',
                'stock_value' => $totals[$family->id]['value'] ?? '0.00',
            ]),
            'filters' => ['search' => $search],
            'can' => ['manage' => $request->user()->can('inventory.catalog.manage')],
        ]);
    }

    public function show(Request $request, ProductFamily $family): Response
    {
        $family->load(['category:id,name', 'brand:id,name']);
        $template = $family->templateSize();
        $totals = ProductFamilyOverview::totals([$family->id])[$family->id] ?? ['sizes' => 0, 'on_hand' => '-', 'value' => '0.00'];
        $whyNotDelete = $family->whyNotRemovable();

        return Inertia::render('inventory/product-families/show', [
            'family' => [
                'id' => $family->id,
                'name' => $family->name,
                'sku' => $family->sku,
                'attribute_name' => $family->attribute_name,
                'description' => $family->description,
                'category_id' => $family->category_id,
                'category' => $family->category?->name,
                'brand_id' => $family->brand_id,
                'brand' => $family->brand?->name,
                // Which list the family lives on: a raw material family on Raw materials.
                'kind' => $template?->type === ProductType::RawMaterial ? 'raw' : 'products',
            ],
            'sizes' => ProductFamilyOverview::sizes($family),
            'totals' => $totals,
            'price_groups' => ProductFamilyOverview::priceGroups(),
            // What "Add a size" copies, so the popup can say it; an empty
            // family asks for the unit and the kind instead.
            'template' => $template === null ? null : [
                'name' => $template->name,
                'unit' => $template->unitOfMeasure?->abbreviation,
                'type' => $template->type?->value,
            ],
            'units' => $template === null ? UnitOfMeasure::orderBy('name')->get(['id', 'name', 'abbreviation']) : [],
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'brands' => Brand::where('is_active', true)->orWhere('id', $family->brand_id)->orderBy('name')->get(['id', 'name']),
            'can' => [
                'manage' => $request->user()->can('inventory.catalog.manage'),
                'set_prices' => $request->user()->can('sales.prices.manage'),
                'delete' => $whyNotDelete === null,
                'delete_reason' => $whyNotDelete,
            ],
        ]);
    }

    /** "Make it a family": this single product becomes the first size. */
    public function make(Request $request, Product $product, ProductFamilies $families): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:100'],
            'attribute_name' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:2000'],
            'value' => ['required', 'string', 'max:60'],
        ], $this->messages());

        $family = $families->make($product, $data);

        return redirect()->route('inventory.product-families.show', $family)
            ->with('success', "{$family->name} is now a family, with {$product->name} as its first size.");
    }

    /** "Join a family": an existing single product becomes one more size. */
    public function join(Request $request, Product $product, ProductFamilies $families): RedirectResponse
    {
        $data = $request->validate([
            'product_family_id' => ['required', 'integer', Rule::exists('product_families', 'id')
                ->where('business_id', app(BusinessContext::class)->id())->whereNull('deleted_at')],
            'value' => ['required', 'string', 'max:60'],
        ], $this->messages());

        $family = ProductFamily::findOrFail($data['product_family_id']);
        $families->join($product, $family, $data['value']);

        return back()->with('success', "{$product->name} is now a size of {$family->name}.");
    }

    /** "Leave family": the size becomes a single product again. Nothing else moves. */
    public function leave(Product $product, ProductFamilies $families): RedirectResponse
    {
        $name = $product->family?->name;
        $families->leave($product);

        return back()->with('success', "{$product->name} left {$name}. It is a single product again, with its stock and prices.");
    }

    /** "Add a size": a new product, pre-filled from the family, with its own SKU. */
    public function storeSize(Request $request, ProductFamily $family, ProductFamilies $families): RedirectResponse
    {
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'value' => ['required', 'string', 'max:60'],
            'sku' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'mrp' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            // Asked only when the family has no size to copy them from.
            'unit_of_measure_id' => ['nullable', 'integer', Rule::exists('units_of_measure', 'id')->where('business_id', $businessId)],
            'type' => ['nullable', new Enum(ProductType::class)],
        ], $this->messages());

        $product = $families->addSize($family, $data);

        return back()->with('success', "{$product->name} ({$product->sku}) added as a size of {$family->name}.");
    }

    public function update(Request $request, ProductFamily $family): RedirectResponse
    {
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('business_id', $businessId)],
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')->where('business_id', $businessId)],
            'attribute_name' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:2000'],
        ], $this->messages());

        $data['sku'] = strtoupper(trim($data['sku']));
        $data['attribute_name'] = trim((string) ($data['attribute_name'] ?? '')) ?: ProductFamily::DEFAULT_ATTRIBUTE;

        DB::transaction(function () use ($family, $data) {
            $current = ProductFamily::query()->whereKey($family->id)->lockForUpdate()->firstOrFail();

            if (ProductFamily::query()->where('sku', $data['sku'])->whereKeyNot($current->id)->exists()) {
                throw ValidationException::withMessages(['sku' => "Another family already uses the SKU {$data['sku']}."]);
            }

            // ⚠ The family only. Its sizes keep their own category, brand and
            // SKU: the family's are what a NEW size starts with.
            $current->update($data);
        });

        return back()->with('success', "{$data['name']} saved.");
    }

    /** Q5: an unused family to the Recycle bin with a reason; one with sizes is refused. */
    public function destroy(Request $request, ProductFamily $family): RedirectResponse
    {
        $request->validate($this->actionReasonRules('deletion'), $this->actionReasonMessages());

        DB::transaction(fn () => ProductFamily::query()->whereKey($family->getKey())->lockForUpdate()->firstOrFail()
            ->recycle($request->user(), $this->actionReason($request)));

        return redirect()->route('inventory.product-families.index')
            ->with('success', "{$family->name} moved to the Recycle bin. The Owner can restore it.");
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'value.required' => 'Write the size, for example 25 kg.',
            'sku.required' => 'Write the SKU.',
            'name.required' => 'Write the name.',
            'product_family_id.required' => 'Choose the family.',
            'product_family_id.exists' => 'Choose one of this business\'s families.',
        ];
    }
}
