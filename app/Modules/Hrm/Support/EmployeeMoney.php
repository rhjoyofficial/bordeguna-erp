<?php

namespace App\Modules\Hrm\Support;

use App\Models\User;
use App\Modules\Accounting\Support\EmployeeCash;
use App\Modules\Accounting\Support\EmployeeStatement;
use App\Modules\Accounting\Support\Money;
use App\Modules\Hrm\Enums\PayrollStatus;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\ExpenseClaim;
use App\Modules\Hrm\Models\Loan;
use App\Modules\Hrm\Models\LoanLedgerEntry;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Models\PayslipPayment;
use App\Modules\Hrm\Models\StaffPurchaseRequest;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Support\Lifetime\LifetimeRange;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * ⭐ B23 THE EMPLOYEE'S MONEY TAB (25 Oct 2026; R77).
 *
 * Joy: a page for every employee with all lifetime information. The employee
 * page had no money on it at all. This gathers, for one person, from the
 * tables that own each figure:
 *
 *   salary     every payslip of a payroll that was approved (approved,
 *              disbursed or locked): net, and what was actually paid
 *              (PayslipPayment, a reversal taking a payment back)
 *   loans      advances and loans: principal, repaid, still owed (the loan
 *              ledger, one grouped sum)
 *   claims     expense claims and where each stands
 *   purchases  staff purchases and the invoice each made
 *   set-offs   what was taken from salary against a staff purchase account
 *              (a customer receipt of the payroll-offset kind)
 *   ledger     the person's own heads (EmployeeStatement: 1170 advances,
 *              2130 salary payable, 2140 deductions held, 2145 claims owed)
 *
 *   cash       the company cash this person holds or is owed (initial plan
 *              B3, R63: a money account of the Employee cash kind with him as
 *              its holder), with its latest movements (EmployeeCash)
 *
 * ⚠ Permissions: the page itself needs `hrm.employees.manage`. Pay (salary
 * and loans) also needs `hrm.payroll.manage`; the ledger heads need
 * `accounting.view`. A part the viewer may not read is null, never an empty
 * list that looks like "never paid".
 *
 * ⚠ Fixed query count, whatever the number of payslips, loans or claims.
 */
final class EmployeeMoney
{
    /** A payroll that reached these states paid (or will pay) its payslips. */
    private const PAYING = [PayrollStatus::Approved, PayrollStatus::Disbursed, PayrollStatus::Locked];

    public function __construct(
        private readonly Employee $employee,
        private readonly ?User $viewer,
        private readonly LifetimeRange $range,
    ) {}

    /** @return array<string, mixed> */
    public function props(): array
    {
        return [
            'range' => $this->range->toArray(),
            'salary' => $this->salary(),
            'loans' => $this->loans(),
            'claims' => $this->claims(),
            'purchases' => $this->purchases(),
            'set_offs' => $this->setOffs(),
            'ledger' => $this->ledger(),
            'cash_account' => $this->cashAccount(),
        ];
    }

    /** @return array{rows: list<array<string, mixed>>, net: string, paid: string}|null */
    public function salary(): ?array
    {
        if (! $this->sees('hrm.payroll.manage')) {
            return null;
        }

        $payslips = Payslip::query()
            ->where('employee_id', $this->employee->id)
            ->whereHas('run', fn ($run) => $run->whereIn('status', array_map(fn (PayrollStatus $s) => $s->value, self::PAYING))
                ->whereHas('period', fn ($period) => $this->range->apply($period, 'end_date')))
            ->with('run.period:id,year,month,start_date,end_date')
            ->get();

        $paid = PayslipPayment::query()
            ->whereIn('payslip_id', $payslips->pluck('id'))
            ->settling()
            ->get(['payslip_id', 'event_type', 'amount'])
            ->groupBy('payslip_id')
            ->map(fn ($payments) => (int) round($payments->sum(fn (PayslipPayment $payment) => $payment->signedAmount()) * 100));

        $mayOpen = Route::has('hrm.payslips.show');
        $rows = $payslips
            ->sortByDesc(fn (Payslip $payslip) => $payslip->run?->period?->end_date?->toDateString())
            ->map(fn (Payslip $payslip) => [
                'id' => $payslip->id,
                'year' => $payslip->run?->period?->year,
                'month' => $payslip->run?->period?->month,
                'status' => $payslip->run?->status?->value,
                'earnings' => Money::ofTaka((string) $payslip->total_earnings)->format(),
                'deductions' => Money::ofTaka((string) $payslip->total_deductions)->format(),
                'net' => Money::ofTaka((string) $payslip->net)->format(),
                'paid' => Money::ofMinor((int) ($paid[$payslip->id] ?? 0))->format(),
                'url' => $mayOpen ? route('hrm.payslips.show', $payslip->id, false) : null,
            ])
            ->values();

        return [
            'rows' => $rows->all(),
            'net' => Money::sum($payslips->map(fn (Payslip $payslip) => Money::ofTaka((string) $payslip->net)))->format(),
            'paid' => Money::ofMinor((int) $paid->sum())->format(),
        ];
    }

