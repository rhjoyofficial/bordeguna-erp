<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\TaxClass;
use App\Modules\Accounting\Support\Money;
use App\Modules\Commerce\Enums\AvailabilityPolicy;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\DuplicateKeys;
use App\Modules\Foundation\Support\SaveAnyway;
use App\Modules\Inventory\Corrections\ProductDuplicateRule;
use App\Modules\Inventory\Enums\MaterialKind;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Enums\WeightSource;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductFamily;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\ProductFamilyOverview;
use App\Modules\Inventory\Support\ProductLifetime;
use App\Modules\Inventory\Support\ProductProfile;
use App\Modules\Inventory\Support\ProductStockCard;
use App\Modules\Inventory\Support\ProductWeight;
use App\Modules\Inventory\Support\SkuProposal;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Enums\PricingMode;
use App\Support\Lifetime\LifetimeCsv;
use App\Support\Lifetime\LifetimeRange;
use App\Support\Traceability\BatchTrace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            // ⭐ B4 (R7): "Made here" is a recipe in use, never a flag typed by hand.
            ->withExists(['billsOfMaterial as made_here' => fn ($q) => $q->where('is_active', true)])
            ->with('defaultSupplier:id,name')
            ->withCount(['batches as expired_batches_count' => fn ($q) => $q->expired()->where('current_quantity', '>', 0)])
            // ⚠ Bug hunt 16 Oct 2026: on hand and its value in the page's one
            // query, instead of three queries per row (about 45 a page).
            ->withSum(['batches as active_quantity' => fn ($q) => $q->where('status', StockBatchStatus::Active)], 'current_quantity')
            ->withSum(['batches as active_value' => fn ($q) => $q->where('status', StockBatchStatus::Active)], DB::raw('current_quantity * unit_cost'))
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
            // ⭐ Initial plan B22 (24 Oct 2026; R10): the sizes of a family sit
            // together under its name, in the order they were added; a single
            // product sorts by its own name among them, as it always did.
            ->with('family:id,name,sku,attribute_name')
            ->orderByRaw('COALESCE((SELECT product_families.name FROM product_families WHERE product_families.id = products.product_family_id), products.name)')
            ->orderBy('product_family_id')
            ->orderBy('products.id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Product $product) => [
                'id' => $product->id,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'name' => $product->name,
                'mrp' => $product->mrp === null ? null : (string) $product->mrp,
                // ⭐ Phase 2 / 1B (17 Oct 2026): how it is priced, and this SKU's
                // market rule overrides (null = inherits the category's).
                'pricing_mode' => $product->pricingMode()->value,
                'market_markup_percent' => $product->market_markup_percent === null ? null : (string) $product->market_markup_percent,
                'market_min_margin_percent' => $product->market_min_margin_percent === null ? null : (string) $product->market_min_margin_percent,
                'market_rounding_step' => $product->market_rounding_step === null ? null : (string) $product->market_rounding_step,
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
                'shelf_life_days' => $product->shelf_life_days,
                // Phase 1 Part B (17 Oct 2026): whether a count unit may be split.
                'allows_fractional_stock' => (bool) $product->allows_fractional_stock,
                'current_quantity' => $product->type->isStocked() ? Product::plainQuantity((string) ($product->active_quantity ?? '0')) : null,
                'average_unit_cost' => $product->type->isStocked() && (float) $product->active_quantity > 0
                    ? (string) ((float) $product->active_value / (float) $product->active_quantity)
                    : null,
                // The Low chip's rule (lowStockProductIds): held, and at or
                // below the threshold. Nothing on hand is not "low" here; the
                // pill used to say it was while the chip did not count it.
                'is_below_threshold' => (float) $product->active_quantity > 0
                    && Product::stockLevelIsLow($product->low_stock_threshold, (float) $product->active_quantity),
                // Dated batches past their date and still held (the expiry screen's rule).
                'expired_batches_count' => (int) $product->expired_batches_count,
                // ⚠ Locks the unit and the service choice in the edit dialog.
                'has_stock_history' => (bool) $product->movements_exists,
                // ⭐ Initial plan B4 (24 Oct 2026).
                'can_be_sold' => $product->canBeSold(),
                'material_kind' => $product->material_kind?->value,
                'default_supplier_id' => $product->default_supplier_id,
                'default_supplier' => $product->defaultSupplier?->name,
                'made_here' => (bool) $product->made_here,
                // ⭐ B22: the family this row is a size of, and which size.
                'family' => $product->family === null ? null : [
                    'id' => $product->family->id,
                    'name' => $product->family->name,
                    'sku' => $product->family->sku,
                    'attribute_name' => $product->family->attribute_name,
                    'value' => $product->family_attribute_value,
                ],
            ]);

        return Inertia::render('inventory/products/index', [
            'products' => $products,
            // ⭐ B22: each family's totals over ALL its sizes (not only this
            // page's): sizes, on hand per unit, value.
            'family_totals' => (object) ProductFamilyOverview::totals(collect($products->items())->pluck('family.id')),
            'counts' => $counts,
            // The market defaults ride along so the dialog can say what a
            // market product inherits when its own boxes are blank.
            'categories' => Category::orderBy('name')->get(['id', 'name', 'market_markup_percent', 'market_min_margin_percent', 'market_rounding_step']),
            // ⚠ Active brands only in the picker. A retired brand must stay
            // readable on the products that already carry it (which is why
            // brand_id is RESTRICT on delete) without being offered for new ones.
            'brands' => Brand::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            // `dimension` too (Phase 1 Part B): the form offers the fractional
            // switch only for a count unit and names the stock unit as locked.
            'units' => UnitOfMeasure::orderBy('name')->get(['id', 'name', 'abbreviation', 'dimension']),
            // ⛔ Offered so the catalogue can be classified ahead of the VAT
            // switch. Choosing one changes nothing today; the engine is dormant.
            'taxClasses' => TaxClass::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'filters' => $filters,
            'kind' => $kind,
            'can_manage_weights' => $request->user()->can('inventory.catalog.manage'),
            // ⭐ B4 (R22): who the product's usual vendor may be. Active only.
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
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
    /**
     * ⭐ THE PRODUCT PAGE (PR5, 18 Oct 2026). Tally's stock item: where the
     * stock is, what it cost, the stock card (every movement, running
     * quantity and value, each row opening its document), batches, purchase
     * and sales history, cost history. Reads only; edits stay in the list's
     * popup.
     */
    public function show(Request $request, Product $product): Response
    {
        $product->load(['category:id,name', 'brand:id,name', 'unitOfMeasure:id,name,abbreviation', 'defaultSupplier:id,name']);
        $viewer = $request->user();
        $profile = new ProductProfile($product, $viewer);
        $range = LifetimeRange::fromRequest($request);
        $lifetime = new ProductLifetime($product, $viewer, $range);
        $warehouseId = $request->integer('warehouse') ?: null;
        $average = $product->averageUnitCost();
        $onHand = $product->currentStockQuantity();

        return Inertia::render('inventory/products/show', [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'type' => $product->type?->value,
                'category' => $product->category?->name,
                'brand' => $product->brand?->name,
                'unit' => $product->unitOfMeasure?->abbreviation,
                'is_active' => (bool) $product->is_active,
                'tracks_expiry' => (bool) $product->tracks_expiry,
                'low_stock_threshold' => $product->low_stock_threshold,
                // ⭐ Initial plan B4 (24 Oct 2026; R7, R17, R18, R22).
                'can_be_sold' => $product->canBeSold(),
                'material_kind' => $product->material_kind?->value,
                'made_here' => $product->billsOfMaterial()->where('is_active', true)->exists(),
                'default_supplier' => $product->defaultSupplier === null ? null : ['id' => $product->defaultSupplier->id, 'name' => $product->defaultSupplier->name],
            ],
            'summary' => [
                'on_hand' => BatchTrace::qty((float) $onHand),
                'average_cost' => $average === null ? null : Money::ofTaka(number_format((float) $average, 4, '.', ''))->format(),
                'stock_value' => $average === null ? '0.00' : Money::ofProduct($onHand, number_format((float) $average, 4, '.', ''))->format(),
            ],
            'by_warehouse' => $profile->byWarehouse(),
            'last_purchase' => $profile->lastPurchase(),
            'selling' => $profile->sellingPrices(),
            'recipe' => $profile->recipe(),
            'suppliers' => $profile->suppliers(),
            'card' => ProductStockCard::for($product, $warehouseId, $request->integer('card_page', 1), '/inventory/products/'.$product->id, $viewer),
            'warehouse' => $warehouseId,
            'warehouses' => Warehouse::query()->orderBy('name')->get(['id', 'name']),
            'batches' => $profile->batches(),
            'purchases' => $profile->purchases(),
            'sales' => $profile->sales(),
            'costs' => $profile->costHistory(),
            // ⭐ Initial plan B22 (24 Oct 2026; R10): the family this product is
            // a size of, or, for a single product, the families it may join
            // (the same kind only) for "Join a family".
            'family' => $this->familyOf($product),
            'families' => $product->product_family_id === null && ($viewer?->can('inventory.catalog.manage') ?? false)
                ? $this->familiesToJoin($product)
                : [],
            // ⭐ B23 lifetime (25 Oct 2026; R13, R76): totals, money, runs,
            // "used in" and profit per batch, all time unless narrowed.
            // Closures, so paging the stock card does not read them again.
            'range' => $range->toArray(),
            'lifetime' => fn () => [
                'totals' => $lifetime->totals(),
                'write_offs' => $lifetime->writeOffs(),
                'money' => $lifetime->money(),
                'by_supplier' => $lifetime->bySupplier(),
                'runs' => $lifetime->runs(),
                'used_in' => $lifetime->usedIn(),
                'batches' => $lifetime->batchProfit(),
                'sees_margin' => $lifetime->seesMargin(),
            ],
            'can' => [
                'manage' => $viewer?->can('inventory.catalog.manage') ?? false,
                'see_recipes' => $viewer?->can('inventory.bom.manage') ?? false,
            ],
        ]);
    }

    /** @return array<string, mixed>|null */
    private function familyOf(Product $product): ?array
    {
        $family = $product->product_family_id === null ? null : $product->family()->first();

        if ($family === null) {
            return null;
        }

        return [
            'id' => $family->id,
            'name' => $family->name,
            'attribute_name' => $family->attribute_name,
            'value' => $product->family_attribute_value,
            'sizes' => Product::query()->where('product_family_id', $family->id)
                ->orderBy('id')->get(['id', 'name', 'family_attribute_value'])
                ->map(fn (Product $size) => ['id' => $size->id, 'name' => $size->name, 'value' => $size->family_attribute_value])
                ->values()->all(),
        ];
    }

    /**
     * Families a single product may join: an empty one, or one whose sizes
     * are the same kind (a product family never takes a raw material).
     *
     * @return list<array<string, mixed>>
     */
    private function familiesToJoin(Product $product): array
    {
        $kinds = Product::query()->whereNotNull('product_family_id')
            ->selectRaw('product_family_id, min(type) as kind, count(*) as sizes')
            ->groupBy('product_family_id')
            ->get()
            ->keyBy('product_family_id');

        return ProductFamily::query()->orderBy('name')->get(['id', 'name', 'sku', 'attribute_name'])
            ->filter(fn (ProductFamily $family) => ! isset($kinds[$family->id]) || $kinds[$family->id]->kind === $product->type?->value)
            ->map(fn (ProductFamily $family) => [
                'id' => $family->id,
                'name' => $family->name,
                'sku' => $family->sku,
                'attribute_name' => $family->attribute_name,
                'sizes' => (int) ($kinds[$family->id]->sizes ?? 0),
            ])
            ->values()->all();
    }

    /**
     * ⭐ B23 (25 Oct 2026): the profit-per-batch list as a spreadsheet, for
     * the same window as the page. Revenue and margin stay empty for a reader
     * who may not see sales costs, exactly as on the screen.
     */
    public function lifetimeExport(Request $request, Product $product): StreamedResponse
    {
        $lifetime = new ProductLifetime($product, $request->user(), LifetimeRange::fromRequest($request));
        $rows = collect($lifetime->batchProfit())->map(fn (array $row) => [
            $row['batch_number'] ?? '#'.$row['id'],
            $row['received_at'],
            $row['warehouse'],
            $row['source'],
            $row['document'] === null ? null : $row['document']['label'].' '.$row['document']['number'],
            $row['supplier']['name'] ?? null,
            $row['received'],
            $row['unit_cost'],
            $row['cost'],
            $row['sold'],
            $row['cost_of_sold'],
            $row['revenue'],
            $row['margin'],
            $row['on_hand'],
        ]);

        return LifetimeCsv::download(
            'product-'.($product->sku ?: $product->id).'-batches.csv',
            ['Batch', 'Received', 'Warehouse', 'Source', 'Document', 'Supplier', 'Quantity in', 'Unit cost', 'Cost', 'Sold', 'Cost of sold', 'Revenue', 'Margin', 'On hand'],
            $rows,
        );
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $data = $this->withWeightSource($this->validated($request));
        // ⭐ B13 (24 Oct 2026; FINAL-PLAN 5): the same name, or a SKU that
        // differs only by case or dashes, asks "Possible duplicate" first.
        $ack = SaveAnyway::ask(app(ProductDuplicateRule::class), $data, $request, (int) app(BusinessContext::class)->id(), 'Possible duplicate. Is this a different product?');

        $product = DB::transaction(function () use ($data, $ack) {
            $product = Product::create($data + ['is_active' => true]);
            SaveAnyway::record($ack, $product);

            return $product;
        });

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

        $ack = null;

        if (DuplicateKeys::name($data['name'] ?? null) !== DuplicateKeys::name($product->name) || DuplicateKeys::code($data['sku'] ?? null) !== DuplicateKeys::code($product->sku)) {
            $ack = SaveAnyway::ask(app(ProductDuplicateRule::class), $data, $request, (int) $product->business_id, 'Possible duplicate. Is this a different product?', [$product->getMorphClass().':'.$product->id]);
        }

        DB::transaction(function () use ($product, $data, $ack) {
            $product->update($data);
            SaveAnyway::record($ack, $product);
        });

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

        // ⭐ 17 Oct 2026 (practice run F5): the toast names the item, so the
        // person sees WHICH row came back on, not only that a row changed.
        return back()->with('success', "{$product->name} switched back on.");
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

        $category = empty($data['category_id'])
            ? null
            : Category::whereKey($data['category_id'])->first(['code', 'name']);
        $categoryCode = $category?->code;

        // F7: a brand's own words in a product name are never its item.
        $brandWords = SkuProposal::brandWords(
            Brand::where('business_id', $businessId)->get(['name', 'code'])
                ->map(fn (Brand $b) => ['name' => $b->name, 'code' => $b->code])->all(),
        );

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
            $brandWords,
        );

        $proposal = new SkuProposal(
            fn (string $sku) => $sku !== $currentSku
                && Product::where('business_id', $businessId)->where('sku', $sku)->exists(),
            $known,
            $brandWords,
        );

        return response()->json($proposal->propose(
            $data['name'],
            ProductType::from($data['type']),
            $brandCode,
            $categoryCode,
            $category?->name,
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
            // ⭐ Phase 2 / 1B (17 Oct 2026): fixed_mrp or market. `sometimes`,
            // so an older form that never sends it cannot switch a product
            // back to fixed_mrp by omission. The three rule overrides are
            // optional and null means "inherit the category's". ⚠ A minimum
            // margin of 100% or more would divide by zero (C / (1 - g/100)).
            'pricing_mode' => ['sometimes', new Enum(PricingMode::class)],
            'market_markup_percent' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'market_min_margin_percent' => ['nullable', 'numeric', 'min:0', 'max:99.99'],
            'market_rounding_step' => ['nullable', 'numeric', 'gt:0', 'max:10000'],
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
            // ⭐ PR2 (18 Oct 2026): days from manufacture to expiry; blank = not known.
            'shelf_life_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            // ⭐ Phase 1 Part B (17 Oct 2026): a count stock unit is consumed in
            // whole numbers unless the product says it is really split.
            'allows_fractional_stock' => ['sometimes', 'boolean'],
            // ⚠ Sometimes, so an older form that never sends it cannot switch a
            // product off by omission; the dialog sends it to bring one back.
            'is_active' => ['sometimes', 'boolean'],
            // ⭐ Courier hub slice 3 (Joy, 11 Oct 2026): one stock unit as it
            // ships, in whole grams. Blank = not known (never zero).
            'weight_grams' => ['nullable', 'integer', 'min:1', 'max:'.ProductWeight::MAX_GRAMS],
            // ⭐ Initial plan B4 (Joy, 24 Oct 2026). `sometimes`, so an older
            // form that never sends them cannot flip them by omission: a new
            // product left without them takes the model's defaults (a raw
            // material not for sale, its kind from its category).
            'can_be_sold' => ['sometimes', 'boolean'],
            'material_kind' => ['nullable', new Enum(MaterialKind::class)],
            // ⚠ Scoped like every foreign key here, and an active supplier
            // only: a switched-off one is never proposed on a new purchase.
            // The one already saved may stay, so editing anything else on a
            // product whose vendor was switched off later still saves.
            'default_supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('business_id', $businessId)
                ->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $product?->default_supplier_id ?? 0))],
        ], [
            'default_supplier_id.exists' => 'Choose one of this business\'s active suppliers.',
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
