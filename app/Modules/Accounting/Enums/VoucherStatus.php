<?php

namespace App\Modules\Accounting\Enums;

/**
 * ⚠ A draft has touched nothing. Only `posted` and `reversed` vouchers have a
 * journal, and neither can ever be edited or deleted.
 */
enum VoucherStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Reversed = 'reversed';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
