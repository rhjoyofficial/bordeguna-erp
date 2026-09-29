<?php

namespace App\Modules\Accounting\Enums;

/**
 * ⚠ A draft has touched nothing. Only `posted` and `reversed` vouchers have a
 * journal, and neither can ever be edited or deleted.
 */
enum VoucherStatus: string
{
    case Draft = 'draft';
    // A payment above the finance-policy limit that its own preparer tried to
    // post: it waits for a second person, touching nothing (B13, 29 Sep 2026).
    case PendingApproval = 'pending_approval';
    case Posted = 'posted';
    case Reversed = 'reversed';

    public function label(): string
    {
        return $this === self::PendingApproval ? 'Awaiting approval' : ucfirst($this->value);
    }
}
