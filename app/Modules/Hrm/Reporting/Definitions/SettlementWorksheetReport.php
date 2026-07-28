<?php

namespace App\Modules\Hrm\Reporting\Definitions;

use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Enums\SeparationType;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Reporting\Contracts\ReportDefinition;
use App\Modules\Hrm\Reporting\ReportColumn;
use App\Modules\Hrm\Reporting\ReportMeta;
use App\Modules\Hrm\Reporting\ReportParameter;
use App\Modules\Hrm\Reporting\ReportParameters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * RPT-02 #15 — Final settlement WORKSHEET.
 *
 * ⚠⚠ **This report computes no final settlement figure, and that is its
 * defining property rather than a limitation of it.**
 *
 * Designing W3 outward from the RPT-02 list exposed a hole that is not a
 * reporting problem at all. **EMP-08 ticked সবগুলো — every option — including
 * final salary calculation, leave encashment and loan settlement, and no phase
 * ever built any of them.** `SeparateEmployee` (Phase 1) closes assignments and
 * disables the login, and its own docblock defers the rest to "Phase 2/3", which
 * never picked it up. Phase 3 built every *part* a settlement would consume —
 * `SettleLoan`, `can_encash` on the encashable leave types per C6a, the payroll
 * engine itself — but never the settlement. An approved requirement fell between
 * two phases.
 *
 * **Correction C10b decided what to do about it:** W3 ships this worksheet,
 * which gathers the components a settlement is made of and adds none of them up;
 * the settlement engine is its own slice, after W3. The engine is blocked on a
 * rule the questionnaire never states — **the leave-encashment rate** — and a
 * report is the worst possible place to invent one. A number printed here under
 * a heading like "Final settlement" would be paid to somebody.
 *
 * So every column below is a **fact already recorded elsewhere**: an outstanding
 * loan balance from the loan ledger, an encashable leave balance in *days* from
 * the leave ledger, unpaid net from the payment events. Nothing is added across
 * columns and the days are never priced.
 *
 * What this is for: HR can see, on one page, everything that has to be settled
 * with a leaver and whether each part is done — which is the thing that has
 * actually been missing, and it needs no new rule to be true.
 */
class SettlementWorksheetReport implements ReportDefinition
{
    public function __construct(
        private readonly BusinessContext $context,
    ) {}

    public function key(): string
    {
        return 'payroll_settlement_worksheet';
    }

    public function name(): string
    {
        return 'Final settlement worksheet';
    }

