<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Support\PurchaseUnitConversion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
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

    /**
     * The controlled path for creating a conversion: BOTH directions must be
     * supplied and they must be reciprocals.
     *
     * ⚠⚠ THIS EXISTS BECAUSE ONLY ONE DIRECTION IS STORED. The reciprocal check
     * has nowhere else to live: there is no conversion screen (deliberately -
     * one product, one row), so without this the guard would be a tested helper
     * that nothing ever calls. That is precisely how `products.barcode` shipped
     * unreachable behind six passing tests, and it is not happening twice.
     *
     * ⚠ An inverted factor is the dangerous error here: 0.917 stored where
     * 1.0905 belongs is 21% wrong, permanently, with every ledger still
     * balancing. Two numbers that must multiply to 1 make it impossible to
     * supply quietly. C-UOM-3.
     *
     * @throws InvalidArgumentException when the two directions are not reciprocals
     */
    public static function record(
        int $businessId,
        Product $product,
        UnitOfMeasure $fromUnit,
        UnitOfMeasure $toUnit,
        string $stockUnitsPerPurchaseUnit,
        string $purchaseUnitsPerStockUnit,
        string $effectiveFrom,
        string $source,
        ?string $note = null,
    ): self {
        if (! PurchaseUnitConversion::reciprocalsAgree($stockUnitsPerPurchaseUnit, $purchaseUnitsPerStockUnit)) {
            throw new InvalidArgumentException(
                "The two directions given for {$product->sku} are not reciprocals: ".
                "{$stockUnitsPerPurchaseUnit} and {$purchaseUnitsPerStockUnit} multiply to ".
                rtrim(rtrim(number_format(((float) $stockUnitsPerPurchaseUnit) * ((float) $purchaseUnitsPerStockUnit), 6, '.', ''), '0'), '.').
                ', not 1. One of them is the wrong way round.'
            );
        }

        return static::create([
            'business_id' => $businessId,
            'product_id' => $product->id,
            'from_unit_id' => $fromUnit->id,
            'to_unit_id' => $toUnit->id,
            'stock_units_per_purchase_unit' => $stockUnitsPerPurchaseUnit,
            'effective_from' => $effectiveFrom,
            'source' => $source,
            'note' => $note,
        ]);
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
