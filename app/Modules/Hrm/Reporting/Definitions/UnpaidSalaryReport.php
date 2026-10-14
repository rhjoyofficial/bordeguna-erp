<?php

namespace App\Modules\Hrm\Reporting\Definitions;

use App\Modules\Hrm\Enums\PayslipPaymentStatus;
use App\Modules\Hrm\Enums\SalaryPaymentMethod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Reporting\Concerns\CountsRows;
use App\Modules\Hrm\Reporting\Concerns\ResolvesAssignments;
use App\Modules\Hrm\Reporting\Concerns\SignsFromPayrollRun;
use App\Modules\Hrm\Reporting\Contracts\ReportDefinition;
use App\Modules\Hrm\Reporting\ReportColumn;
use App\Modules\Hrm\Reporting\ReportMeta;
use App\Modules\Hrm\Reporting\ReportParameter;
use App\Modules\Hrm\Reporting\ReportParameters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * RPT-02 #14 — Unpaid salary.
 *
 * Who has not been paid, how much is still owed, and — where a payment was
 * attempted and failed — why. 3f built exactly this data; the report is the
 * question it was built to answer.
 *
 * ⚠ **Paid is a SUM of payment events, never a stored status.** 3f's
 * `payslip_payments` is append-only: a payment can succeed, fail, be reversed
 * and be retried, and no boolean carries that. So the outstanding figure here is
 * `net − Σ(paid − reversed)`, computed the same way `Payslip::paidTotal()`
 * computes it, and the two cannot disagree because neither stores anything.
 *
 * ⚠ **A failure contributes nothing to the paid total.** It did not take money
 * back — it never delivered any. That is `PaymentEventType::signedFactor()`'s
 * rule and it is reproduced in SQL here rather than reinvented: reading a
 * failure as a negative would show a *doubled* outstanding amount on exactly the
 * payslip somebody is chasing.
 *
 * ⚠ **Three states, not two.** A partial payment is not "not paid" — treating it
 * as unpaid is the bug 3f found in the reconciler, and on this report it would
 * ask Finance to pay the whole net a second time.
 */
class UnpaidSalaryReport implements ReportDefinition
{
    use CountsRows, ResolvesAssignments;
    use SignsFromPayrollRun;

    /**
     * HRM-002 (audit 14 Oct 2026). Paid counts the payments on EVERY version
     * of the payslip's month for that employee, the same rule
     * Payslip::settledTotal() and SettlementFigures::unpaidSalarySql follow,
     * so an unlocked month's new version is not reported unpaid for money
     * the locked version already paid.
     */
    private const ACROSS_VERSIONS = ' join payslips as paid_slips on paid_slips.id = payslip_payments.payslip_id'
        .' join payroll_runs as paid_runs on paid_runs.id = paid_slips.payroll_run_id'
        .' where paid_slips.employee_id = payslips.employee_id'
        .' and paid_runs.payroll_period_id = (select own_runs.payroll_period_id from payroll_runs as own_runs'
        .' where own_runs.id = payslips.payroll_run_id)';

    public function key(): string
    {
        return 'payroll_unpaid_salary';
    }

    public function name(): string
    {
        return 'Unpaid salary';
    }

