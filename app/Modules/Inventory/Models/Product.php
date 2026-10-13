<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Accounting\Models\TaxClass;
use App\Modules\Commerce\Enums\AvailabilityPolicy;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Enums\WeightSource;
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
            'low_stock_threshold' => 'decimal:4',
            'content_quantity' => 'decimal:4',
            'weight_grams' => 'integer',
            'weight_source' => WeightSource::class,
            'is_active' => 'boolean',
            'availability_override' => AvailabilityPolicy::class,
            'lead_time_days' => 'integer',
            'tracks_expiry' => 'boolean',
            'shelf_life_warning_days' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'sku', 'brand_id', 'category_id', 'low_stock_threshold', 'weight_grams', 'weight_source', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
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
     * Trim a decimal string to its shortest exact form: `20.0000` → `20`,
     * `20.5000` → `20.5`, `0.0000` → `0`. Never rounds, so no quantity is
     * altered by being displayed.
     */
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
}
