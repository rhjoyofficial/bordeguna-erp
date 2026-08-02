<?php

namespace App\Modules\Foundation\Enums;

/**
 * Where a business actually holds money.
 *
 * ⚠ Deliberately three kinds and not a free-text field: each answers a different
 * reconciliation question. A bank account reconciles to a statement, a cash
 * account to a physical count, and an MFS wallet to the provider's own history.
 */
enum MoneyAccountType: string
{
    case Bank = 'bank';
    case Cash = 'cash';
    case Mfs = 'mfs';

    public function label(): string
    {
        return [
            'bank' => 'Bank account',
            'cash' => 'Cash',
            'mfs' => 'Mobile financial service',
        ][$this->value] ?? $this->value;
    }
}
