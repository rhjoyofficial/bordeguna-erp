<?php

namespace App\Modules\Hrm\Reporting\Definitions;

use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Enums\AttendanceConsequence;
use App\Modules\Hrm\Models\AttendancePolicy;
use App\Modules\Hrm\Models\EarnedLeaveAccrual;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\ShowCauseNotice;
use App\Modules\Hrm\Reporting\Concerns\CountsRows;
use App\Modules\Hrm\Reporting\Contracts\ReportDefinition;
use App\Modules\Hrm\Reporting\ReportColumn;
use App\Modules\Hrm\Reporting\ReportMeta;
use App\Modules\Hrm\Reporting\ReportParameter;
use App\Modules\Hrm\Reporting\ReportParameters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * The year's lateness, month by month (Joy, 24 Aug 2026: "All this late will be
 * recorded and show end of the year report").
 *
 * One row per employee per month: how many times they were late in each band,
 * how many minutes that came to, what it cost, how many show cause notices it
 * drew, and whether the month earned its leave day.
 *
 * ⚠ **Bands are read per policy, not per name.** Office calls its first band
 * `within_grace` and retail calls its `ordinary_late`, and a register keyed to
 * either name would silently report zeros for half the company. Every policy
 * covering the year contributes its own window order, so "ordinary" means
 * whichever band a policy lists first however it is spelled - and a policy that
 * grows a third band (retail did, on 24 Aug 2026) starts filling the third
 * column without this report changing.
 *
 * ⚠ **Deduction days are net of forgiveness, and the forgiven count sits beside
 * them.** Payroll skips a waived day, so a register that counted it would
 * disagree with the payslip; hiding the waiver entirely would lose the fact
 * that somebody decided. Both numbers, side by side.
 *
 * Months are CALENDAR months, not the 24-23 payroll cycle: the ladders these
 * counts come from reset per calendar month, so any other grouping would report
 * occurrences against a counter that restarted mid-row.
 */
class LatenessRegisterReport implements ReportDefinition
{
    use CountsRows;

    public function __construct(
        private readonly BusinessContext $context,
    ) {}

    public function key(): string
    {
        return 'attendance_lateness_register';
    }

    public function name(): string
    {
        return 'Yearly lateness register';
    }

    public function description(): string
    {
        return 'Every employee\'s lateness month by month for a year - the bands, the minutes, what it cost, the show cause notices it drew, and the earned leave day it did or did not win.';
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
        $years = collect(range((int) now()->year, (int) now()->year - 5))
            ->map(fn (int $year) => ['value' => (string) $year, 'label' => (string) $year])
            ->all();

        return [
            ReportParameter::select('year', 'Year', $years, required: true, default: (string) now()->year),
            ReportParameter::office(),
            // ⚠ Declared so a department manager can be scoped to their own
            // people - ReportRegistry withholds a report entirely when a
            // restricted scope has nothing to bind to.
            ReportParameter::department(),
            ReportParameter::employee(),
        ];
    }

    public function columns(?ReportParameters $parameters = null): array
    {
        return [
            ReportColumn::text('employee_code', 'Code', 12),
            ReportColumn::text('employee_name', 'Employee', 24),
            ReportColumn::text('month', 'Month', 10),
            ReportColumn::integer('ordinary_lates', 'Ordinary', 9),
            ReportColumn::integer('serious_lates', 'Serious', 9),
            ReportColumn::integer('extreme_lates', 'Beyond', 9),
            ReportColumn::duration('late_minutes', 'Late by'),
            ReportColumn::integer('deduction_days', 'Deducted', 9),
            ReportColumn::integer('forgiven_days', 'Forgiven', 9),
            ReportColumn::integer('notices', 'Notices', 9),
            ReportColumn::text('earned_leave', 'Earned leave', 22),
        ];
    }

    public function totalColumns(?ReportParameters $parameters = null): array
    {
        return ['ordinary_lates', 'serious_lates', 'extreme_lates', 'late_minutes', 'deduction_days', 'forgiven_days', 'notices'];
    }

