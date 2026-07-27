<?php

namespace App\Modules\Hrm\Enums;

/**
 * Where a payslip stands against its net (DIS-02).
 *
 * Derived from the payment events, never stored. That is the same rule the loan
 * balance and the leave balance follow, and it matters here for a specific
 * reason: a stored status and a list of payments can disagree, and when they do
 * it is the employee's pay that is in dispute.
 */
enum PayslipPaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Partial = 'partial';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::Partial => 'Partially paid',
            self::Paid => 'Paid',
        };
    }

    /**
     * Resolve a status from the two figures that decide it.
     *
     * Compared at two decimal places because both sides are money already
     * rounded to paisa by PAY-10; a float comparison would leave a payslip
     * eternally "partially paid" by a millionth of a taka.
     */
    public static function resolve(float $paid, float $net): self
    {
        $paid = round($paid, 2);
        $net = round($net, 2);

        // A zero or negative net is nothing to pay, so it is settled by
        // definition — PAY-11 blocks a negative net long before it reaches here.
        if ($net <= 0) {
            return self::Paid;
        }

        return match (true) {
            $paid <= 0 => self::Unpaid,
            $paid >= $net => self::Paid,
            default => self::Partial,
        };
    }
}
