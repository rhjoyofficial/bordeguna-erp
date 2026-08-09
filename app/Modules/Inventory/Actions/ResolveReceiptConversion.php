<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Accounting\Support\Money;
use App\Modules\Inventory\Exceptions\MissingUnitConversionException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductUnitConversion;
use App\Modules\Inventory\Support\PurchaseUnitConversion;
use Illuminate\Support\Carbon;

/**
 * Turns "100 kilograms at 320.00 per kilogram, received on this date" into the
 * litres and cost per litre a stock batch will carry, plus the factor that did
 * it.
 *
 * ⚠ Returns the purchase figures UNCHANGED for the overwhelming majority of
 * products, which are bought in the unit they are stocked in. Only a product
 * with a purchase unit different from its stock unit converts.
 *
 * Design note: UNIT-CONVERSION-ARCHITECTURE.md.
 */
class ResolveReceiptConversion
{
    /**
     * @return array{quantity:string, unitCost:string, purchaseQuantity:?string, purchaseUnitId:?int, factor:?string}
     */
    public function handle(Product $product, string $purchaseQuantity, string $purchaseUnitCost, Carbon $on): array
    {
        if (! $product->isBoughtInAnotherUnit()) {
            return [
                'quantity' => $purchaseQuantity,
                'unitCost' => $purchaseUnitCost,
                'purchaseQuantity' => null,
                'purchaseUnitId' => null,
                'factor' => null,
            ];
        }

        $conversion = ProductUnitConversion::forProductOn($product->id, $on);

        // ⚠⚠ C-UOM-9: REFUSED, not defaulted to 1. A factor of 1 would put 100
        // litres into stock for a 100 kilogram drum and understate the cost of a
        // litre by about 9% - and the ledger would balance perfectly while it
        // did. A missing factor is not a small gap, it is a wrong number that
        // looks right, so the receipt does not happen at all.
        if ($conversion === null) {
            throw MissingUnitConversionException::forProductOn($product, $on);
        }

        $factor = (string) $conversion->stock_units_per_purchase_unit;

        $quantity = PurchaseUnitConversion::toStockQuantity($purchaseQuantity, $factor);

        // ⚠ The cost per stock unit is derived from the INVOICE TOTAL and the
        // ROUNDED stock quantity, never by converting the unit cost on its own.
        // See PurchaseUnitConversion::toStockUnitCost.
        $total = Money::ofProduct($purchaseQuantity, $purchaseUnitCost);
        $unitCost = PurchaseUnitConversion::toStockUnitCost($total, $quantity);

        return [
            'quantity' => $quantity,
            'unitCost' => $unitCost,
            'purchaseQuantity' => $purchaseQuantity,
            'purchaseUnitId' => $product->purchase_unit_id,
            'factor' => $factor,
        ];
    }
}
