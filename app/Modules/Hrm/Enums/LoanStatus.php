<?php

namespace App\Modules\Hrm\Enums;

/**
 * The ADJ-07 lifecycle: requested, approved and disbursed, repaid, closed.
 *
 * Deliberately short. ADJ-07 describes a request, an approval, a repayment
 * schedule and an early settlement — so those are the states. There is no
 * "defaulted", no "written off" and no "restructured", because nobody has said
 * what any of them would mean, and a status the system cannot act on is worse
 * than no status at all.
 */
enum LoanStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Settled = 'settled';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting approval',
            self::Active => 'Active',
            self::Settled => 'Settled',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Whether payroll may deduct against this loan. Only a disbursed, still
     * outstanding loan is recoverable — a pending one has not been paid out yet,
     * and a settled one has nothing left to take.
     */
    public function isRecoverable(): bool
    {
        return $this === self::Active;
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Active], true);
    }
}
