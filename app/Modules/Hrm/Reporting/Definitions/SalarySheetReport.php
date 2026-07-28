<?php

namespace App\Modules\Hrm\Reporting\Definitions;

use App\Modules\Hrm\Enums\SalaryPaymentMethod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Reporting\Concerns\CountsRows;
use App\Modules\Hrm\Reporting\Contracts\ReportDefinition;
use App\Modules\Hrm\Reporting\ReportColumn;
use App\Modules\Hrm\Reporting\ReportMeta;
use App\Modules\Hrm\Reporting\ReportParameter;
use App\Modules\Hrm\Reporting\ReportParameters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;

/**
 * RPT-02 #8 — Salary sheet.
 *
 * One row per employee for one payroll run: gross, earnings, deductions and
 * net. The component-by-component breakdown is a **different** report (#11);
 * a salary sheet that expanded every component would be a hundred columns wide
 * and would stop being the thing people mean by a salary sheet.
 *
 * The second of the two reports built alongside the framework, chosen because
 * it is the money half — it exercises the C10d access gate that matters
 * (ACL-02: a Department Manager must not see this at all), the money column
 * type, and column totals.
 *
 * **It reads finished payslips and recomputes nothing.** This is the rule
 * `AccountingExport` states in 3f and it is not softened here: the sheet must
 * agree with the payslips it came from, by construction rather than by
 * coincidence. A recomputation would be a second opinion about what someone
 * was paid.
 *
 * **A run is a version (WFL-06).** A reopened payroll produces v2 and cancels
 * v1, so a salary sheet is always *of a run*, never merely of a month —
 * printing "July 2026" without the version would make two contradictory sheets
 * look like the same document.
 */
class SalarySheetReport implements ReportDefinition
{
    use CountsRows;

    public function key(): string
    {
        return 'payroll_salary_sheet';
    }

    public function name(): string
    {
        return 'Salary sheet';
    }

    public function description(): string
    {
        return 'Every employee on one payroll run with their gross, earnings, deductions and net pay. Component detail is in the Earnings & deductions breakdown.';
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
                'include_blocked',
                'Include blocked payslips',
                default: true,
                help: 'A blocked payslip (PAY-09/PAY-11) is not payable. Excluding them makes the total match what will actually be disbursed.',
            ),
        ];
    }

    public function columns(?ReportParameters $parameters = null): array
    {
        return [
            ReportColumn::text('employee_code', 'Code', 12),
            ReportColumn::text('employee_name', 'Employee', 26),
            ReportColumn::text('office', 'Office', 18),
            ReportColumn::text('department', 'Department', 18),
            ReportColumn::text('payment_method', 'Paid by', 16),
            ReportColumn::money('gross_salary', 'Gross'),
            ReportColumn::money('total_earnings', 'Earnings'),
            ReportColumn::money('total_deductions', 'Deductions'),
            ReportColumn::money('net', 'Net payable'),
            // A blocked payslip appears with its reason rather than being
            // silently dropped: "why is this person missing from the sheet" is
            // the question a silent filter creates.
            ReportColumn::boolean('is_blocked', 'Blocked'),
            ReportColumn::text('block_reason', 'Block reason', 30),
        ];
    }

    public function totalColumns(?ReportParameters $parameters = null): array
    {
        return ['gross_salary', 'total_earnings', 'total_deductions', 'net'];
    }

    public function rows(ReportParameters $parameters): LazyCollection
    {
        return LazyCollection::make(function () use ($parameters) {
            foreach ($this->query($parameters)->cursor() as $payslip) {
                $reasons = $payslip->block_reasons;

                yield [
                    'employee_code' => $payslip->employee_code,
                    'employee_name' => trim($payslip->first_name.' '.$payslip->last_name),
                    'office' => $payslip->office_name,
                    'department' => $payslip->department_name,
                    // Selected from the joined employees table, so it arrives
                    // as a raw string rather than through the model's cast —
                    // rendered through the enum here so the report reads
                    // "Bank transfer", not "bank_transfer".
                    'payment_method' => SalaryPaymentMethod::tryFrom((string) $payslip->salary_payment_method)?->label(),
                    'gross_salary' => $payslip->gross_salary,
                    'total_earnings' => $payslip->total_earnings,
                    'total_deductions' => $payslip->total_deductions,
                    'net' => $payslip->net,
                    'is_blocked' => $payslip->is_blocked,
                    'block_reason' => is_array($reasons) ? implode('; ', $reasons) : null,
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

        return new ReportMeta(
            title: 'Salary sheet',
            subtitle: $run === null
                ? null
                : sprintf('%s — run v%d (%s)', (string) $run->period?->label(), $run->version, $run->status->label()),
            // A payroll run's figures are frozen once it is locked; before that
            // a recalculation can still move them. Stating which is true is the
            // difference between a report and a rumour.
            periodLocked: $run?->status?->isFinal() ?? false,
            lockNote: ($run?->status?->isFinal() ?? false)
                ? null
                : 'This payroll run is not yet locked — a recalculation can still change these figures.',
        );
    }

    private function run(ReportParameters $parameters): ?PayrollRun
    {
        $id = $parameters->int('run_id');

        return $id === null ? null : PayrollRun::with('period')->find($id);
    }

    /**
     * As with the daily attendance report, the assignment is resolved **as at
     * the run's period end**, not as at today — a payroll sheet for June must
     * file people where they worked in June.
     */
    private function query(ReportParameters $parameters): Builder
    {
        $run = $this->run($parameters);
        $asAt = ($run?->period?->end_date ?? now())->toDateString();

        return Payslip::query()
            ->join('employees', 'employees.id', '=', 'payslips.employee_id')
            ->leftJoin('employee_assignments', function ($join) use ($asAt) {
                $join->on('employee_assignments.employee_id', '=', 'payslips.employee_id')
                    ->where('employee_assignments.is_primary', '=', true)
                    ->whereDate('employee_assignments.effective_from', '<=', $asAt)
                    ->where(function ($inner) use ($asAt) {
                        $inner->whereNull('employee_assignments.effective_to')
                            ->orWhereDate('employee_assignments.effective_to', '>=', $asAt);
                    });
            })
            ->leftJoin('offices', 'offices.id', '=', 'employee_assignments.office_id')
            ->leftJoin('departments', 'departments.id', '=', 'employee_assignments.department_id')
            ->when(
                $parameters->int('run_id'),
                fn (Builder $q, int $id) => $q->where('payslips.payroll_run_id', $id),
            )
            ->when(
                $parameters->int('office_id'),
                fn (Builder $q, int $id) => $q->where('employee_assignments.office_id', $id),
            )
            ->when(
                $parameters->int('department_id'),
                fn (Builder $q, int $id) => $q->where('employee_assignments.department_id', $id),
            )
            ->when(
                ! $parameters->bool('include_blocked'),
                fn (Builder $q) => $q->where('payslips.is_blocked', false),
            )
            ->orderBy('employees.employee_code')
            ->select([
                'payslips.*',
                'employees.employee_code',
                'employees.first_name',
                'employees.last_name',
                'employees.salary_payment_method',
                'offices.name as office_name',
                'departments.name as department_name',
            ]);
    }
}
