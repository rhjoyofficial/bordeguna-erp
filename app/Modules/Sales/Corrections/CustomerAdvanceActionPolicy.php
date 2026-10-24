<?php

namespace App\Modules\Sales\Corrections;

use App\Modules\Sales\Models\CustomerAdvance;

/**
 * ⭐ B11 (24 Oct 2026). An advance received, or moved from a cancelled order.
 * Reverse only; see CustomerAdvanceDocumentPolicy.
 */
class CustomerAdvanceActionPolicy extends CustomerAdvanceDocumentPolicy
{
    public const TYPE = 'sales.customer_advance';

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return CustomerAdvance::class;
    }

    protected function dateColumn(): string
    {
        return 'received_on';
    }
}
