<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Accounting\Models\TaxClass;
use App\Modules\Commerce\Enums\AvailabilityPolicy;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Enums\MaterialKind;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Enums\WeightSource;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Enums\PricingMode;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Product extends Model
{
    use BelongsToBusiness, HasFactory, LogsActivity;

    protected $fillable = [
        'business_id',
        'category_id',
        'brand_id',
        'unit_of_measure_id',
        // ⚠ Fillable, unlike the business-level VAT switches. A product's tax
        // class is ordinary master data a manager edits on the product screen;
        // it decides nothing on its own while the engine is dormant.
        'tax_class_id',
        'sku',
        'barcode',
        'name',
        // The printed maximum retail price (21 Sep 2026). What a consumer or a
        // retail shop pays when no price has been set for their group, and the
        // base a "% off MRP" price is worked out from. Null = not known yet.
        'mrp',
        // ⭐ Phase 2 / 1B (17 Oct 2026, ChatGPT Q4): `fixed_mrp` prices from
        // the MRP and the price list; `market` prices from today's confirmed
        // price, proposed at goods receipt from the batch cost. ⚠ The MODE
        // decides: a market product never falls back to its MRP. The three
        // market_* columns are this SKU's override of its category's rules
        // (null = inherit). Definitions in Sales\Support\ProductPricing.
        'pricing_mode',
        'market_markup_percent',
        'market_min_margin_percent',
        'market_rounding_step',
        'type',
        'description',
        'low_stock_threshold',
        // C-MFG-14: the net content of ONE stock unit, in a declared unit - a
        // 5 L pack contains 5 of "Litre". PURELY a yield-check input for
        // production runs: no arithmetic anywhere converts with it (C-MFG-2),
        // and null means the check is silently off for this product.
        'content_quantity',
        'content_unit_id',
        // ⭐ Courier hub slice 3 (Joy, 11 Oct 2026): the weight of ONE stock
        // unit as it ships, in whole grams, and where that number came from.
        // Null = not known, and ⚠ never read as zero. A parcel's weight is
        // worked out from these (Sales\Support\ParcelWeight).
        'weight_grams',
        'weight_source',
        'is_active',
        // Website Phase 2, slice 2.1. ⭐ ON THE PRODUCT MASTER, NOT THE STORE:
        // "this cannot be made to order" is true at the counter, on the phone
        // and on the web alike. Null follows the channel's own policy.
        'availability_override',
        // The promised lead time for a made-to-order sale. ⚠ Null promises
        // nothing, and a page with nothing to promise says nothing (Q7 open).
        'lead_time_days',
        // ⭐ 26 Sep 2026 (Joy, Q8/Q9): a receipt of this product MUST carry a
        // batch and an expiry; and warns when fewer days than this are left.
        'tracks_expiry',
        'shelf_life_warning_days',
        // ⭐ PR2 (18 Oct 2026): days the product keeps from manufacture; a new
        // batch's expiry defaults from it (BatchNumbers::label). Null = unknown.
        'shelf_life_days',
        // ⭐ 17 Oct 2026 (Phase 1 Part B): a COUNT stock unit (pcs, jar, box)
        // is consumed in whole numbers unless this says the product really is
        // split (half a jar of bulk pickle). Off by default; irrelevant for a
        // mass or volume unit, which is always fractional.
        'allows_fractional_stock',
        // ⭐ Initial plan B4 (Joy, 24 Oct 2026; R18): "Is sales item". Off and
        // no sales path takes it; the server refuses it (NotForSale), the
        // pickers only follow. Off by default for raw materials and packaging.
        'can_be_sold',
        // ⭐ B4 (R17): raw | packaging, for a raw material only (MaterialKind).
        'material_kind',
        // ⭐ B4 (R22): the usual vendor, proposed on a new purchase. A proposal
        // only: the purchase still names its own supplier.
        'default_supplier_id',
    ];

    /** Joy, 26 Sep 2026 (Q9): warn below 90 days unless the product says otherwise. */
    public const DEFAULT_SHELF_LIFE_WARNING_DAYS = 90;

    public function shelfLifeWarningDays(): int
    {
        return $this->shelf_life_warning_days ?? self::DEFAULT_SHELF_LIFE_WARNING_DAYS;
    }

    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'mrp' => 'decimal:4',
            'market_markup_percent' => 'decimal:4',
            'market_min_margin_percent' => 'decimal:4',
            'market_rounding_step' => 'decimal:2',
            'low_stock_threshold' => 'decimal:4',
            'content_quantity' => 'decimal:4',
            'weight_grams' => 'integer',
            'weight_source' => WeightSource::class,
            'is_active' => 'boolean',
            'availability_override' => AvailabilityPolicy::class,
            'lead_time_days' => 'integer',
            'tracks_expiry' => 'boolean',
            'shelf_life_warning_days' => 'integer',
            'shelf_life_days' => 'integer',
            'allows_fractional_stock' => 'boolean',
            'can_be_sold' => 'boolean',
            'material_kind' => MaterialKind::class,
            'default_supplier_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // ⭐ B4 (24 Oct 2026): the defaults every path gets, the screen, the
        // catalogue import, quick create and a seeder alike. A raw material
        // or packaging item is not for sale unless somebody says it is; a
        // finished good or a service is. Only a raw material has a kind, and
        // one nobody chose follows its category or PK- SKU, the rule the SKU
        // proposal and the recipe screen already used (F4, F10).
        static::creating(function (Product $product) {
            if (! array_key_exists('can_be_sold', $product->getAttributes())) {
                $product->can_be_sold = $product->type !== ProductType::RawMaterial;
            }
        });

        static::saving(function (Product $product) {
            if ($product->type !== ProductType::RawMaterial) {
                $product->material_kind = null;
            } elseif ($product->material_kind === null) {
                $product->material_kind = self::guessMaterialKind(
                    $product->category_id === null ? null : Category::query()->whereKey($product->category_id)->value('name'),
                    $product->sku,
                );
            }
        });

        // ⚠⚠ THE STOCK-UNIT LOCK AT THE CHOKEPOINT (17 Oct 2026, Phase 1 Part
        // B). The product screen and the catalogue import already refuse a
        // unit change once stock has moved; this makes the MODEL refuse it,
        // so no third path (a seeder, a console fix, a future import) can
        // relabel 10 kg as 10 pcs with every journal still balancing.
        static::saving(function (Product $product) {
            if ($product->exists && $product->isDirty('unit_of_measure_id') && $product->movements()->exists()) {
                throw new DomainException(
                    "{$product->sku} already has stock movements, so its stock unit cannot change. Create a new product for the new unit."
                );
            }
        });
    }

    /**
     * How this product is priced. ⚠ A null column (a row older than the
     * column, or a seeder that never said) is fixed_mrp: the engine as it
     * always was. Only an explicit `market` switches the MRP fallback off.
     */
    public function pricingMode(): PricingMode
    {
        return PricingMode::tryFrom((string) $this->pricing_mode) ?? PricingMode::FixedMrp;
    }

    public function isMarketPriced(): bool
    {
        return $this->pricingMode() === PricingMode::Market;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'sku', 'brand_id', 'category_id', 'low_stock_threshold', 'weight_grams', 'weight_source', 'is_active', 'pricing_mode', 'market_markup_percent', 'market_min_margin_percent', 'market_rounding_step', 'can_be_sold', 'material_kind', 'default_supplier_id', 'product_family_id', 'family_attribute_value'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * ⭐ Initial plan B22 (Joy, 24 Oct 2026; R10, Q9 = A): the family this
     * product is one size of, and which size (`family_attribute_value`).
     *
     * ⚠⚠ DELIBERATELY NOT FILLABLE. Only the family actions (Make it a family,
     * Add a size, Join a family, Leave family) set the pair, so the product
     * popup and the catalogue import can never half-join a product (a family
     * without a size, or a size without a family). ProductScreenTest's guard
     * reads $fillable, and this pair is not the screen's to set.
     */
    public function family(): BelongsTo
    {
        return $this->belongsTo(ProductFamily::class, 'product_family_id');
    }

    /** "Miniket Rice - 25 kg" for a size, the plain name for a single product. */
    public function pickerName(): string
    {
        if ($this->product_family_id === null) {
            return (string) $this->name;
        }

        $family = $this->relationLoaded('family') ? $this->family : $this->family()->first(['id', 'name']);

        return $family === null ? (string) $this->name : ProductFamily::sizeLabel($family->name, $this->family_attribute_value);
    }

    /**
     * ⭐ B22: the picker words for many products in ONE query: {product_id:
     * "Miniket Rice - 25 kg"} for the sizes among them. A single product is
     * not in the map; the caller keeps its own name.
     *
     * @param  iterable<int>  $productIds
     * @return array<int, string>
     */
    public static function familyLabels(iterable $productIds): array
    {
        $ids = collect($productIds)->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return static::query()
            ->whereIn('products.id', $ids)
            ->whereNotNull('products.product_family_id')
            ->join('product_families', 'product_families.id', '=', 'products.product_family_id')
            ->get(['products.id', 'products.family_attribute_value', 'product_families.name as family_name'])
            ->mapWithKeys(fn ($row) => [(int) $row->id => ProductFamily::sizeLabel((string) $row->family_name, $row->family_attribute_value)])
            ->all();
    }

    /** ⭐ B4 (R22): the usual vendor, proposed on a new purchase. */
    public function defaultSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'default_supplier_id');
    }

    /**
     * ⭐ B4 (R7): the recipes that make this product. An active one is what
     * "Made here" means; a product with none is bought in (trading).
     */
    public function billsOfMaterial(): HasMany
    {
        return $this->hasMany(BillOfMaterial::class, 'output_product_id');
    }

    /** ⭐ B4 (R18): whether any sales path may take this product. */
    public function canBeSold(): bool
    {
        return $this->can_be_sold !== false;
    }

    /** ⭐ B4 (R17): a raw material whose kind is packaging (jar, lid, label, carton). */
    public function isPackaging(): bool
    {
        return $this->type === ProductType::RawMaterial && $this->material_kind === MaterialKind::Packaging;
    }

    /**
     * The kind a raw material nobody classified gets: packaging when its
     * category is a "Packaging ..." one or its SKU is in the PK- family
     * (F4/F10, 17 Oct 2026), raw otherwise. The migration applied the same
     * rule once to every existing raw material.
     */
    public static function guessMaterialKind(?string $categoryName, ?string $sku): MaterialKind
    {
        return preg_match('/^\s*packag/i', (string) $categoryName) === 1 || str_starts_with(strtoupper(trim((string) $sku)), 'PK-')
            ? MaterialKind::Packaging
            : MaterialKind::Raw;
    }

    /**
     * ⚠ Nullable by design. Raw materials have no brand: the same sugar becomes
     * LaLaDia sugar or Bionic sugar depending only on what it is packed into.
     * A finished good WITHOUT a brand is a warning rather than an error (D-3),
     * because a hard failure would stop the first import on exactly the rows
     * whose brand has not been decided yet.
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * What kind of thing this is for tax purposes.
     *
     * ⚠⚠ NULL IS NOT ZERO-RATED. A product with no class falls back to the
     * business default when the engine is built (C-VAT-11); it never silently
     * means "charge nothing". The two are wrong in opposite directions and only
     * one of them is a refund.
     *
     * ⛔ Nothing calculates with this yet. The VAT engine is dormant.
     */
    public function taxClass(): BelongsTo
    {
        return $this->belongsTo(TaxClass::class);
    }

    public function unitOfMeasure(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class);
    }

    /**
     * The unit the supplier invoices in, when it differs from the unit stock is
     * held and sold in.
     *
     * ⚠⚠ DELIBERATELY NOT FILLABLE, AND NOT ON THE PRODUCT SCREEN. Setting it
     * without a matching conversion puts the product into a state where every
     * goods receipt throws for ever, with no in-app remedy - a clerk could brick
     * a product and only a developer could unbrick it. It is set by
     * `ProductUnitConversion::record()`, which declares the second unit and the
     * factor together, in one place, or not at all.
     *
     * ⚠ NULL means "bought in the same unit it is stocked in", which is every
     * product except the few genuinely bought in one measure and sold in
     * another. Absence is the honest statement, not a flag kept in step with a
     * value. C-UOM-1.
     */
    public function purchaseUnit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'purchase_unit_id');
    }

    public function unitConversions(): HasMany
    {
        return $this->hasMany(ProductUnitConversion::class);
    }

    /** The unit `content_quantity` is declared in (C-MFG-14). */
    public function contentUnit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'content_unit_id');
    }

    /** Whether a receipt of this product has to convert before it becomes stock. */
    public function isBoughtInAnotherUnit(): bool
    {
        return $this->purchase_unit_id !== null
            && $this->purchase_unit_id !== $this->unit_of_measure_id;
    }

    public function batches(): HasMany
    {
        return $this->hasMany(StockBatch::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Why the stock unit and the stocked/service split are fixed once stock has
     * moved, on the screen and in the import alike.
     *
     * ⚠⚠ Every movement and batch is a quantity in the unit the product had at
     * the time. Changing the unit relabels all of them (10 kg becomes 10 pcs)
     * with every journal still balancing, and turning a stocked product into a
     * service strands its stock where nothing can move it.
     */
    public function hasStockHistory(): bool
    {
        return $this->movements()->exists();
    }

    /**
     * The sentence for each fixed field the given values would change, keyed by
     * field. Empty when nothing fixed would change.
     *
     * @return array<string, string>
     */
    public function stockLockedChanges(int|string|null $unitOfMeasureId, string|ProductType $type): array
    {
        $type = $type instanceof ProductType ? $type : ProductType::tryFrom($type);
        $refusals = [];

        if ($unitOfMeasureId !== null && (int) $unitOfMeasureId !== (int) $this->unit_of_measure_id) {
            $refusals['unit_of_measure_id'] = 'This product already has stock movements, so its stock unit cannot change. Create a new product for the new unit.';
        }

        if ($type !== null && $type->isStocked() !== $this->type->isStocked()) {
            $refusals['type'] = 'This product already has stock movements, so it cannot switch between a stocked product and a service.';
        }

        if ($refusals === [] || ! $this->hasStockHistory()) {
            return [];
        }

        return $refusals;
    }

    /**
     * Stock on hand across active batches.
     *
     * ⚠⚠ THE NORMALISATION IS NOT COSMETIC. An aggregate over a decimal column
     * is stringified differently by every driver: SQLite returns `20`, MySQL
     * returns `20.0000`, and PostgreSQL would return `20.0000` as well. This
     * method declares `: string`, so without normalising, its return value —
     * and anything comparing it with `===` or printing it on a screen — changes
     * meaning with the database underneath.
     *
     * Found on 2026-07-29 running the suite against production MySQL for the
     * first time. It is a portability bug in the application, not merely a
     * test-assertion difference, which is why it is fixed here rather than in
     * the tests.
     */
    public function currentStockQuantity(): string
    {
        $sum = $this->batches()
            ->where('status', StockBatchStatus::Active)
            ->sum('current_quantity');

        return self::normaliseDecimal((string) $sum);
    }

    /**
     * Stock that can actually be SOLD: active batches that have not expired.
     *
     * ⚠⚠ 14 Oct 2026 (audit #14): `currentStockQuantity()` counts expired
     * batches too, which is right for the book (a count meets them on the
     * shelf, and valuation still carries them until they are written off) and
     * wrong for selling: the shop said "in stock" and the order desk said "not
     * short" for goods FIFO will refuse to hand over. This uses the SAME rule
     * as `StockBatch::consumable()`, so what is offered is what can leave.
     */
    public function sellableStockQuantity(): string
    {
        $sum = $this->batches()->consumable()->reorder()->sum('current_quantity');

        // ⭐ Phase 2 (17 Oct 2026): what is RESERVED for an approved staff
        // purchase or owner withdrawal is not on offer (plan F4).
        $reserved = StockReservation::query()->live()
            ->whereIn('stock_batch_id', $this->batches()->consumable()->reorder()->select('stock_batches.id'))
            ->sum('quantity');

        return self::normaliseDecimal(number_format(max(0.0, (float) $sum - (float) $reserved), 4, '.', ''));
    }

    /** Expired stock still on the book (audit #14): shown, never sold. */
    public function expiredStockQuantity(): string
    {
        $sum = $this->batches()->expired()->sum('current_quantity');

        return self::normaliseDecimal((string) $sum);
    }

    /**
     * Trim a decimal string to its shortest exact form: `20.0000` → `20`,
     * `20.5000` → `20.5`, `0.0000` → `0`. Never rounds, so no quantity is
     * altered by being displayed.
     */
    /** A summed quantity as the screens show it: "10.5000" as "10.5". */
    public static function plainQuantity(string $value): string
    {
        return self::normaliseDecimal($value);
    }

    private static function normaliseDecimal(string $value): string
    {
        if (! str_contains($value, '.')) {
            return $value;
        }

        $trimmed = rtrim(rtrim($value, '0'), '.');

        return ($trimmed === '' || $trimmed === '-') ? '0' : $trimmed;
    }

    /**
     * Weighted average cost across active batches. Null when there's no stock
     * on hand — a "no data" state, not a cost of zero.
     */
    public function averageUnitCost(): ?string
    {
        $batches = $this->batches()->where('status', StockBatchStatus::Active)->get(['current_quantity', 'unit_cost']);
        $totalQuantity = $batches->sum(fn ($batch) => (float) $batch->current_quantity);

        if ($totalQuantity <= 0) {
            return null;
        }

        $totalValue = $batches->sum(fn ($batch) => (float) $batch->current_quantity * (float) $batch->unit_cost);

        return (string) ($totalValue / $totalQuantity);
    }

    public function isBelowLowStockThreshold(): bool
    {
        return self::stockLevelIsLow($this->low_stock_threshold, (float) $this->currentStockQuantity());
    }

    /**
     * THE low-stock rule, in one place: reaching the reorder point IS the
     * alert (at or below threshold), and a product with no threshold is never
     * "low" - a default nobody set would page somebody about nothing.
     *
     * Static so the low-stocks screen, which aggregates batches in one grouped
     * query instead of asking each product, still applies the SAME comparison
     * the dashboard does. Two screens disagreeing about "low" is the
     * chart-vs-tiles drift this project keeps paying for.
     */
    public static function stockLevelIsLow(mixed $threshold, float $quantity): bool
    {
        if ($threshold === null) {
            return false;
        }

        return $quantity <= (float) $threshold;
    }

    public function scopeStocked(Builder $query): Builder
    {
        return $query->where('type', '!=', ProductType::Service);
    }

    /**
     * What each product last cost to put on the shelf: the unit cost of its
     * newest batch, bought or made (F21, practice run 17 Oct 2026: the ghee jar
     * came out of production at 762 and the distributor price was 760). Per
     * stock unit, the unit a sale is in. A product never stocked is absent.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, string> keyed by product id
     */
    public static function latestUnitCosts(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $newest = StockBatch::query()
            ->whereIn('product_id', $productIds)
            ->groupBy('product_id')
            ->selectRaw('max(id)');

        return StockBatch::query()
            ->whereIn('id', $newest)
            ->get(['id', 'product_id', 'unit_cost'])
            ->mapWithKeys(fn (StockBatch $batch) => [(int) $batch->product_id => number_format((float) $batch->unit_cost, 2, '.', '')])
            ->all();
    }

    /**
     * What a sales picker may offer (F25, practice run 17 Oct 2026): never a
     * raw material or packaging (bulk ghee, jars, lids, labels) unless it is
     * sold as it is.
     *
     * ⭐ B4 (Joy, 24 Oct 2026; R18): that is now the product's own "Can be
     * sold" switch, not the presence of a price row. The migration switched
     * it on for every raw material that had a price, so the pickers offer the
     * same items as before; from now on the switch decides, and the server
     * refuses an item it hides (Sales\Support\NotForSale).
     */
    public function scopeSellable(Builder $query): Builder
    {
        return $query->where('products.can_be_sold', true);
    }
}
