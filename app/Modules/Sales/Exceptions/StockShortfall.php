<?php

namespace App\Modules\Sales\Exceptions;

use RuntimeException;

/**
 * ⭐ B17 (25 Oct 2026, Q4 = A): an order cannot be confirmed because it wants
 * more than is available to sell. The sentence names each product with
 * available against wanted; a Manager or the Owner may confirm it anyway with
 * a reason (a backorder).
 */
class StockShortfall extends RuntimeException
{
    /**
     * @param  array<int, array{product_id: int, product: string, warehouse: string, wanted: string, available: string, short: string}>  $shortfalls
     */
    public function __construct(public readonly array $shortfalls, string $message)
    {
        parent::__construct($message);
    }
}
