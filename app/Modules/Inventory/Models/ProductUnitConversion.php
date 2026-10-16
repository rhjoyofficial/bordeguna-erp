<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Support\PurchaseUnitConversion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
            'effective_from' => 'date:Y-m-d',
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
        // ⚠⚠ SIGN FIRST. `reciprocalsAgree` only asks whether the two multiply
        // to 1, and TWO NEGATIVES DO. A pair of -1.09051254 and -0.917 would
        // sail through, and a negative factor makes a goods receipt create a
        // batch with negative quantity and post a negative debit to Stock on
        // Hand - a delivery that REDUCES inventory, with the ledger balancing.
        if ((float) $stockUnitsPerPurchaseUnit <= 0 || (float) $purchaseUnitsPerStockUnit <= 0) {
            throw new InvalidArgumentException(
                "A conversion factor must be greater than zero; got {$stockUnitsPerPurchaseUnit} ".
                "and {$purchaseUnitsPerStockUnit} for {$product->sku}."
            );
        }

        // ⚠⚠ THE UNITS MUST BE THE PRODUCT'S OWN. Without this a conversion
        // recorded as kilogram-to-litre keeps resolving after the product's
        // purchase unit is changed to something else, and every later receipt
        // silently applies a factor that describes a different measurement -
        // the wrong number that looks right, ledger balanced. `forProductOn`
        // matches on product and date alone, so the check has to be here.
        if ($toUnit->id !== $product->unit_of_measure_id) {
            throw new InvalidArgumentException(
                "The conversion for {$product->sku} must convert INTO the unit it is stocked in. ".
                "Given {$toUnit->abbreviation}, but stock is held in {$product->unitOfMeasure->abbreviation}."
            );
        }

        if ($fromUnit->id === $toUnit->id) {
            throw new InvalidArgumentException(
                "A conversion for {$product->sku} must be between two DIFFERENT units."
            );
        }

        // ⚠ A product that already converts cannot be quietly re-pointed at a
        // different purchase unit: existing batches froze a factor describing
        // the old one, and the new rows would silently describe something else.
        if ($product->purchase_unit_id !== null && $product->purchase_unit_id !== $fromUnit->id) {
            throw new InvalidArgumentException(
                "{$product->sku} is already bought in {$product->purchaseUnit->abbreviation}. ".
                'Changing the purchase unit is not a conversion, it is a new product.'
            );
        }

        if (! PurchaseUnitConversion::reciprocalsAgree($stockUnitsPerPurchaseUnit, $purchaseUnitsPerStockUnit)) {
            throw new InvalidArgumentException(
                "The two directions given for {$product->sku} are not reciprocals: ".
                "{$stockUnitsPerPurchaseUnit} and {$purchaseUnitsPerStockUnit} multiply to ".
                rtrim(rtrim(number_format(((float) $stockUnitsPerPurchaseUnit) * ((float) $purchaseUnitsPerStockUnit), 6, '.', ''), '0'), '.').
                ', not 1. One of them is the wrong way round.'
            );
        }

        // ⚠⚠ THE SECOND UNIT AND ITS FACTOR ARE DECLARED TOGETHER, HERE, OR NOT
        // AT ALL. `products.purchase_unit_id` is not fillable and is absent from
        // the product screen precisely so this is the only way in: setting the
        // unit without a factor would put the product into a state where every
        // goods receipt throws for ever (C-UOM-9) and no screen could undo it.
        if ($product->purchase_unit_id === null) {
            self::assertFirstConversionIsSafe($product, $effectiveFrom);

            $product->purchase_unit_id = $fromUnit->id;
            $product->save();
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

    /**
     * ⚠⚠ Bug hunt 16 Oct 2026. The first conversion changes how EVERY later
     * receipt of the product is read, from today: a purchase order line keeps
     * no unit of its own, and a receipt takes the product's unit when it is
     * received. So a first conversion is refused
     *  - while an open order still waits for the product: 100 ordered as
     *    litres would be received as 100 kg and the cost divided to match;
     *  - when it takes effect in the future: the purchase unit would switch
     *    today and every receipt until that day would find no factor.
     */
    private static function assertFirstConversionIsSafe(Product $product, string $effectiveFrom): void
    {
        if (Carbon::parse($effectiveFrom)->startOfDay()->gt(Carbon::today())) {
            throw new InvalidArgumentException(
                "The first conversion for {$product->sku} changes how it is received from today, so it must take effect today or earlier."
            );
        }

        $waiting = DB::table('purchase_order_lines')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_lines.purchase_order_id')
            ->where('purchase_order_lines.product_id', $product->id)
            ->whereIn('purchase_orders.status', ['open', 'partially_received'])
            ->whereColumn('purchase_order_lines.quantity_received', '<', 'purchase_order_lines.quantity_ordered')
            ->orderBy('purchase_orders.po_number')
            ->distinct()
            ->pluck('purchase_orders.po_number');

        if ($waiting->isNotEmpty()) {
            throw new InvalidArgumentException(
                "{$product->sku} is still waiting on purchase order ".$waiting->implode(', ').'. '.
                'Its quantities were ordered without this conversion and would be read in the new unit. '.
                'Receive or close those orders first, then record the conversion.'
            );
        }
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
