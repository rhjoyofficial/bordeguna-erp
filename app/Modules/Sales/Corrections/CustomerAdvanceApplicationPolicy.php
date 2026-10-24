<?php

namespace App\Modules\Sales\Corrections;

use App\Modules\Sales\Models\CustomerAdvanceApplication;

/**
 * ⭐ B11 (24 Oct 2026). A customer's advance used on one of their invoices.
 * Reverse only; see CustomerAdvanceDocumentPolicy.
 */
class CustomerAdvanceApplicationPolicy extends CustomerAdvanceDocumentPolicy
{
    public const TYPE = 'sales.customer_advance_application';

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return CustomerAdvanceApplication::class;
    }

    protected function dateColumn(): string
    {
        return 'applied_on';
    }
}