    public function description(): string
    {
        return 'Everyone on a payroll run whose net has not been fully paid, with what has reached them so far and the reason for any failure.';
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
            ReportParameter::payrollRun(),
            ReportParameter::office(),
            ReportParameter::department(),
            ReportParameter::boolean(
                'include_partial',
                'Include partly paid',
                default: true,
                help: 'A partly paid payslip still owes something. Excluding them leaves only those where nothing at all has been paid.',
            ),
        ];
    }

    public function columns(?ReportParameters $parameters = null): array
    {
        return [
            ReportColumn::text('employee_code', 'Code', 12),
            ReportColumn::text('employee_name', 'Employee', 24),
            ReportColumn::text('office', 'Office', 18),
            ReportColumn::text('department', 'Department', 18),
            ReportColumn::text('payment_method', 'Paid by', 16),
            ReportColumn::money('net', 'Net payable'),
            ReportColumn::money('paid', 'Paid so far'),
            ReportColumn::money('outstanding', 'Outstanding'),
            ReportColumn::text('status', 'Status', 16),
            ReportColumn::text('failure_reason', 'Last failure', 34),
            ReportColumn::boolean('is_blocked', 'Blocked'),
        ];
    }

    public function totalColumns(?ReportParameters $parameters = null): array
    {
        return ['net', 'paid', 'outstanding'];
    }

    public function rows(ReportParameters $parameters): LazyCollection
    {
        return LazyCollection::make(function () use ($parameters) {
            foreach ($this->query($parameters)->cursor() as $payslip) {
                $net = round((float) $payslip->net, 2);
                $paid = round((float) $payslip->paid_total, 2);

                yield [
                    'employee_code' => $payslip->employee_code,
                    'employee_name' => trim($payslip->first_name.' '.$payslip->last_name),
                    'office' => $payslip->office_name,
                    'department' => $payslip->department_name,
                    'payment_method' => SalaryPaymentMethod::tryFrom((string) $payslip->salary_payment_method)?->label(),
                    'net' => $net,
                    'paid' => $paid,
                    // Never negative: an overpayment is a different problem and
                    // showing it as a negative amount owed would invite someone
                    // to net it off against a colleague's shortfall.
                    'outstanding' => max(0, round($net - $paid, 2)),
                    'status' => PayslipPaymentStatus::resolve($paid, $net)->label(),
                    'failure_reason' => $payslip->failure_reason,
                    'is_blocked' => $payslip->is_blocked,
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
        $run = $this->run($parameters);

        $signs = $this->signatories($run);

        return new ReportMeta(
            title: 'Unpaid salary',
            subtitle: $run === null
                ? null
                : sprintf('%s — run v%d (%s)', (string) $run->period?->label(), $run->version, $run->status->label()),
            periodLocked: $run?->status?->isFinal() ?? false,
            preparedBy: $signs['preparedBy'],
            checkedBy: $signs['checkedBy'],
            approvedBy: $signs['approvedBy'],
        );
    }

    private function run(ReportParameters $parameters): ?PayrollRun
    {
        $id = $parameters->int('run_id');

        return $id === null ? null : PayrollRun::with('period')->find($id);
    }

    private function query(ReportParameters $parameters): Builder
    {
        $run = $this->run($parameters);
        $asAt = ($run?->period?->end_date ?? now())->toDateString();

        // ⚠ The paid total as a correlated subquery rather than a per-row call
        // to Payslip::paidTotal(). Identical arithmetic — including a failure
        // counting for nothing — but one query for the whole run instead of one
        // per employee, which is what keeps the cursor streaming.
        $paid = DB::raw('(select coalesce(sum(case payslip_payments.event_type'
            ." when 'paid' then payslip_payments.amount"
            ." when 'reversed' then -payslip_payments.amount"
            .' else 0 end), 0) from payslip_payments'
            .self::ACROSS_VERSIONS.') as paid_total');

        // The reason on the most recent event, shown only where that event is
        // itself a failure — a later successful retry clears it, exactly as
        // Payslip::currentFailure() decides.
        $failure = DB::raw('(select case when p.event_type = \'failed\' then p.failure_reason end'
            .' from payslip_payments p where p.payslip_id = payslips.id'
            .' order by p.id desc limit 1) as failure_reason');

        $query = Payslip::query()
            ->join('employees', 'employees.id', '=', 'payslips.employee_id');

        $query = $this->joinAssignmentAsAt($query, 'payslips.employee_id', $asAt);
        $query = $this->filterByAssignment($query, $parameters);

        return $query
            ->when(
                $parameters->int('run_id'),
                fn (Builder $q, int $id) => $q->where('payslips.payroll_run_id', $id),
            )
            // Settled payslips are excluded in SQL rather than in the loop, so
            // the C10e row estimate describes the report that will actually be
            // produced instead of the whole run.
            ->whereRaw($this->outstandingCondition($parameters->bool('include_partial')))
            ->orderBy('employees.employee_code')
            ->select([
                'payslips.*',
                'employees.employee_code',
                'employees.first_name',
                'employees.last_name',
                'employees.salary_payment_method',
                ...$this->assignmentColumns(),
                $paid,
                $failure,
            ]);
    }

    /**
     * ⚠ Compared at two decimal places, as `PayslipPaymentStatus::resolve()`
     * does. Without the rounding a payslip stays eternally on this report for a
     * millionth of a taka, and somebody spends an afternoon on it.
     */
    private function outstandingCondition(bool $includePartial): string
    {
        $paidSum = '(select coalesce(sum(case payslip_payments.event_type'
            ." when 'paid' then payslip_payments.amount"
            ." when 'reversed' then -payslip_payments.amount"
            .' else 0 end), 0) from payslip_payments'
            .self::ACROSS_VERSIONS.')';

        return $includePartial
            ? "round(payslips.net, 2) > round({$paidSum}, 2)"
            : "round({$paidSum}, 2) <= 0 and round(payslips.net, 2) > 0";
    }
}