    /**
     * Twelve rows per employee, every month of the year.
     *
     * ⚠ Clean months are printed as zeros rather than skipped. A register is
     * read to compare months, and a missing row reads as "no data" rather than
     * "nothing happened" - the same reason the monthly register prints an
     * employee who never punched. It also keeps the row count exact, which
     * `CountsRows` above depends on.
     */
    public function rows(ReportParameters $parameters): LazyCollection
    {
        $year = $this->year($parameters);

        return LazyCollection::make(function () use ($parameters, $year) {
            $ranks = $this->windowRanks($year);
            $notices = $this->noticeCounts($year);
            $accruals = $this->accrualDecisions($year);

            $current = null;
            $months = [];

            foreach ($this->query($parameters, $year)->toBase()->cursor() as $record) {
                if ($current !== null && $current['employee_id'] !== $record->employee_id) {
                    // ⚠⚠ `foreach ... yield`, never `yield from`. A `yield from`
                    // PRESERVES the inner generator's keys, so every employee
                    // would yield keys 0 to 11 and each one would overwrite the
                    // last when collected - one employee's register, wearing
                    // the name of whoever came last. Third appearance of this
                    // trap on this project.
                    foreach ($this->emit($current, $months, $notices, $accruals) as $row) {
                        yield $row;
                    }
                    $months = [];
                    $current = null;
                }

                $current ??= [
                    'employee_id' => $record->employee_id,
                    'employee_code' => $record->employee_code,
                    'employee_name' => trim($record->first_name.' '.$record->last_name),
                ];

                if ($record->attendance_date === null) {
                    continue;
                }

                $month = (int) Carbon::parse((string) $record->attendance_date)->month;
                $months[$month] ??= $this->blankMonth();

                $rank = $ranks[(string) $record->grace_window_key] ?? null;

                match ($rank) {
                    0 => $months[$month]['ordinary_lates']++,
                    1 => $months[$month]['serious_lates']++,
                    // Rank 2 and anything beyond it: a policy with four bands
                    // still has one column for "worse than serious".
                    null => null,
                    default => $months[$month]['extreme_lates']++,
                };

                $months[$month]['late_minutes'] += (int) $record->late_minutes;

                $consequence = AttendanceConsequence::tryFrom((string) $record->consequence);

                if ($consequence !== null && $consequence->deducts()) {
                    $record->waived ? $months[$month]['forgiven_days']++ : $months[$month]['deduction_days']++;
                }
            }

            if ($current !== null) {
                foreach ($this->emit($current, $months, $notices, $accruals) as $row) {
                    yield $row;
                }
            }
        });
    }

    public function estimatedRows(ReportParameters $parameters): int
    {
        // Exact, not an estimate: every employee contributes twelve rows.
        return $this->employees($parameters, $this->year($parameters))->toBase()->getCountForPagination() * 12;
    }

    public function meta(ReportParameters $parameters): ReportMeta
    {
        return new ReportMeta(
            title: 'Yearly lateness register',
            subtitle: (string) $this->year($parameters),
            lockNote: 'Bands are read from each employee\'s own attendance policy, so "ordinary" is whichever band that policy lists first. Deducted days are net of forgiveness; forgiven days are counted beside them.',
        );
    }

    private function year(ReportParameters $parameters): int
    {
        return (int) ($parameters->string('year') ?? now()->year);
    }

