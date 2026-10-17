<?php

namespace App\Modules\Inventory\Support;

/**
 * What `UnitConverter::toStockUnit()` worked out, every number of it kept so
 * the caller can store the typed figure, the stock figure AND the factor that
 * joined them. A recipe line and a production-run line both freeze all three.
 */
final readonly class UnitConversionResult
{
    /**
     * @param  string  $quantity  in the product's stock unit, decimal(15,4), rounded half up once
     * @param  string  $factor  stock units per ONE entered unit, decimal(20,8)
     * @param  string  $enteredQuantity  as typed, decimal(15,4)
     * @param  string  $basis  same_unit | global | product
     * @param  ?int  $conversionId  the product_unit_conversions row used, when basis is product
     */
    public function __construct(
        public string $quantity,
        public string $factor,
        public string $enteredQuantity,
        public int $enteredUnitId,
        public int $stockUnitId,
        public string $basis,
        public ?int $conversionId,
    ) {}
}
