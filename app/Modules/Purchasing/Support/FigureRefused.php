<?php

namespace App\Modules\Purchasing\Support;

use InvalidArgumentException;

/**
 * A purchase order figure refused, with the box it belongs under (`unit_id`,
 * `discount_value`, `tax_rate`...), so PurchaseOrderFigures can say
 * `lines.2.discount_value` instead of one message at the bottom.
 */
final class FigureRefused extends InvalidArgumentException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
