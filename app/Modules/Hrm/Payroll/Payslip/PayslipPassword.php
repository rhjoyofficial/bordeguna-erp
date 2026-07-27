<?php

namespace App\Modules\Hrm\Payroll\Payslip;

use App\Modules\Hrm\Models\Employee;

/**
 * The password on an exported payslip (SAL-07, Correction C9b).
 *
 * SAL-07 ticks "password-protected export" and names no password; nothing else
 * in the questionnaire supplies one. C9b settles it as the employee's own code
 * followed by their date of birth as DDMMYYYY — derived entirely from data
 * already held, so there is no password to distribute, store or lose, and it is
 * the ordinary convention for a Bangladeshi payslip.
 *
 * ⚠ **The rule is what gets shown, never the password.** A screen or an email
 * that prints the password alongside the protected file has protected nothing.
 *
 * Where an employee has no date of birth recorded there is no password, and
 * `PayslipPdf` refuses the protected export with that reason rather than
 * falling back to something weaker. An export that claims protection it does
 * not have is worse than one that will not open.
 */
class PayslipPassword
{
    public const HINT = 'Your employee code followed by your date of birth as DDMMYYYY — for example BDG-00000131121995.';

    public static function for(Employee $employee): ?string
    {
        if ($employee->date_of_birth === null || blank($employee->employee_code)) {
            return null;
        }

        return $employee->employee_code.$employee->date_of_birth->format('dmY');
    }

    /**
     * Why an employee has no password, in words HR can act on.
     */
    public static function missingReason(Employee $employee): ?string
    {
        if (self::for($employee) !== null) {
            return null;
        }

        return blank($employee->employee_code)
            ? 'This employee has no employee code, so the payslip password cannot be formed (SAL-07).'
            : 'This employee has no date of birth on record, so the payslip password cannot be formed (SAL-07). Add it on the employee record and the PDF becomes available.';
    }
}
