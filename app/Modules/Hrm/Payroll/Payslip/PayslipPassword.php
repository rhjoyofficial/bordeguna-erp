<?php

namespace App\Modules\Hrm\Payroll\Payslip;

use App\Modules\Hrm\Models\Employee;

/**
 * The password on an exported payslip (SAL-07, Correction C9b, shortened by
 * Joy on 1 September 2026).
 *
 * SAL-07 ticks "password-protected export" and names no password. C9b settled
 * it as the employee code followed by the date of birth; Joy shortened it to
 * **the date of birth alone, DDMMYYYY**, after failing to open a payslip with
 * the date he correctly remembered: *"payslip password is very long, make it
 * very short like 20121992."*
 *
 * ⚠⚠ THE SHORTENING COSTS LESS THAN IT LOOKS, AND THAT IS WHY IT WAS ALLOWED.
 * The employee code was never secret: it is printed on the payslip itself, on
 * the salary sheet, in every export, and it runs in sequence. It added
 * characters, not protection. The date of birth was always the only private
 * half, and it is the half that remains. What is genuinely given up is length
 * against an offline brute force - eight digits of plausible birth dates is not
 * many - so this protects a payslip from a colleague who opens the wrong
 * attachment, and was never going to protect it from somebody determined.
 *
 * ⚠ **The rule is what gets shown, never the password.** A screen or an email
 * that prints the password alongside the protected file has protected nothing.
 *
 * ⚠⚠ A PDF ALREADY SENT KEEPS THE PASSWORD IT WAS BUILT WITH. Encryption
 * happens once, when the file is generated. The eight payslips emailed on
 * 1 September open with the old long form; everything issued after this change
 * opens with the date alone. Re-send a payslip if the old one confuses somebody.
 *
 * Where an employee has no date of birth recorded there is no password, and
 * `PayslipPdf` refuses the protected export with that reason rather than
 * falling back to something weaker. An export that claims protection it does
 * not have is worse than one that will not open.
 */
class PayslipPassword
{
    public const HINT = 'Your date of birth as DDMMYYYY, with no spaces or slashes. For example 20121992 for 20 December 1992.';

    public static function for(Employee $employee): ?string
    {
        if ($employee->date_of_birth === null) {
            return null;
        }

        return $employee->date_of_birth->format('dmY');
    }

    /**
     * Why an employee has no password, in words HR can act on.
     */
    public static function missingReason(Employee $employee): ?string
    {
        if (self::for($employee) !== null) {
            return null;
        }

        return 'This employee has no date of birth on record, so the payslip password cannot be formed (SAL-07). Add it on the employee record and the PDF becomes available.';
    }
}
