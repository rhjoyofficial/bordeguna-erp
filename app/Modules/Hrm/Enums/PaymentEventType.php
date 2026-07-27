<?php

namespace App\Modules\Hrm\Enums;

/**
 * What happened when someone tried to pay a payslip (DIS-02).
 *
 * Three kinds, because DIS-02 ticked unpaid-with-retry, partial-paid and manual
 * handling all at once, and none of those is expressible as a boolean. A failed
 * attempt is deliberately an event of its own rather than the absence of a
 * successful one: "we tried on the 28th and the account was closed" is the
 * thing HR has to act on, and it is invisible if failure is just a gap.
 */
enum PaymentEventType: string
{
    case Paid = 'paid';
    case Failed = 'failed';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Paid',
            self::Failed => 'Failed',
            self::Reversed => 'Reversed',
        };
    }

    /**
     * How this event moves the paid total. A failure moves nothing — it did not
     * take money back, it never delivered any.
     */
    public function signedFactor(): int
    {
        return match ($this) {
            self::Paid => 1,
            self::Reversed => -1,
            self::Failed => 0,
        };
    }
}
