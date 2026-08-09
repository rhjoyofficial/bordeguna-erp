<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * How many stock units one purchase unit becomes: litres per kilogram for
 * mustard oil.
 *
 * ⚠⚠ NEVER call this "the factor" in a variable name or a column. The two
 * directions look alike and are 21% apart. See UNIT-CONVERSION-ARCHITECTURE.md
 * C-UOM-3.
 */
class ProductUnitConversion extends Model
{
    use BelongsToBusiness, HasFactory, LogsActivity;

    protected $fillable = [
        'business_id',
        'product_id',
        'from_unit_id',
        'to_unit_id',
        'stock_units_per_purchase_unit',
        'effective_from',
        'source',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'stock_units_per_purchase_unit' => 'decimal:8',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['product_id', 'stock_units_per_purchase_unit', 'effective_from', 'source'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function fromUnit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'from_unit_id');
    }

    public function toUnit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'to_unit_id');
    }

    /**
     * The conversion in force for this product on this date, or null.
     *
     * ⚠ Copied from `AccountMapping::forKeyOn`, which is the established
     * effective-dated lookup in this codebase. `whereDate` rather than a
     * comparison against a Carbon instance: this project has been bitten five
     * times by comparing a date-cast column to a datetime, where a row dated
     * today is silently excluded because "today 00:00" is not ">= today 14:32".
     *
     * ⚠ Ordered newest-first, then by id. With one row per date the unique index
     * makes that decide nothing - it is here so a row inserted by hand cannot
     * make resolution depend on insertion order.
     */
    public static function forProductOn(int $productId, Carbon $on): ?self
    {
        return static::query()
            ->where('product_id', $productId)
            ->whereDate('effective_from', '<=', $on->toDateString())
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }
}
