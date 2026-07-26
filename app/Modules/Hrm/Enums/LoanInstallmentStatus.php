<?php

namespace App\Modules\Hrm\Enums;

/**
 * The state of one planned installment.
 *
 * `Scheduled` is a plan, `Paid` is a fact, and `Cancelled` is what an early
 * settlement leaves behind — the months that will now never be collected. The
 * cancelled rows are kept rather than deleted so the original plan stays
 * readable next to what actually happened.
 */
enum LoanInstallmentStatus: string
{
    case Scheduled = 'scheduled';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Paid => 'Paid',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isDue(): bool
    {
        return $this === self::Scheduled;
    }
}
