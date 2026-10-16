<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\TaxClass;
use App\Modules\Commerce\Enums\AvailabilityPolicy;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Enums\WeightSource;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Support\ProductWeight;
use App\Modules\Inventory\Support\SkuProposal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    /** The status chips above the list, each a `flag` filter value. */
    private const FLAGS = ['low', 'weight_missing', 'expired', 'inactive', 'service', 'no_brand'];

    public function index(Request $request): Response|RedirectResponse
    {
        // ⭐ 27 Sep 2026 (Joy): raw materials and products are kept SEPARATE. One
        // Product model still holds both (a `type` enum), but the screen shows
        // one or the other. ⭐ 15 Oct 2026 (Inventory redesign): the kind is
        // fixed by the ROUTE (`/inventory/raw-materials` sets it to raw), never
        // by a query string, because the sidebar and the tab row match on the
        // path alone. The old `?kind=raw` address redirects, filters kept.
        if ($request->route('kind') !== 'raw' && $request->query('kind') === 'raw') {
            return redirect()->route('inventory.raw-materials.index', $request->except('kind'));
        }

        $kind = $request->route('kind') === 'raw' ? 'raw' : 'products';

        $filters = [
            'search' => $request->string('search')->toString(),
            'type' => $request->string('type')->toString(),
            'category_id' => $request->string('category_id')->toString(),
            'brand_id' => $request->string('brand_id')->toString(),
            // ⭐ Courier hub slice 3: "which products still have no weight".
            'weight' => $request->string('weight')->toString() === 'missing' ? 'missing' : '',
            // ⭐ The status chips (15 Oct 2026): one state at a time.
            'flag' => in_array($request->string('flag')->toString(), self::FLAGS, true) ? $request->string('flag')->toString() : '',
        ];

        $kindQuery = fn () => Product::query()->when(
            $kind === 'raw',
            fn ($query) => $query->where('type', 'raw_material'),
            fn ($query) => $query->whereIn('type', ['finished_good', 'service']),
        );

        // ⚠ The chips count the whole kind, not the filtered page, so a person
        // reads "6 low stock" and then narrows to them. Low stock is the Low
        // stock screen's rule (held, and at or below the threshold); expired
        // is the expiry screen's (a dated batch past its date, still held).
        $lowIds = $this->lowStockProductIds();
        $expiredProducts = fn ($query) => $query->whereHas('batches', fn ($q) => $q->expired()->where('current_quantity', '>', 0));

        $counts = [
            'all' => $kindQuery()->count(),
            'low_stock' => $kindQuery()->where('is_active', true)->whereIn('id', $lowIds)->count(),
            'weight_missing' => $kindQuery()->where('is_active', true)->where('type', '!=', 'service')->whereNull('weight_grams')->count(),
            'expired' => $kindQuery()->where($expiredProducts)->count(),
            'inactive' => $kindQuery()->where('is_active', false)->count(),
            'service' => $kind === 'raw' ? 0 : $kindQuery()->where('type', 'service')->count(),
            'no_brand' => $kind === 'raw' ? 0 : $kindQuery()->where('type', 'finished_good')->whereNull('brand_id')->count(),
        ];

        $products = $kindQuery()
            ->with(['category', 'brand', 'unitOfMeasure', 'purchaseUnit'])
            ->withExists('movements')
            ->withCount(['batches as expired_batches_count' => fn ($q) => $q->expired()->where('current_quantity', '>', 0)])
            ->when($filters['flag'] === 'low', fn ($query) => $query->where('is_active', true)->whereIn('id', $lowIds))
            ->when($filters['flag'] === 'weight_missing', fn ($query) => $query->where('is_active', true)->where('type', '!=', 'service')->whereNull('weight_grams'))
            ->when($filters['flag'] === 'expired', fn ($query) => $query->where($expiredProducts))
            ->when($filters['flag'] === 'inactive', fn ($query) => $query->where('is_active', false))
            ->when($filters['flag'] === 'service', fn ($query) => $query->where('type', 'service'))
            ->when($filters['flag'] === 'no_brand', fn ($query) => $query->where('type', 'finished_good')->whereNull('brand_id'))
            ->when($filters['search'], fn ($query, $search) => $query->where(
                fn ($q) => $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    // ⚠ A scanned barcode is the fastest way anybody looks a
                    // product up, so it has to be searchable or it is decoration.
                    ->orWhere('barcode', 'like', "%{$search}%")
            ))
            ->when($filters['type'], fn ($query, $type) => $query->where('type', $type))
            ->when($filters['category_id'], fn ($query, $categoryId) => $query->where('category_id', $categoryId))
            // ⚠ "none" is a real filter, not an empty one: "which finished goods
            // still have no brand" is the question D-3 leaves open, and without
            // this the warning has no screen to act on.
            ->when($filters['brand_id'], fn ($query, $brandId) => $brandId === 'none'
                ? $query->whereNull('brand_id')
                : $query->where('brand_id', $brandId))
            ->when($filters['weight'] === 'missing', fn ($query) => $query->whereNull('weight_grams'))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Product $product) => [
                'id' => $product->id,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'name' => $product->name,
                'mrp' => $product->mrp === null ? null : (string) $product->mrp,
                'type' => $product->type->value,
                'category' => $product->category?->name,
                'brand' => $product->brand?->name,
                // ⚠ The ids, not only the display names: without them the edit
                // form cannot preselect what the product already is.
                'category_id' => $product->category_id,
                'brand_id' => $product->brand_id,
                'unit_of_measure_id' => $product->unit_of_measure_id,
                'tax_class_id' => $product->tax_class_id,
                'description' => $product->description,
                'unit' => $product->unitOfMeasure->abbreviation,
                // ⚠ Shown in the list, not only in the form. A product bought in
                // one unit and stocked in another behaves differently at every
                // goods receipt, and that has to be visible without opening it.
                'purchase_unit' => $product->purchaseUnit?->abbreviation,
                'low_stock_threshold' => $product->low_stock_threshold,
                // C-MFG-14: shown so the edit form can preselect the declared
                // content, exactly as the other ids above.
                'content_quantity' => $product->content_quantity,
                'content_unit_id' => $product->content_unit_id,
                'weight_grams' => $product->weight_grams,
                'weight_source' => $product->weight_source?->value,
                'is_active' => $product->is_active,
                'availability_override' => $product->availability_override?->value,
                'lead_time_days' => $product->lead_time_days,
                'tracks_expiry' => (bool) $product->tracks_expiry,
                'shelf_life_warning_days' => $product->shelf_life_warning_days,
                'current_quantity' => $product->type->isStocked() ? $product->currentStockQuantity() : null,
                'average_unit_cost' => $product->type->isStocked() ? $product->averageUnitCost() : null,
                'is_below_threshold' => $product->isBelowLowStockThreshold(),
                // Dated batches past their date and still held (the expiry screen's rule).
                'expired_batches_count' => (int) $product->expired_batches_count,
                // ⚠ Locks the unit and the service choice in the edit dialog.
                'has_stock_history' => (bool) $product->movements_exists,
            ]);

        return Inertia::render('inventory/products/index', [
            'products' => $products,
            'counts' => $counts,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            // ⚠ Active brands only in the picker. A retired brand must stay
            // readable on the products that already carry it (which is why
            // brand_id is RESTRICT on delete) without being offered for new ones.
            'brands' => Brand::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'units' => UnitOfMeasure::orderBy('name')->get(['id', 'name', 'abbreviation']),
            // ⛔ Offered so the catalogue can be classified ahead of the VAT
            // switch. Choosing one changes nothing today; the engine is dormant.
            'taxClasses' => TaxClass::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'filters' => $filters,
            'kind' => $kind,
            'can_manage_weights' => $request->user()->can('inventory.catalog.manage'),
        ]);
    }

    /**
     * The products the Low stock screen lists: held, and at or below their
     * threshold. One grouped query, compared in PHP with THE rule
     * (Product::stockLevelIsLow), as the dashboard does.
     *
     * @return array<int, int>
     */
    private function lowStockProductIds(): array
    {
        $held = StockBatch::query()
            ->where('status', StockBatchStatus::Active)
            ->selectRaw('product_id, SUM(current_quantity) as quantity')
            ->groupBy('product_id')
            ->pluck('quantity', 'product_id');

        return Product::query()
            ->whereNotNull('low_stock_threshold')
            ->whereIn('id', $held->keys())
            ->get(['id', 'low_stock_threshold'])
            ->filter(fn (Product $product) => (float) $held[$product->id] > 0
                && Product::stockLevelIsLow($product->low_stock_threshold, (float) $held[$product->id]))
            ->pluck('id')
            ->all();
    }

    /**
     * ⚠ Answers JSON to the product dialog (21 Sep 2026), which saves the
     * product first and then its prices beside it (Sales, a separate module),
     * and needs the new product's id to do that. Any other caller still gets the
     * redirect it always got.
     */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $data = $this->withWeightSource($this->validated($request));

        $product = Product::create($data + ['is_active' => true]);

        return $request->wantsJson()
            ? response()->json(['id' => $product->id, 'name' => $product->name], 201)
            : back()->with('success', 'Product created.');
    }

    public function update(Request $request, Product $product): RedirectResponse|JsonResponse
    {
        $data = $this->withWeightSource($this->validated($request, $product), $product);

        // ⚠⚠ Once stock has moved, the stock unit and the stocked/service split
        // are fixed: changing them relabels or strands every quantity already
        // recorded. The catalogue import refuses the same changes.
        $locked = $product->stockLockedChanges($data['unit_of_measure_id'], $data['type']);

        if ($locked !== []) {
            throw ValidationException::withMessages($locked);
        }

        $product->update($data);

        return $request->wantsJson()
            ? response()->json(['id' => $product->id, 'name' => $product->name])
            : back()->with('success', 'Product updated.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $product->update(['is_active' => false]);

        return back()->with('success', 'Product deactivated.');
    }

    /**
     * ⚠ Deactivation used to be a one-way door: a raw material switched off by a
     * stray click (15 Oct 2026) could not be switched back on from any screen,
     * and its SKU blocked re-creating it. Nothing else about the product moves.
     */
    public function activate(Product $product): RedirectResponse
    {
        $product->update(['is_active' => true]);

        return back()->with('success', $product->type === ProductType::RawMaterial ? 'Raw material reactivated.' : 'Product reactivated.');
    }

    /**
     * C-SKU-2. Propose a SKU for what is currently typed into the form.
     *
     * ⚠⚠ THIS WRITES NOTHING. It answers with a string the screen drops into an
     * editable field, and the user may ignore it. The SKU is the identity every
     * importer matches on, so nothing may assign one on a person's behalf.
     *
     * ⚠ Brand and category are read through the scoped models, so another
     * business's brand code can never leak into this business's SKU.
     *
     * ⚠⚠ A GET, and that is not a style choice: see the note on the route. A
     * mutating verb here would be the one write-shaped request that escapes
     * EnsurePageBusinessMatches, because the stamp rides on Inertia's visit hook
     * and this call is a plain fetch.
     */
    public function skuProposal(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', new Enum(ProductType::class)],
            'brand_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            // The product being edited, so its own SKU does not count as taken
            // and the proposal does not come back numbered against itself.
            'product_id' => ['nullable', 'integer'],
        ]);

        $businessId = app(BusinessContext::class)->id();

        $brandCode = empty($data['brand_id'])
            ? null
            : Brand::whereKey($data['brand_id'])->value('code');

        $categoryCode = empty($data['category_id'])
            ? null
            : Category::whereKey($data['category_id'])->value('code');

        $currentSku = empty($data['product_id'])
            ? null
            : Product::whereKey($data['product_id'])->value('sku');

        // ⚠⚠ Learn from this business's own catalogue before proposing anything.
        // Beetroot is BET here, Black is BLK and Beef is BEF - abbreviations no
        // rule reproduces. Proposing BLA next to the live BLK would split one
        // product into two families.
        $known = SkuProposal::learnFrom(
            Product::where('business_id', $businessId)
                ->get(['name', 'sku'])
                ->map(fn (Product $p) => ['name' => $p->name, 'sku' => $p->sku])
                ->all(),
        );

        $proposal = new SkuProposal(
            fn (string $sku) => $sku !== $currentSku
                && Product::where('business_id', $businessId)->where('sku', $sku)->exists(),
            $known,
        );

        return response()->json($proposal->propose(
            $data['name'],
            ProductType::from($data['type']),
            $brandCode,
            $categoryCode,
        ));
    }

    /**
     * ⚠⚠ The screen and the catalogue import must produce the SAME product. A
     * product typed in by hand must not be structurally different from an
     * imported one - otherwise the fields only the import can reach are blank
     * on exactly the records somebody created in a hurry. Enforced by the
     * structural guard in ProductScreenTest, which reads Product::getFillable().
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Product $product = null): array
    {
        $businessId = $product?->business_id ?? app(BusinessContext::class)->id();

        // ⚠ A blank barcode box must reach the database as NULL, never as ''.
        // The unique index is on (business_id, barcode), and both MySQL and
        // SQLite permit many NULLs but only one '' - so a business that uses no
        // barcodes at all would fail on a duplicate key at its SECOND product.
        // Nothing here does that conversion: the framework's global
        // ConvertEmptyStringsToNull middleware already has, before validation
        // runs. That is the whole defence on this path, which is why
        // ProductScreenTest pins the behaviour rather than the mechanism.
        // ⚠⚠ The catalogue importer does NOT pass through that middleware and
        // must nullify its own blanks.

        return $request->validate([
            'sku' => [
                'required', 'string', 'max:100',
                Rule::unique('products', 'sku')->where('business_id', $businessId)->ignore($product),
            ],
            // ⚠ The number printed on the pack, kept apart from the SKU: a
            // product's permanent code is ours, its barcode is the
            // manufacturer's and may be absent, reused or corrected.
            'barcode' => [
                // Printable ASCII only: a label (Code 128) can print nothing else
                // (bug hunt 16 Oct 2026).
                'nullable', 'string', 'max:100', 'regex:/^[\x20-\x7E]+$/',
                Rule::unique('products', 'barcode')->where('business_id', $businessId)->ignore($product),
            ],
            'name' => ['required', 'string', 'max:255'],
            // The printed MRP (21 Sep 2026): the consumer price when no Retail
            // price is set, and the base of any "% off MRP" price.
            'mrp' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'type' => ['required', new Enum(ProductType::class)],
            // ⚠⚠ Scoped to the business. A bare `exists:` accepts another
            // business's id, and unlike the sales and purchasing screens -
            // which re-fetch through findOrFail and so fail closed on the
            // global scope - these two are written straight onto the row by
            // Product::create(). An unscoped unit_of_measure_id therefore
            // persists, and then the index 500s, because the global scope
            // hides the row that `$product->unitOfMeasure->abbreviation` reads.
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('business_id', $businessId)],
            // ⚠⚠ Scoped for the same reason, and it matters more here than
            // anywhere: brand is the axis profit is reported along, so a product
            // pointing at another business's brand would quietly corrupt a
            // margin report rather than merely 500 a page.
            // ⚠ Nullable: raw materials have no brand (D-5).
            'brand_id' => ['nullable', Rule::exists('brands', 'id')->where('business_id', $businessId)],
            'unit_of_measure_id' => ['required', Rule::exists('units_of_measure', 'id')->where('business_id', $businessId)],
            // ⚠ Scoped like every other foreign key here. Nullable, and NULL is
            // NOT zero-rated: it means "not yet classified" and falls back to
            // the business default when the VAT engine is built (C-VAT-11).
            // ⛔ Classifying a product calculates nothing today - the engine is
            // dormant. It is allowed now so the catalogue can be classified
            // ahead of the switch rather than in a rush on the day.
            'tax_class_id' => ['nullable', Rule::exists('tax_classes', 'id')->where('business_id', $businessId)],
            'description' => ['nullable', 'string'],
            'low_stock_threshold' => ['nullable', 'numeric', 'min:0'],
            // C-MFG-14: the net content of ONE stock unit, declared with its
            // unit or not at all - a quantity with no unit measures nothing,
            // and a unit with no quantity is a promise. Purely a yield-check
            // input for production runs; nothing converts with it (C-MFG-2).
            'content_quantity' => ['nullable', 'numeric', 'gt:0', 'required_with:content_unit_id'],
            'content_unit_id' => [
                'nullable', 'required_with:content_quantity',
                Rule::exists('units_of_measure', 'id')->where('business_id', $businessId),
            ],
            // Website Phase 2, slice 2.1. ⚠ Only `strict` may be stored: an
            // override can NARROW what a channel sells, never widen it. A
            // product cannot be made to sell at zero on the counter, where the
            // customer is standing there, by editing the catalogue.
            'availability_override' => ['nullable', Rule::in([AvailabilityPolicy::Strict->value])],
            // ⚠ Null promises nothing. A zero would promise same-day.
            'lead_time_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            // ⭐ 26 Sep 2026 (Q8/Q9): batch + expiry required on receipt when on;
            // the shelf-life warning threshold, blank = 90 days.
            'tracks_expiry' => ['sometimes', 'boolean'],
            'shelf_life_warning_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            // ⚠ Sometimes, so an older form that never sends it cannot switch a
            // product off by omission; the dialog sends it to bring one back.
            'is_active' => ['sometimes', 'boolean'],
            // ⭐ Courier hub slice 3 (Joy, 11 Oct 2026): one stock unit as it
            // ships, in whole grams. Blank = not known (never zero).
            'weight_grams' => ['nullable', 'integer', 'min:1', 'max:'.ProductWeight::MAX_GRAMS],
        ]);
    }

    /**
     * Where the weight came from, decided here and never sent by the form.
     *
     * - A weight somebody typed or changed is `manual`.
     * - A new product with no weight but a mass net content (400 g) starts at
     *   that weight, `from_net_content`. A liquid is left empty: no density
     *   guess (Joy, 11 Oct 2026).
     * - A weight that came from net content follows the net content when that
     *   is corrected, until somebody types a weight of their own.
     * - Clearing the weight clears its source.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withWeightSource(array $data, ?Product $product = null): array
    {
        $typed = array_key_exists('weight_grams', $data) ? $data['weight_grams'] : ($product?->weight_grams);
        $typed = $typed === null ? null : (int) $typed;
        $unchanged = $product !== null && $typed === $product->weight_grams;

        if ($typed !== null && ! $unchanged) {
            return array_merge($data, ['weight_grams' => $typed, 'weight_source' => WeightSource::Manual]);
        }

        $followsContent = $product === null
            ? $typed === null
            : $unchanged && ($product->weight_grams === null || $product->weight_source === WeightSource::FromNetContent);

        if ($followsContent) {
            $unitId = array_key_exists('content_unit_id', $data) ? $data['content_unit_id'] : $product?->content_unit_id;
            $quantity = array_key_exists('content_quantity', $data) ? $data['content_quantity'] : $product?->content_quantity;
            $derived = ProductWeight::fromNetContent($quantity, $unitId === null ? null : UnitOfMeasure::find($unitId));

            if ($derived !== null) {
                return array_merge($data, ['weight_grams' => $derived, 'weight_source' => WeightSource::FromNetContent]);
            }
        }

        if ($typed === null) {
            return array_merge($data, ['weight_grams' => null, 'weight_source' => null]);
        }

        return array_merge($data, ['weight_grams' => $typed]);
    }
}
