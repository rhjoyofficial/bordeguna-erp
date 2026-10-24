<?php

namespace App\Modules\Sales\Support;

use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\PriceFloorOverride;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ THE MINIMUM SELLING PRICE (Joy, 25 Oct 2026, Q16 = A, R12): an optional
 * floor on a MARKET-priced product (fish, onion). Sales types the day's price,
 * but nobody sells below the floor except a Manager or the Owner
 * (`sales.prices.below_floor`), and only with a reason, which is logged.
 *
 * "The price" of a sale line is what one unit really goes for: the line's
 * price less its discount spread over the quantity. A fixed-MRP product has
 * no floor (its price list is the control), and a blank floor is no floor.
 *
 * One rule for the order, the invoice, the counter and today's price, so the
 * four cannot disagree.
 */
final class PriceFloor
{
    public const PERMISSION = 'sales.prices.below_floor';

    /** The floor in force for this product, or null. */
    public static function of(Product $product): ?string
    {
        if (! $product->isMarketPriced() || $product->min_selling_price === null || (float) $product->min_selling_price <= 0) {
            return null;
        }

        return bcadd((string) $product->min_selling_price, '0', 4);
    }

    /** One unit's real price: the line's price less its discount over the quantity. */
    public static function netUnit(string $quantity, string $unitPrice, string $discount = '0'): string
    {
        if (bccomp($quantity, '0', 4) <= 0) {
            return bcadd($unitPrice, '0', 4);
        }

        return bcsub($unitPrice, bcdiv($discount === '' ? '0' : $discount, $quantity, 6), 4);
    }

    /** Whether this price is under the floor (false when there is none). */
    public static function isBelow(Product $product, string $netUnit): bool
    {
        $floor = self::of($product);

        return $floor !== null && bccomp($netUnit, $floor, 4) < 0;
    }

    /**
     * Why this price may not stand, or null. `$reason` is the reason typed for
     * going below; blank is refused even for an Owner.
     */
    public static function refusal(Product $product, string $netUnit, User $by, ?string $reason): ?string
    {
        if (! self::isBelow($product, $netUnit)) {
            return null;
        }

        $floor = ProductPricing::plain(self::of($product));
        $price = ProductPricing::plain(bcadd($netUnit, '0', 2));

        if (! $by->can(self::PERMISSION)) {
            return "{$product->name} at Tk {$price} is below its minimum selling price of Tk {$floor}. Only a Manager or the Owner may sell below it, with a reason.";
        }

        if (trim((string) $reason) === '') {
            return "{$product->name} at Tk {$price} is below its minimum selling price of Tk {$floor}. Say why.";
        }

        return null;
    }

    /** The log row and the History line, inside the caller's transaction. */
    public static function record(Product $product, string $netUnit, User $by, string $reason, Model $document): PriceFloorOverride
    {
        $row = PriceFloorOverride::create([
            'business_id' => $product->business_id,
            'product_id' => $product->id,
            'document_type' => $document->getMorphClass(),
            'document_id' => $document->getKey(),
            'unit_price' => bcadd($netUnit, '0', 4),
            'floor' => self::of($product),
            'reason' => trim($reason),
            'allowed_by' => $by->id,
        ]);

        activity()
            ->performedOn($document)
            ->causedBy($by)
            ->withProperties(['price_floor_override_id' => $row->id, 'product' => $product->name, 'unit_price' => $row->unit_price, 'floor' => $row->floor, 'reason' => trim($reason)])
            ->log("Sold {$product->name} below its minimum price: ".trim($reason));

        return $row;
    }
}
