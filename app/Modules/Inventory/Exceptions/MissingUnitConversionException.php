<?php

namespace App\Modules\Inventory\Exceptions;

use App\Modules\Inventory\Models\Product;
use DomainException;
use Illuminate\Support\Carbon;

/**
 * A product bought in one unit and stocked in another cannot be received until
 * somebody has said how the two relate on that date.
 *
 * ⚠⚠ This exception existing is the point of C-UOM-9. The alternative - carrying
 * on with a factor of 1 - is the failure this whole design was built to prevent:
 * stock quantity wrong by the density, cost per unit wrong by the same, and a
 * trial balance that balances to the penny throughout.
 */
class MissingUnitConversionException extends DomainException
{
    public static function forProductOn(Product $product, Carbon $on): self
    {
        return new self(
            "No unit conversion is in force for {$product->sku} on {$on->toDateString()}. ".
            'A product bought in a different unit from the one it is stocked in cannot be received '.
            'until its conversion is recorded. Refusing rather than assuming 1:1.'
        );
    }
}
