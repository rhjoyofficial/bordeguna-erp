<?php

namespace App\Modules\Hrm\Reporting\Definitions;

use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\AttendanceWaiver;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Reporting\Concerns\CountsRows;
use App\Modules\Hrm\Reporting\Contracts\ReportDefinition;
use App\Modules\Hrm\Reporting\ReportColumn;
use App\Modules\Hrm\Reporting\ReportMeta;
use App\Modules\Hrm\Reporting\ReportParameter;
use App\Modules\Hrm\Reporting\ReportParameters;
use App\Modules\Hrm\Support\ForgivenessValue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * What the company forgave, month by month, person by person (Joy, 25 Aug 2026:
 * "so company can track how much company forgive, must need track").
 *
 * Forgiving a deduction is the one act in this system where a manager gives
 * away money with a click and nothing adds it up afterwards. The lateness
 * register already counts forgiven DAYS, and only those caused by lateness; it
 * says nothing about what they were worth, nothing about a forgiven ABSENCE,
 * and nothing about who did the forgiving.
 *
 * C-WVR-1  BOTH days and money. "How much" is a money question, but a forgiven
 *          day is naturally a day, and one figure without the other invites the
 *          wrong comparison between a junior's day and an owner's.
 * C-WVR-2  The money is NOT re-derived. `ForgivenessValue` asks PayrollConfig
 *          the same question payroll asks, at the salary in force on the day
 *          forgiven.
 * C-WVR-3  ONLY ACTIVE WAIVERS CARRY MONEY. A revoked one forgave nothing in
 *          the end, so it is counted in its own column and never added to the
 *          amount. An undo that vanished from the register would be a way to
 *          forgive money quietly.
 * C-WVR-4  A waiver belongs to the PAYROLL month containing its date, because
 *          that is the payroll the money would have come out of. Under the
 *          24-to-23 cycle the 26th of August is September's business.
 * C-WVR-5  One row per employee per month, all twelve months, so a clean month
 *          reads as nothing happened rather than as missing data.
 * C-WVR-6  Who forgave is on the row. "How much did we forgive" without "who"
 *          is half a question, and this is a governance report.
 * C-WVR-7  Group Payroll, permission hrm.payroll.manage: it states amounts per
 *          person, which is pay data whatever else it is.
 */
class ForgivenessRegisterReport implements ReportDefinition
{
    use CountsRows;

    public function __construct(
        private readonly BusinessContext $context,
    ) {}

    public function key(): string
    {
        return 'payroll_forgiveness_register';
    }

    public function name(): string
    {
        return 'Forgiveness register';
    }

    public function description(): string
    {
        return 'Every deduction a manager forgave, month by month for a year: how many days, what those days were worth, who forgave them, and how many were later undone.';
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
        $years = collect(range((int) now()->year, (int) now()->year - 5))
            ->map(fn (int $year) => ['value' => (string) $year, 'label' => (string) $year])
            ->all();

        return [
            ReportParameter::select('year', 'Year', $years, required: true, default: (string) now()->year),
            ReportParameter::office(),
            ReportParameter::department(),
            ReportParameter::employee(),
        ];
    }

    public function columns(?ReportParameters $parameters = null): array
    {
        return [
            ReportColumn::text('employee_code', 'Code', 12),
            ReportColumn::text('employee_name', 'Employee', 24),
            ReportColumn::text('month', 'Payroll month', 14),
            ReportColumn::integer('forgiven_days', 'Days', 8),
            ReportColumn::money('forgiven_amount', 'Forgiven'),
            ReportColumn::integer('revoked_days', 'Undone', 8),
            ReportColumn::text('forgiven_by', 'Forgiven by', 22),
            ReportColumn::text('reasons', 'Reasons', 40),
        ];
    }

    public function totalColumns(?ReportParameters $parameters = null): array
    {
        // ⚠ The money and the days total; "undone" totals too, because a year
        // with many undos is itself worth seeing. Nothing totals across
        // columns, and there is no "net" figure: forgiven and undone are two
        // counts of two different acts, not a sum.
        return ['forgiven_days', 'forgiven_amount', 'revoked_days'];
    }

