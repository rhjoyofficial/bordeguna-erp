<?php

namespace App\Modules\Hrm\Reporting\Definitions;

use App\Modules\Hrm\Enums\LoanInstallmentStatus;
use App\Modules\Hrm\Models\Loan;
use App\Modules\Hrm\Reporting\Concerns\ResolvesAssignments;
use App\Modules\Hrm\Reporting\Contracts\ReportDefinition;
use App\Modules\Hrm\Reporting\ReportColumn;
use App\Modules\Hrm\Reporting\ReportMeta;
use App\Modules\Hrm\Reporting\ReportParameter;
use App\Modules\Hrm\Reporting\ReportParameters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * RPT-02 #12 — Loan and advance balance.
 *
 * Every loan and salary advance with what is still owed on it, how much has been
 * recovered, and how many installments remain.
 *
 * ⚠ **The outstanding balance is the ledger sum, and this report re-derives it
 * the same way 3e does — in SQL, not by calling `Loan::outstanding()` per row.**
 * That method fires a query per loan, which on a report is an N+1 that only
 * appears once there are enough loans to notice. The arithmetic is identical:
 * the balance is `SUM(loan_ledger_entries.amount)` and is never stored, so the
 * figure on this report, the figure on the loan screen and the deduction payroll
 * takes are the same number by construction.
 *
 * ⚠ **No interest, no fees, no penalty.** ADJ-07 lists principal, installment,
 * start month and remaining balance and nothing else, so there is no such column
 * to report and none is invented here.
 *
 * A loan settled early shows a zero balance with its remaining installments
 * cancelled but still counted — 3e cancels rather than deletes them, because a
 * debt paid early is paid, not forgiven, and the schedule it was paid against is
 * part of the record.
 */
class LoanBalanceReport implements ReportDefinition
{
    use ResolvesAssignments;

    public function key(): string
    {
        return 'payroll_loan_balance';
    }

    public function name(): string
    {
        return 'Loan and advance balance';
    }

    public function description(): string
    {
        return 'Every loan and salary advance with its outstanding balance, what has been recovered so far, and the installments still to run.';
    }

    public function group(): string
    {
        return 'Payroll';
    }

    public function permission(): string
    {
        return 'hrm.payroll.manage';
    }

    public function parameters(): array
    {
        return [
            ReportParameter::office(),
            ReportParameter::department(),
            ReportParameter::employee(),
            ReportParameter::select('state', 'Show', [
                ['value' => 'outstanding', 'label' => 'Still owing'],
                ['value' => 'all', 'label' => 'All loans, settled included'],
                ['value' => 'settled', 'label' => 'Settled only'],
            ], default: 'outstanding'),
        ];
    }

    public function columns(?ReportParameters $parameters = null): array
    {
        return [
            ReportColumn::text('reference', 'Reference', 14),
            ReportColumn::text('employee_code', 'Code', 12),
            ReportColumn::text('employee_name', 'Employee', 24),
            ReportColumn::text('department', 'Department', 18),
            ReportColumn::text('type', 'Type', 14),
            ReportColumn::text('status', 'Status', 12),
            ReportColumn::date('disbursed_on', 'Disbursed'),
            ReportColumn::money('principal', 'Principal'),
            ReportColumn::money('installment_amount', 'Installment'),
            ReportColumn::money('repaid', 'Repaid'),
            ReportColumn::money('outstanding', 'Outstanding'),
            ReportColumn::integer('installments_paid', 'Paid', 8),
            ReportColumn::integer('installments_remaining', 'Left', 8),
        ];
    }

    public function totalColumns(?ReportParameters $parameters = null): array
    {
        // ⚠ `installment_amount` is deliberately not totalled. Adding up
        // everyone's monthly installment produces a figure that looks like next
        // month's recovery but is not — a settled loan contributes an
        // installment it will never take again.
        return ['principal', 'repaid', 'outstanding'];
    }

