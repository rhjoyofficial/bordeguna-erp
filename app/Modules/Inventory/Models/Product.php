<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Enums\StockBatchStatus;
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
        'sku',
        'barcode',
        'name',
        'type',
        'description',
        'low_stock_threshold',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'low_stock_threshold' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'sku', 'brand_id', 'category_id', 'low_stock_threshold', 'is_active'])
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
        if ($this->low_stock_threshold === null) {
            return false;
        }

        return (float) $this->currentStockQuantity() < (float) $this->low_stock_threshold;
    }

    public function scopeStocked(Builder $query): Builder
    {
        return $query->where('type', '!=', ProductType::Service);
    }
}
