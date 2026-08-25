<?php

namespace App\Modules\Hrm\Support;

use App\Modules\Hrm\Models\Payslip;

/**
 * Where a payslip would be emailed, if it were emailed now.
 *
 * ⚠⚠ ONE RULE, TWO READERS, and the second one is the point. The sending job
 * has always known this; the screen that offers the button did not, so HR could
 * press "email payslips" and be told eight were queued while the ninth person
 * silently had nowhere to send to. Asking the same question in two places with
 * two answers is how "sent" and "received" stop matching.
 *
 * ⚠ The employee's own address first, the login account second. A personal
 * address is where a payslip belongs; a work login may be shared, or disabled
 * the day somebody leaves, which is exactly when a final payslip still has to
 * reach them.
 */
class PayslipAddress
{
    public static function for(Payslip $payslip): ?string
    {
        $employee = $payslip->employee;

        return filled($employee?->personal_email)
            ? $employee->personal_email
            : ($employee?->user?->email ?: null);
    }
}