    /** @return list<array<string, mixed>>|null */
    public function loans(): ?array
    {
        if (! $this->sees('hrm.payroll.manage')) {
            return null;
        }

        // The day the money went out; a loan not handed over yet, the day it was asked for.
        $loans = $this->range->apply(Loan::query(), DB::raw('COALESCE(disbursed_on, created_at)'))
            ->where('employee_id', $this->employee->id)
            ->orderByDesc('id')
            ->get();

        // What each still owes: the loan ledger's own sum (Loan::outstanding), in one read.
        $owed = LoanLedgerEntry::query()
            ->whereIn('loan_id', $loans->pluck('id'))
            ->selectRaw('loan_id, SUM(amount) AS owed')
            ->groupBy('loan_id')
            ->pluck('owed', 'loan_id');

        return $loans->map(function (Loan $loan) use ($owed) {
            $outstanding = Money::ofTaka(number_format((float) ($owed[$loan->id] ?? 0), 2, '.', ''));
            $principal = Money::ofTaka((string) $loan->principal);

            return [
                'id' => $loan->id,
                'reference' => $loan->reference,
                'type' => $loan->type?->value,
                'status' => $loan->status?->value,
                'disbursed_on' => $loan->disbursed_on?->toDateString(),
                'principal' => $principal->format(),
                'repaid' => $loan->disbursed_on === null ? '0.00' : $principal->plus($outstanding->negated())->format(),
                'outstanding' => $outstanding->format(),
            ];
        })->values()->all();
    }

    /** @return list<array<string, mixed>> */
    public function claims(): array
    {
        return $this->range->apply(ExpenseClaim::query(), 'spent_on')
            ->where('employee_id', $this->employee->id)
            ->with('approvalRequest')
            ->orderByDesc('spent_on')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ExpenseClaim $claim) => [
                'id' => $claim->id,
                'number' => $claim->claim_number,
                'date' => $claim->spent_on?->toDateString(),
                'purpose' => $claim->purpose,
                'amount' => Money::ofTaka((string) $claim->amount)->format(),
                'state' => $claim->state(),
                'settled' => $claim->settled_at !== null,
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function purchases(): array
    {
        return $this->range->apply(StaffPurchaseRequest::query(), 'created_at')
            ->where('employee_id', $this->employee->id)
            ->with('invoice:id,invoice_number,invoice_date,total')
            ->orderByDesc('id')
            ->get()
            ->map(fn (StaffPurchaseRequest $request) => [
                'id' => $request->id,
                'number' => $request->number,
                'date' => $request->created_at?->toDateString(),
                'status' => is_object($request->status) ? $request->status->value : (string) $request->status,
                'payment_mode' => $request->payment_mode,
                'invoice' => $request->invoice?->invoice_number,
                'amount' => $request->invoice === null ? null : Money::ofTaka((string) $request->invoice->total)->format(),
            ])
            ->values()
            ->all();
    }

    /**
     * What was taken from salary against the person's staff purchase account.
     *
     * @return list<array<string, mixed>>
     */
    public function setOffs(): array
    {
        $customerIds = Customer::query()->where('employee_id', $this->employee->id)->pluck('id');

        if ($customerIds->isEmpty()) {
            return [];
        }

        return $this->range->apply(CustomerReceipt::query(), 'received_on')
            ->whereIn('customer_id', $customerIds)
            ->where('kind', CustomerReceipt::KIND_PAYROLL_OFFSET)
            ->orderByDesc('received_on')
            ->get()
            ->map(fn (CustomerReceipt $receipt) => [
                'id' => $receipt->id,
                'date' => $receipt->received_on?->toDateString(),
                'reference' => $receipt->reference,
                'amount' => Money::ofTaka((string) $receipt->amount)->format(),
                'reversed' => $receipt->isReversed(),
            ])
            ->values()
            ->all();
    }

    /**
     * The person's own ledger heads, null without `accounting.view`.
     *
     * @return list<array<string, mixed>>|null
     */
    public function ledger(): ?array
    {
        if (! $this->sees('accounting.view')) {
            return null;
        }

        $statement = EmployeeStatement::for($this->employee, $this->range->statementFrom(), $this->range->statementTo());

        return $statement->heads->map(fn (array $head) => [
            'code' => $head['account']->code,
            'name' => $head['account']->name,
            'opening' => $head['opening']->format(),
            'debits' => $head['debits']->format(),
            'credits' => $head['credits']->format(),
            'closing' => $head['closing']->format(),
            'lines' => count($head['lines']),
        ])->values()->all();
    }

    /**
     * The Employee cash accounts this person holds (B3, R63), the same rows
     * the employee page's cash card shows. Null when he holds none, or for a
     * reader who neither reads the books nor manages staff.
     *
     * @return array{accounts: list<array<string, mixed>>}|null
     */
    public function cashAccount(): ?array
    {
        if (! $this->sees('accounting.view') && ! $this->sees('hrm.employees.manage')) {
            return null;
        }

        $accounts = EmployeeCash::forEmployee($this->employee);

        return $accounts === [] ? null : ['accounts' => array_values($accounts)];
    }

    private function sees(string $ability): bool
    {
        return $this->viewer === null || $this->viewer->can($ability);
    }
}