    /**
     * Every lateness band the year could have been judged by, ranked by how
     * late it is - 0 ordinary, 1 serious, 2 and up beyond that.
     *
     * @return array<string, int>
     */
    private function windowRanks(int $year): array
    {
        $policies = AttendancePolicy::query()
            ->whereDate('effective_from', '<=', Carbon::create($year, 12, 31)->toDateString())
            ->where(fn ($query) => $query
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', Carbon::create($year, 1, 1)->toDateString()))
            ->get();

        $ranks = [];

        foreach ($policies as $policy) {
            foreach (array_values($policy->toConfig()->graceWindows) as $rank => $window) {
                // The lowest rank a key was ever given wins. Two policies
                // sharing a key and disagreeing about its severity would be a
                // configuration mistake; reporting the milder reading of it
                // beats crashing on it.
                $ranks[$window->key] = min($rank, $ranks[$window->key] ?? $rank);
            }
        }

        return $ranks;
    }

    /**
     * @return array<string, int> keyed employee_id:month
     */
    private function noticeCounts(int $year): array
    {
        return ShowCauseNotice::query()
            ->whereYear('issued_at', $year)
            // A withdrawn notice is not a warning on record (corrections wave 3).
            ->whereNull('withdrawn_at')
            ->selectRaw('employee_id, '.$this->monthExpression('issued_at').' as m, count(*) as total')
            ->groupBy('employee_id', 'm')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->employee_id.':'.(int) $row->m => (int) $row->total])
            ->all();
    }

    /**
     * @return array<string, EarnedLeaveAccrual> keyed employee_id:month
     */
    private function accrualDecisions(int $year): array
    {
        return EarnedLeaveAccrual::query()
            ->where('year', $year)
            ->get()
            ->mapWithKeys(fn (EarnedLeaveAccrual $row) => [$row->employee_id.':'.$row->month => $row])
            ->all();
    }

    /**
     * ⚠ SQLite and MySQL disagree about month extraction, and the suite runs on
     * one while production runs on the other.
     */
    private function monthExpression(string $column): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "cast(strftime('%m', {$column}) as integer)"
            : "month({$column})";
    }

    /**
     * @return array<string, int>
     */
    private function blankMonth(): array
    {
        return [
            'ordinary_lates' => 0,
            'serious_lates' => 0,
            'extreme_lates' => 0,
            'late_minutes' => 0,
            'deduction_days' => 0,
            'forgiven_days' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $employee
     * @param  array<int, array<string, int>>  $months
     * @param  array<string, int>  $notices
     * @param  array<string, EarnedLeaveAccrual>  $accruals
     * @return iterable<int, array<string, mixed>>
     */
    private function emit(array $employee, array $months, array $notices, array $accruals): iterable
    {
        for ($month = 1; $month <= 12; $month++) {
            $counts = $months[$month] ?? null;
            $noticeCount = $notices[$employee['employee_id'].':'.$month] ?? 0;
            $accrual = $accruals[$employee['employee_id'].':'.$month] ?? null;

            yield [
                'employee_code' => $employee['employee_code'],
                'employee_name' => $employee['employee_name'],
                'month' => Carbon::create(2000, $month, 1)->format('F'),
                ...($counts ?? $this->blankMonth()),
                'notices' => $noticeCount,
                'earned_leave' => $this->accrualText($accrual),
            ];
        }
    }

    private function accrualText(?EarnedLeaveAccrual $accrual): string
    {
        if ($accrual === null) {
            return '';
        }

        return $accrual->wasWithheld()
            ? 'Withheld: '.$accrual->withheld_reason
            : rtrim(rtrim(number_format((float) $accrual->days_granted, 2), '0'), '.').' day earned';
    }

    /**
     * The employees the register covers, resolved as at the end of the year -
     * the same as-at rule the monthly register follows.
     *
     * ⚠ The assignment join is INNER and carries the business explicitly:
     * `Employee` is deliberately central and has no business scope of its own,
     * and a global scope never reaches a joined table.
     */
    private function employees(ReportParameters $parameters, int $year): Builder
    {
        $asAt = Carbon::create($year, 12, 31)->toDateString();
        $businessId = $this->context->id();

        return Employee::query()
            ->join('employee_assignments', function ($join) use ($asAt, $businessId) {
                $join->on('employee_assignments.employee_id', '=', 'employees.id')
                    ->where('employee_assignments.business_id', '=', $businessId)
                    ->where('employee_assignments.is_primary', '=', true)
                    ->whereDate('employee_assignments.effective_from', '<=', $asAt)
                    ->where(function ($inner) use ($asAt) {
                        $inner->whereNull('employee_assignments.effective_to')
                            ->orWhereDate('employee_assignments.effective_to', '>=', $asAt);
                    });
            })
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
                fn (Builder $q, int $id) => $q->where('employees.id', $id),
            );
    }

    private function query(ReportParameters $parameters, int $year): Builder
    {
        $start = Carbon::create($year, 1, 1)->toDateString();
        $end = Carbon::create($year, 12, 31)->toDateString();

        return $this->employees($parameters, $year)
            ->leftJoin('daily_attendances', function ($join) use ($start, $end) {
                $join->on('daily_attendances.employee_id', '=', 'employees.id')
                    ->whereNotNull('daily_attendances.grace_window_key')
                    ->whereDate('daily_attendances.attendance_date', '>=', $start)
                    ->whereDate('daily_attendances.attendance_date', '<=', $end);
            })
            // The forgiveness that payroll honours, joined so the register can
            // report the same money payroll will.
            ->leftJoin('attendance_waivers', function ($join) {
                $join->on('attendance_waivers.employee_id', '=', 'daily_attendances.employee_id')
                    ->on('attendance_waivers.attendance_date', '=', 'daily_attendances.attendance_date')
                    ->whereNull('attendance_waivers.revoked_at');
            })
            // ⚠ Employee first: the assembler holds one employee's year and
            // emits when the employee changes, so this ordering is what makes
            // it correct rather than what makes it tidy.
            ->orderBy('employees.employee_code')
            ->orderBy('daily_attendances.attendance_date')
            ->select([
                'employees.id as employee_id',
                'employees.employee_code',
                'employees.first_name',
                'employees.last_name',
                'daily_attendances.attendance_date',
                'daily_attendances.grace_window_key',
                'daily_attendances.late_minutes',
                'daily_attendances.consequence',
                DB::raw('attendance_waivers.id as waived'),
            ]);
    }
}
