<?php

namespace App\Modules\Inventory\Exceptions;

use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public static function forProductInWarehouse(int $productId, int $warehouseId, float $requested, float $available): self
    {
        return new self(
            "Cannot move {$requested} units of product #{$productId} from warehouse #{$warehouseId}: only {$available} available."
        );
    }

    /**
     * Traceability M2 (17 Oct 2026): the shortfall is stock ON HOLD. Said in
     * plain words with the product and the warehouse by name, because "only 0
     * available" beside a shelf of jars reads as a bug at the counter.
     */
    public static function heldStock(string $product, string $warehouse, float $requested, float $available, float $held): self
    {
        $free = $available > 0 ? ' Only '.self::number($available).' is free to use.' : ' Nothing else is free to use.';

        return new self(
            "{$product} in {$warehouse}: ".self::number($held).' is on hold and cannot be used.'
            .$free.' You asked for '.self::number($requested).'. Release the batch first, or take it from another warehouse.'
        );
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
