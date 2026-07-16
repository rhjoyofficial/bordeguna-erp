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
}