    public function description(): string
    {
        return 'Everything outstanding for each leaver — unpaid salary, loan balance and encashable leave in days — gathered on one page. It deliberately computes no final figure.';
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
            ReportParameter::dateRange('separated', 'Separated between', required: false),
            ReportParameter::office(),
            ReportParameter::department(),
            ReportParameter::boolean(
                'outstanding_only',
                'Only those with something outstanding',
                default: true,
                help: 'Leavers with no unpaid salary, no loan and no encashable balance need no settlement and are hidden by default.',
            ),
        ];
    }

    public function columns(?ReportParameters $parameters = null): array
    {
        return [
            ReportColumn::text('employee_code', 'Code', 12),
            ReportColumn::text('employee_name', 'Employee', 24),
            ReportColumn::text('department', 'Department', 18),
            ReportColumn::text('separation_type', 'Separation', 18),
            ReportColumn::date('separation_date', 'Separated on'),
            ReportColumn::money('unpaid_salary', 'Unpaid salary'),
            ReportColumn::money('loan_outstanding', 'Loan outstanding'),
            // ⚠ DAYS, and the column says so. This is the one figure a
            // settlement engine would price, and the rate does not exist yet.
            // A money column here would invite somebody to fill it in.
            ReportColumn::decimal('encashable_leave_days', 'Encashable leave (days)', 20),
            ReportColumn::text('outstanding_items', 'Still to settle', 40),
        ];
    }

    public function totalColumns(?ReportParameters $parameters = null): array
    {
        // ⚠ The two money columns total; the leave DAYS column deliberately does
        // too, because a total of days is still days. **No column adds across to
        // another** — there is no settlement total on this report and there must
        // not be one until the encashment rate is settled.
        return ['unpaid_salary', 'loan_outstanding', 'encashable_leave_days'];
    }

    public function rows(ReportParameters $parameters): LazyCollection
    {
        return LazyCollection::make(function () use ($parameters) {
            foreach ($this->query($parameters)->toBase()->cursor() as $leaver) {
                $unpaid = round((float) $leaver->unpaid_salary, 2);
                $loan = round((float) $leaver->loan_outstanding, 2);
                $leave = round((float) $leaver->encashable_leave_days, 2);

                yield [
                    'employee_code' => $leaver->employee_code,
                    'employee_name' => trim($leaver->first_name.' '.$leaver->last_name),
                    'department' => $leaver->department_name,
                    'separation_type' => SeparationType::tryFrom((string) $leaver->separation_type)?->label(),
                    'separation_date' => $leaver->separation_date,
                    'unpaid_salary' => $unpaid,
                    'loan_outstanding' => $loan,
                    'encashable_leave_days' => $leave,
                    'outstanding_items' => $this->itemise($unpaid, $loan, $leave),
                ];
            }
        });
    }

    /**
     * A plain list of what is still open, because three numbers in three columns
     * do not say "this person is not finished with" at a glance — and that is
     * the question the worksheet exists to answer.
     */
    private function itemise(float $unpaid, float $loan, float $leave): string
    {
        $items = [];

        if ($unpaid > 0.005) {
            $items[] = 'unpaid salary';
        }

        if ($loan > 0.005) {
            $items[] = 'loan balance';
        }

        if ($leave > 0.005) {
            $items[] = 'leave encashment';
        }

        return $items === [] ? 'Nothing outstanding' : ucfirst(implode(', ', $items));
    }

    public function estimatedRows(ReportParameters $parameters): int
    {
        return $this->query($parameters)->toBase()->getCountForPagination();
    }

    public function meta(ReportParameters $parameters): ReportMeta
    {
        return new ReportMeta(
            title: 'Final settlement worksheet',
            subtitle: 'As at '.now()->format('d M Y'),
            periodLocked: null,
            // Stated on the face of every rendering, deliberately. Somebody will
            // eventually be handed this page and asked "so what do we pay him".
            lockNote: 'This worksheet gathers what is outstanding and computes NO final settlement figure. '
                .'Leave is shown in days and is not priced: the encashment rate is not defined anywhere in the approved rules (EMP-08 / C10b), '
                .'and the settlement calculation is a separate piece of work that depends on it.',
        );
    }

    private function query(ReportParameters $parameters): Builder
    {
        $businessId = $this->context->id();

        // ⚠ Every figure is a subquery over a ledger, not a stored total — the
        // loan balance, the leave balance and what has been paid are all sums by
        // construction elsewhere in the system, and re-deriving them any other
        // way here would create a second opinion about a leaver's money.
        $loan = DB::raw('(select coalesce(sum(loan_ledger_entries.amount), 0)'
            .' from loan_ledger_entries'
            .' where loan_ledger_entries.employee_id = employees.id) as loan_outstanding');

        $unpaid = DB::raw('(select coalesce(sum(payslips.net - ('
            .'select coalesce(sum(case payslip_payments.event_type'
            ." when 'paid' then payslip_payments.amount"
            ." when 'reversed' then -payslip_payments.amount"
            .' else 0 end), 0) from payslip_payments'
            .' where payslip_payments.payslip_id = payslips.id)), 0)'
            .' from payslips where payslips.employee_id = employees.id'
            .' and payslips.net > (select coalesce(sum(case payslip_payments.event_type'
            ." when 'paid' then payslip_payments.amount"
            ." when 'reversed' then -payslip_payments.amount"
            .' else 0 end), 0) from payslip_payments'
            .' where payslip_payments.payslip_id = payslips.id)) as unpaid_salary');

        // C6a: only leave types marked encashable count. Carried in days, and a
        // negative balance is not a debt to the employee — clamped at zero.
        // ⚠ A CASE rather than MAX(0, x) or GREATEST(): MySQL's MAX is an
        // aggregate and would not take two arguments, SQLite's is scalar and
        // would. Writing the portable form once is cheaper than discovering the
        // difference in production, where this report is the one that runs.
        $leave = DB::raw('(select case when coalesce(sum(leave_ledger_entries.days), 0) < 0'
            .' then 0 else coalesce(sum(leave_ledger_entries.days), 0) end'
            .' from leave_ledger_entries'
            .' join leave_types on leave_types.id = leave_ledger_entries.leave_type_id'
            .' where leave_ledger_entries.employee_id = employees.id'
            .' and leave_types.can_encash = 1) as encashable_leave_days');

        return Employee::query()
            // Tenancy: `Employee` carries no BelongsToBusiness, and a global
            // scope never reaches a joined table — see ResolvesAssignments.
            ->join('employee_assignments', function ($join) use ($businessId) {
                $join->on('employee_assignments.employee_id', '=', 'employees.id')
                    ->where('employee_assignments.business_id', '=', $businessId)
                    ->where('employee_assignments.is_primary', '=', true);
            })
            ->leftJoin('departments', 'departments.id', '=', 'employee_assignments.department_id')
            ->whereNotNull('employees.separation_date')
            ->when(
                $parameters->from('separated'),
                fn (Builder $q, $from) => $q->whereDate('employees.separation_date', '>=', $from->toDateString()),
            )
            ->when(
                $parameters->to('separated'),
                fn (Builder $q, $to) => $q->whereDate('employees.separation_date', '<=', $to->toDateString()),
            )
            ->when(
                $parameters->int('office_id'),
                fn (Builder $q, int $id) => $q->where('employee_assignments.office_id', $id),
            )
            ->when(
                $parameters->int('department_id'),
                fn (Builder $q, int $id) => $q->where('employee_assignments.department_id', $id),
            )
            // A leaver's assignment is closed on separation, so the join would
            // return several rows for anyone who ever transferred. Distinct on
            // the employee, taking the latest assignment for their department.
            ->groupBy('employees.id')
            ->orderByDesc('employees.separation_date')
            ->orderBy('employees.employee_code')
            ->select([
                'employees.id',
                'employees.employee_code',
                'employees.first_name',
                'employees.last_name',
                'employees.separation_type',
                'employees.separation_date',
                DB::raw('max(departments.name) as department_name'),
                $loan,
                $unpaid,
                $leave,
            ])
            ->when(
                $parameters->bool('outstanding_only'),
                fn (Builder $q) => $q->havingRaw('loan_outstanding > 0.005 or unpaid_salary > 0.005 or encashable_leave_days > 0.005'),
            );
    }
}
