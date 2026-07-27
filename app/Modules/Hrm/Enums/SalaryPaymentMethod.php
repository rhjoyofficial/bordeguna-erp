<?php

namespace App\Modules\Hrm\Enums;

/**
 * How an employee's salary is actually paid (SAL-08).
 *
 * All four options were ticked, along with "varies by employee" — which is why
 * this lives on the employee record rather than being a company-wide setting.
 * It is also what lets a disbursement keep cash apart from bank apart from MFS
 * (DIS-01), and what tells the payroll validator which details it should be
 * insisting on: an employee paid in cash needs no account number, and demanding
 * one would block a payroll for a rule nobody stated.
 */
enum SalaryPaymentMethod: string
{
    case BankTransfer = 'bank_transfer';
    case Mfs = 'mfs';
    case Cash = 'cash';
    case Cheque = 'cheque';

    public function label(): string
    {
        return match ($this) {
            self::BankTransfer => 'Bank transfer',
            self::Mfs => 'Mobile financial service',
            self::Cash => 'Cash',
            self::Cheque => 'Cheque',
        };
    }

    /**
     * Whether paying this way depends on account details being on file.
     *
     * Cash and cheque are handed over in person, so nothing stored can be
     * missing. This is the distinction the old blunt "has a bank account or an
     * MFS number" check could not draw.
     */
    public function needsStoredAccount(): bool
    {
        return $this === self::BankTransfer || $this === self::Mfs;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
