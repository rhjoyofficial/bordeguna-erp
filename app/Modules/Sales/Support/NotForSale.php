<?php

namespace App\Modules\Sales\Support;

use App\Modules\Inventory\Models\Product;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Initial plan B4 (Joy, 24 Oct 2026; R18): "Ensure 'Is Sales Item' is
 * unchecked so sales reps can't accidentally invoice raw mustard seeds."
 *
 * ⚠⚠ THE SERVER REFUSES, NOT ONLY THE PICKER. Before this the pickers hid raw
 * materials (F25) while every write path took any product id it was sent:
 * `exists:products,id` and nothing more. A crafted request, an imported sheet
 * or a stale tab could sell bulk mustard seed. Each sales path that writes a
 * new product line calls this once per line: the order (and through it the
 * website checkout, the shop order import, the HQ confirm and a quotation's
 * acceptance), the order edit, the counter, a direct challan, a direct
 * invoice line, the quotation and the HQ draft.
 *
 * ⚠ A line that already exists is not re-judged: switching "Can be sold" off
 * stops NEW sales, it never strands goods that already left on an order.
 */
final class NotForSale
{
    public static function sentence(Product $product): string
    {
        return "{$product->name} is not for sale: its Can be sold switch is off. Switch it on in the product popup if you really sell it as it is.";
    }

    /**
     * @throws ValidationException
     */
    public static function refuse(Product $product, string $key): void
    {
        if (! $product->canBeSold()) {
            throw ValidationException::withMessages([$key => self::sentence($product)]);
        }
    }
}
