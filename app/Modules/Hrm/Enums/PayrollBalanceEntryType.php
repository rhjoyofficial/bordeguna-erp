<?php

namespace App\Modules\Hrm\Enums;

/**
 * Movements of the PAY-11 carry-forward balance (Correction C8c).
 *
 * Only two, and deliberately so: a shortfall carried forward, and the recovery
 * that clears it. Anything else — a write-off, a settlement on separation — is a
 * rule nobody has stated, and would be invented rather than traced.
 */
enum PayrollBalanceEntryType: string
{
    case CarriedForward = 'carried_forward';
    case Recovered = 'recovered';

    public function label(): string
    {
        return match ($this) {
            self::CarriedForward => 'Carried forward',
            self::Recovered => 'Recovered',
        };
    }
}
