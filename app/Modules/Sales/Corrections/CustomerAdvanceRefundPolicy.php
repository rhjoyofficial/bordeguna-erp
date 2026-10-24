<?php

namespace App\Modules\Sales\Corrections;

use App\Modules\Sales\Models\CustomerAdvanceRefund;

/**
 * ⭐ B11 (24 Oct 2026). A customer's advance paid back.
 * Reverse only; see CustomerAdvanceDocumentPolicy.
 */
class CustomerAdvanceRefundPolicy extends CustomerAdvanceDocumentPolicy
{
    public const TYPE = 'sales.customer_advance_refund';

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return CustomerAdvanceRefund::class;
    }

    protected function dateColumn(): string
    {
        return 'refunded_on';
    }
}
