<?php

namespace App\Modules\Hrm\Enums;

/**
 * ADJ-07 names two things in one breath — "Loan/Salary Advance" — and gives them
 * one identical answer.
 *
 * They are therefore one mechanism with two labels, not two subsystems. The
 * distinction exists because it is the one an employee reading a payslip cares
 * about: a salary advance is money brought forward, a loan is money borrowed.
 * Nothing in the arithmetic differs.
 */
enum LoanType: string
{
    case Loan = 'loan';
    case Advance = 'advance';

    public function label(): string
    {
        return match ($this) {
            self::Loan => 'Loan',
            self::Advance => 'Salary Advance',
        };
    }

    /**
     * How the deduction reads on a payslip.
     */
    public function deductionLabel(): string
    {
        return match ($this) {
            self::Loan => 'Loan Repayment',
            self::Advance => 'Salary Advance Recovery',
        };
    }
}