    public function rows(ReportParameters $parameters): LazyCollection
    {
        return LazyCollection::make(function () use ($parameters) {
            foreach ($this->query($parameters)->cursor() as $loan) {
                $principal = round((float) $loan->principal, 2);
                $outstanding = round((float) $loan->outstanding_balance, 2);

                yield [
                    'reference' => $loan->reference,
                    'employee_code' => $loan->employee_code,
                    'employee_name' => trim($loan->first_name.' '.$loan->last_name),
                    'department' => $loan->department_name,
                    // Cast by the model, unlike the joined columns below it —
                    // `loans.*` is selected, so these arrive as enums.
                    'type' => $loan->type?->label(),
                    'status' => $loan->status?->label(),
                    'disbursed_on' => $loan->disbursed_on,
                    'principal' => $principal,
                    'installment_amount' => $loan->installment_amount,
                    'repaid' => round($principal - $outstanding, 2),
                    'outstanding' => $outstanding,
                    'installments_paid' => (int) $loan->installments_paid,
                    'installments_remaining' => (int) $loan->installments_remaining,
                ];
            }
        });
    }

    public function estimatedRows(ReportParameters $parameters): int
    {
        return $this->query($parameters)->toBase()->getCountForPagination();
    }

    public function meta(ReportParameters $parameters): ReportMeta
    {
        return new ReportMeta(
            title: 'Loan and advance balance',
            subtitle: 'As at '.now()->format('d M Y'),
            // A loan balance is live — it moves with every disbursed payroll —
            // so there is no lock to report and pretending otherwise would be
            // the wrong reassurance.
            periodLocked: null,
            lockNote: 'Balances are the loan ledger as at the moment this was generated, and move with every disbursed payroll.',
        );
    }

    private function query(ReportParameters $parameters): Builder
    {
        $asAt = now()->toDateString();
        $state = $parameters->string('state') ?? 'outstanding';

        $balance = DB::raw('(select coalesce(sum(loan_ledger_entries.amount), 0)'
            .' from loan_ledger_entries where loan_ledger_entries.loan_id = loans.id) as outstanding_balance');

        $paid = DB::raw('(select count(*) from loan_installments'
            ." where loan_installments.loan_id = loans.id and loan_installments.status = '"
            .LoanInstallmentStatus::Paid->value."') as installments_paid");

        $remaining = DB::raw('(select count(*) from loan_installments'
            ." where loan_installments.loan_id = loans.id and loan_installments.status = '"
            .LoanInstallmentStatus::Scheduled->value."') as installments_remaining");

        $query = Loan::query()
            ->join('employees', 'employees.id', '=', 'loans.employee_id');

        $query = $this->joinAssignmentAsAt($query, 'loans.employee_id', $asAt);
        $query = $this->filterByAssignment($query, $parameters);

        return $query
            // A loan that was never disbursed has no ledger and owes nothing, so
            // it has no business on a balance report — 3e's own rule.
            ->whereNotNull('loans.applied_at')
            ->when(
                $parameters->int('employee_id'),
                fn (Builder $q, int $id) => $q->where('loans.employee_id', $id),
            )
            ->when(
                $state === 'outstanding',
                fn (Builder $q) => $q->whereRaw('(select coalesce(sum(amount), 0) from loan_ledger_entries where loan_id = loans.id) > 0.005'),
            )
            ->when(
                $state === 'settled',
                fn (Builder $q) => $q->whereRaw('(select coalesce(sum(amount), 0) from loan_ledger_entries where loan_id = loans.id) <= 0.005'),
            )
            ->orderBy('employees.employee_code')
            ->orderBy('loans.reference')
            ->select([
                'loans.*',
                'employees.employee_code',
                'employees.first_name',
                'employees.last_name',
                ...$this->assignmentColumns(),
                $balance,
                $paid,
                $remaining,
            ]);
    }
}
