<?php

namespace App\Modules\Sales\Support\CourierStatements;

use App\Modules\Foundation\Models\MoneyAccount;

/**
 * The statement format for a courier account, found by the courier named on it.
 */
final class CourierStatementFormats
{
    public static function for(MoneyAccount $courier): ?CourierStatementFormat
    {
        return match (self::provider($courier)) {
            'pathao' => new PathaoStatementFormat,
            default => null,
        };
    }

    public static function provider(MoneyAccount $courier): string
    {
        return strtolower(trim((string) $courier->provider));
    }
}
