<?php

namespace App\Modules\Accounting\Enums;

/**
 * Which side an account's balance normally falls on.
 *
 * ⚠ A presentation concept, not a storage one. The ledger stores a single
 * signed amount, positive for a debit, and this enum only decides which column
 * a figure is printed in and whether a balance reads as positive to a human.
 * Nothing in the posting engine consults it.
 */
enum NormalBalance: string
{
    case Debit = 'debit';
    case Credit = 'credit';

    public function label(): string
    {
        return $this === self::Debit ? 'Debit' : 'Credit';
    }
}
