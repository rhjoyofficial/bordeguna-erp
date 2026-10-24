<?php

namespace App\Modules\Hrm\Reporting\Definitions;

use App\Modules\Hrm\Models\OvertimeAssignment;
use App\Modules\Hrm\Reporting\Concerns\CountsRows;
use App\Modules\Hrm\Reporting\Contracts\ReportDefinition;
use App\Modules\Hrm\Reporting\ReportColumn;
use App\Modules\Hrm\Reporting\ReportMeta;
use App\Modules\Hrm\Reporting\ReportParameter;
use App\Modules\Hrm\Reporting\ReportParameters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;

/**
 * RPT-01 #5 — Overtime.
 *
 * Every authorised stint of overtime in the month, and — the part that makes it
 * worth printing — **what each one turned into**. ADJ-01 through Correction C8d
 * left three ways for overtime to end up unpaid, and all three are invisible on
 * an hours-only list:
 *
 *  1. **It was holiday work (ADJ-04 / C8b).** Compensated in leave, not money,
 *     capped at a day per date. The hours are real and the payment is zero, and
 *     without the compensatory-days column beside them that reads as an error.
 *  2. **The employee was not overtime-eligible (C8d).** Eligibility sits on the
 *     effective-dated salary structure and is checked **as at the date worked**,
 *     so a promotion cannot make last March's hours claimable. This report
 *     resolves it the same way, by joining the structure in force on the work
 *     date — reading today's flag would silently rewrite the answer for every
 *     historical row.
 *  3. **It was never approved.** `applied_at` is null and nothing reached
 *     payroll at all.
 *
 * ⚠ **Hours are authorised, never derived from punches.** ADJ-01 ticked "only
 * approved overtime assignment", so `overtime_assignments` is the whole truth
 * about how much overtime there was. The attendance join below carries the day's
 * worked minutes for context only, and nothing on this report is computed from
 * it — deriving hours here would be the second opinion the framework's first
 * rule exists to prevent.
 */
class OvertimeReport implements ReportDefinition
{
    use CountsRows;

    public function key(): string
    {
        return 'attendance_overtime';
    }

    public function name(): string
    {
        return 'Overtime';
    }

    public function description(): string
    {
        return 'Authorised overtime for the month, with whether each stint was payable, compensated in leave, or blocked by eligibility on the date it was worked.';
    }

    public function group(): string
    {
        return 'Attendance';
    }

    public function permission(): string
    {
        return 'hrm.attendance.manage';
    }

    public function parameters(): array
    {
        return [
            ReportParameter::month(),
            ReportParameter::office(),
            ReportParameter::department(),
            ReportParameter::employee(),
            ReportParameter::boolean(
                'approved_only',
                'Approved only',
                default: true,
                help: 'Unapproved overtime never reaches payroll. Include it to see what was requested but not authorised.',
            ),
        ];
    }

    public function columns(?ReportParameters $parameters = null): array
    {
        return [
            ReportColumn::date('work_date', 'Date'),
            ReportColumn::text('employee_code', 'Code', 12),
            ReportColumn::text('employee_name', 'Employee', 24),
            ReportColumn::text('department', 'Department', 18),
            ReportColumn::decimal('hours', 'Hours', 9),
            ReportColumn::boolean('is_holiday_work', 'Holiday work'),
            ReportColumn::decimal('compensatory_days', 'Comp. days', 11),
            ReportColumn::boolean('is_approved', 'Approved'),
            // The one column somebody actually reads this report for.
            ReportColumn::text('outcome', 'Outcome', 26),
            ReportColumn::duration('worked_minutes', 'Worked that day'),
            ReportColumn::text('reason', 'Reason', 34),
        ];
    }

    public function totalColumns(?ReportParameters $parameters = null): array
    {
        return ['hours', 'compensatory_days'];
    }

    public function rows(ReportParameters $parameters): LazyCollection
    {
        return LazyCollection::make(function () use ($parameters) {
            foreach ($this->query($parameters)->cursor() as $overtime) {
                yield [
                    'work_date' => $overtime->work_date,
                    'employee_code' => $overtime->employee_code,
                    'employee_name' => trim($overtime->first_name.' '.$overtime->last_name),
                    'department' => $overtime->department_name,
                    'hours' => $overtime->hours,
                    'is_holiday_work' => $overtime->is_holiday_work,
                    'compensatory_days' => $overtime->compensatory_days,
                    'is_approved' => $overtime->applied_at !== null,
                    'outcome' => $this->outcome($overtime),
                    'worked_minutes' => $overtime->worked_minutes,
                    'reason' => $overtime->reason,
                ];
            }
        });
    }

