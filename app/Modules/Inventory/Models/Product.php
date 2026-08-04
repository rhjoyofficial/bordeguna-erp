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
            ->logOnly(['name', 'sku', 'category_id', 'low_stock_threshold', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function unitOfMeasure(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class);
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
