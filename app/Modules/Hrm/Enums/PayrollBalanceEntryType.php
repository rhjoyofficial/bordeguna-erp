<?php

namespace App\Modules\Hrm\Enums;

/**
 * Movements of the PAY-11 carry-forward balance (Correction C8c).
 *
 * A shortfall carried forward, the recovery that clears it, and — since
 * Correction C9c — the reversal of a recovery whose salary payment failed.
 * Nothing else: a write-off or a settlement on separation is a rule nobody has
 * stated, and would be invented rather than traced.
 */
enum PayrollBalanceEntryType: string
{
    case CarriedForward = 'carried_forward';
    case Recovered = 'recovered';
    case RecoveryReversed = 'recovery_reversed';

    public function label(): string
    {
        return match ($this) {
            self::CarriedForward => 'Carried forward',
            self::Recovered => 'Recovered',
            self::RecoveryReversed => 'Recovery reversed',
        };
    }
}
