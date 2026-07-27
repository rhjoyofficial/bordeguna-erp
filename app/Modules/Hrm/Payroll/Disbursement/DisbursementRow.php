<?php

namespace App\Modules\Hrm\Payroll\Disbursement;

use App\Modules\Hrm\Enums\SalaryPaymentMethod;

/**
 * One line of a disbursement register — everything any payment route could need
 * about one employee's payment, resolved once.
 *
 * A plain value object with no database behind it, so a format implementation
 * is a pure mapping from these fields onto whatever columns a particular bank
 * wants. That separation is the whole reason DIS-06 can stay open: when the
 * Brac Bank sample finally arrives, it becomes a new format reading these same
 * fields, and nothing about how the rows are gathered has to change.
 */
final class DisbursementRow
{
    public function __construct(
        public readonly string $employeeCode,
        public readonly string $employeeName,
        public readonly SalaryPaymentMethod $method,
        public readonly float $amount,
        public readonly ?string $bankAccountName = null,
        public readonly ?string $bankAccountNumber = null,
        public readonly ?string $bankName = null,
        public readonly ?string $bankBranch = null,
        public readonly ?string $bankRoutingNumber = null,
        public readonly ?string $mfsProvider = null,
        public readonly ?string $mfsAccountNumber = null,
        public readonly ?string $reference = null,
    ) {}

    /**
     * The account this payment actually goes to, whichever route it takes.
     * Cash and cheque have none, and saying so is more useful than an empty
     * column a reader has to interpret.
     */
    public function destination(): string
    {
        return match ($this->method) {
            SalaryPaymentMethod::BankTransfer => $this->bankAccountNumber ?? '',
            SalaryPaymentMethod::Mfs => $this->mfsAccountNumber ?? '',
            SalaryPaymentMethod::Cash, SalaryPaymentMethod::Cheque => 'Paid in person',
        };
    }
}