    /**
     * Twelve rows per employee, every month of the year.
     *
     * ⚠ Built per employee and yielded with a plain foreach. `yield from` has
     * leaked its keys into the outer generator three times on this project,
     * silently overwriting one employee's rows with the next one's.
     */
    public function rows(ReportParameters $parameters): LazyCollection
    {
        $year = (int) ($parameters->get('year') ?? now()->year);

        return LazyCollection::make(function () use ($parameters, $year) {
            foreach ($this->employees($parameters) as $employee) {
                $months = $this->emptyYear();

                foreach ($this->waiversFor($employee, $year) as $waiver) {
                    $value = ForgivenessValue::for($waiver);

                    if ($value['year'] !== $year) {
                        continue;
                    }

                    // ⚠⚠ WRITTEN THROUGH THE INDEX, NEVER THROUGH A REFERENCE.
                    // The first draft took `$bucket = &$months[$m]`, and the
                    // `continue` on a revoked waiver skipped the `unset`, so the
                    // reference survived the loop - and the NEXT `foreach
                    // ($months as $month => $bucket)` then wrote every month in
                    // turn into the month that reference still pointed at. April
                    // reported nothing at all while its two waivers sat in the
                    // table. PHP's oldest trap, caught by the one test that
                    // happened to revoke something.
                    $key = $value['month'];

                    if ($waiver->revoked_at !== null) {
                        $months[$key]['revoked_days']++;

                        continue;
                    }

                    $months[$key]['forgiven_days']++;
                    $months[$key]['forgiven_amount'] = round($months[$key]['forgiven_amount'] + $value['amount'], 2);

                    $by = $waiver->waiver?->name;

                    if ($by !== null && ! in_array($by, $months[$key]['by'], true)) {
                        $months[$key]['by'][] = $by;
                    }

                    if (filled($waiver->reason)) {
                        $months[$key]['reasons'][] = trim((string) $waiver->reason);
                    }
                }

                foreach ($months as $month => $bucket) {
                    yield [
                        'employee_code' => $employee->employee_code,
                        'employee_name' => $employee->fullName(),
                        'month' => Carbon::create($year, $month, 1)->format('M Y'),
                        'forgiven_days' => $bucket['forgiven_days'],
                        'forgiven_amount' => $bucket['forgiven_amount'],
                        'revoked_days' => $bucket['revoked_days'],
                        'forgiven_by' => implode(', ', $bucket['by']),
                        'reasons' => implode(' | ', $bucket['reasons']),
                    ];
                }
            }
        });
    }

    public function estimatedRows(ReportParameters $parameters): int
    {
        return $this->employees($parameters)->count() * 12;
    }

    public function meta(ReportParameters $parameters): ReportMeta
    {
        $year = (int) ($parameters->get('year') ?? now()->year);

        return new ReportMeta(
            title: 'Forgiveness register',
            subtitle: (string) $year,
            lockNote: 'A day is worth what payroll would have deducted for it, at the salary in force on that day. '
                .'Months follow the payroll cycle, so a day forgiven on the 26th belongs to the next month. '
                .'Undone waivers are counted separately and are not part of the amount.',
        );
    }

    /**
     * @return Collection<int, Employee>
     */
    private function employees(ReportParameters $parameters)
    {
        $businessId = $this->context->id();

        return Employee::query()
            // Employee carries no business column; the assignment is what makes
            // somebody ours, the same join every other HRM report uses.
            ->whereHas('assignments', function ($query) use ($businessId, $parameters) {
                $query->where('business_id', $businessId)->where('is_primary', true);

                if ($office = $parameters->int('office_id')) {
                    $query->where('office_id', $office);
                }

                if ($department = $parameters->int('department_id')) {
                    $query->where('department_id', $department);
                }
            })
            ->when($parameters->int('employee_id'), fn ($query, int $id) => $query->where('id', $id))
            ->orderBy('employee_code')
            ->get();
    }

    /**
     * @return Collection<int, AttendanceWaiver>
     */
    private function waiversFor(Employee $employee, int $year)
    {
        // ⚠ A window wider than the calendar year on both sides: with the
        // 24-to-23 cycle, late December belongs to the next January and late
        // December of the year before belongs to this January. Querying the
        // plain year would lose a month at each end.
        return AttendanceWaiver::query()
            ->with('waiver:id,name')
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', '>=', Carbon::create($year - 1, 12, 1)->toDateString())
            ->whereDate('attendance_date', '<=', Carbon::create($year, 12, 31)->toDateString())
            ->orderBy('attendance_date')
            ->get();
    }

    /**
     * @return array<int, array{forgiven_days:int, forgiven_amount:float, revoked_days:int, by:list<string>, reasons:list<string>}>
     */
    private function emptyYear(): array
    {
        $months = [];

        foreach (range(1, 12) as $month) {
            $months[$month] = [
                'forgiven_days' => 0,
                'forgiven_amount' => 0.0,
                'revoked_days' => 0,
                'by' => [],
                'reasons' => [],
            ];
        }

        return $months;
    }
}