    /**
     * Why this stint was paid, credited or neither — in the order the rules
     * actually apply, which is not the order they were written in.
     *
     * Approval is checked first because an unapproved stint never reaches any of
     * the others; holiday work before eligibility because C8b's leave credit
     * does not depend on being overtime-eligible for *pay*.
     */
    private function outcome(object $overtime): string
    {
        if ($overtime->applied_at === null) {
            return 'Not approved — unpaid';
        }

        if ($overtime->is_holiday_work) {
            return 'Compensatory leave (ADJ-04)';
        }

        if (! $overtime->overtime_eligible) {
            return 'Not eligible on that date (ADJ-01)';
        }

        return 'Payable';
    }

    public function estimatedRows(ReportParameters $parameters): int
    {
        return $this->query($parameters)->toBase()->getCountForPagination();
    }

    public function meta(ReportParameters $parameters): ReportMeta
    {
        return new ReportMeta(
            title: 'Overtime',
            subtitle: $parameters->month()?->format('F Y'),
            // Overtime assignments carry no attendance period lock of their own —
            // they are authorisations, not processed days — so the concept does
            // not apply and the meta says nothing rather than guessing.
            periodLocked: null,
        );
    }

    private function query(ReportParameters $parameters): Builder
    {
        $month = $parameters->monthStart();
        $asAt = $parameters->monthEnd()->toDateString();

        return OvertimeAssignment::query()
            ->join('employees', 'employees.id', '=', 'overtime_assignments.employee_id')
            ->leftJoin('employee_assignments', function ($join) use ($asAt) {
                $join->on('employee_assignments.employee_id', '=', 'overtime_assignments.employee_id')
                    ->where('employee_assignments.is_primary', '=', true)
                    ->whereDate('employee_assignments.effective_from', '<=', $asAt)
                    ->where(function ($inner) use ($asAt) {
                        $inner->whereNull('employee_assignments.effective_to')
                            ->orWhereDate('employee_assignments.effective_to', '>=', $asAt);
                    });
            })
            ->leftJoin('departments', 'departments.id', '=', 'employee_assignments.department_id')
            // ⚠ C8d. The structure in force **on the work date**, not today's —
            // joined per row on that row's own date, which is the only way a
            // report covering a month of promotions stays right.
            // ⚠ DATE() on both sides — see the note in OrganisationSummaryReport.
            // A `date`-cast column and a query-builder-written one differ by a
            // ` 00:00:00` suffix, and comparing two columns as strings makes an
            // eligible employee look ineligible on exactly the boundary date.
            ->leftJoin('salary_structures', function ($join) {
                $join->on('salary_structures.employee_id', '=', 'overtime_assignments.employee_id')
                    ->whereRaw('date(salary_structures.effective_from) <= date(overtime_assignments.work_date)')
                    ->where(function ($inner) {
                        $inner->whereNull('salary_structures.effective_to')
                            ->orWhereRaw('date(salary_structures.effective_to) >= date(overtime_assignments.work_date)');
                    });
            })
            ->leftJoin('daily_attendances', function ($join) {
                $join->on('daily_attendances.employee_id', '=', 'overtime_assignments.employee_id')
                    ->whereRaw('date(daily_attendances.attendance_date) = date(overtime_assignments.work_date)');
            })
            // ⭐ B24 (25 Oct 2026): a cancelled overtime was never worked for pay.
            ->whereNull('overtime_assignments.cancelled_at')
            ->whereDate('overtime_assignments.work_date', '>=', $month->toDateString())
            ->whereDate('overtime_assignments.work_date', '<=', $asAt)
            ->when(
                $parameters->bool('approved_only'),
                fn (Builder $q) => $q->whereNotNull('overtime_assignments.applied_at'),
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
                $parameters->int('employee_id'),
                fn (Builder $q, int $id) => $q->where('overtime_assignments.employee_id', $id),
            )
            ->orderBy('overtime_assignments.work_date')
            ->orderBy('employees.employee_code')
            ->select([
                'overtime_assignments.*',
                'employees.employee_code',
                'employees.first_name',
                'employees.last_name',
                'departments.name as department_name',
                'salary_structures.overtime_eligible',
                'daily_attendances.worked_minutes',
            ]);
    }
}
