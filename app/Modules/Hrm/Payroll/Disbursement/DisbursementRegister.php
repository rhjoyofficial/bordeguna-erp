<?php

namespace App\Modules\Hrm\Payroll\Disbursement;

use App\Modules\Hrm\Enums\SalaryPaymentMethod;
use App\Modules\Hrm\Models\DisbursementBatch;
use App\Modules\Hrm\Models\Payslip;
use Illuminate\Support\Collection;

/**
 * Turns a batch into the rows any disbursement format writes out.
 *
 * The single place that reads the database for a register, so a format
 * implementation never touches Eloquent and can be tested against a handful of
 * hand-built rows. Same split as the payroll engine's: gathering here, mapping
 * there.
 *
 * The amount is what is **still outstanding** on each payslip, not its net. A
 * register produced after a part payment must ask for the remainder, or paying
 * from it would pay some of it twice.
 */
class DisbursementRegister
{
    /**
     * @return Collection<int, DisbursementRow>
     */
    public function rowsFor(DisbursementBatch $batch): Collection
    {
        return $batch->payslips()
            ->with(['employee', 'payments'])
            ->get()
            ->map(fn (Payslip $payslip) => $this->row($batch, $payslip))
            ->filter()
            ->values();
    }

    private function row(DisbursementBatch $batch, Payslip $payslip): ?DisbursementRow
    {
        // HRM-002 (14 Oct 2026): the bank file asks only for what no version
        // of this month has paid yet.
        $outstanding = $payslip->outstanding();

        if ($outstanding <= 0.005) {
            return null;
        }

        $employee = $payslip->employee;

        return new DisbursementRow(
            employeeCode: (string) ($employee?->employee_code ?? ''),
            employeeName: (string) ($employee?->fullName() ?? ''),
            method: $employee?->salary_payment_method ?? $batch->method ?? SalaryPaymentMethod::Cash,
            amount: $outstanding,
            bankAccountName: $employee?->bank_account_name,
            bankAccountNumber: $employee?->bank_account_number,
            bankName: $employee?->bank_name,
            bankBranch: $employee?->bank_branch,
            bankRoutingNumber: $employee?->bank_routing_number,
            mfsProvider: $employee?->mfs_provider?->label(),
            mfsAccountNumber: $employee?->mfs_account_number,
            reference: $batch->reference,
        );
    }
}
