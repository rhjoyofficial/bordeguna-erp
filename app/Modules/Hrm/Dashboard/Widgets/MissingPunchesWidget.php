<?php

namespace App\Modules\Hrm\Dashboard\Widgets;

use App\Modules\Hrm\Dashboard\DashboardFilters;
use App\Modules\Hrm\Reporting\Definitions\AttendanceExceptionReport;

/**
 * RPT-04 #2 — Missing punches.
 *
 * The proof widget for Correction C11a's report-backed half: its figure is
 * literally `AttendanceExceptionReport` filtered to missing punches, counted.
 * Click the tile, get those exact rows.
 *
 * **Why this KPI earns a tile of its own.** POL-07 made a missing punch a
 * *blocking* exception rather than an assumed Absent or Half-day: the day sets
 * `holds_payroll` and `FinalizeAttendance` refuses it until a 2c correction
 * resolves it. So this number is not a curiosity — it is the count of days that
 * will stop a payroll run from validating, seen before somebody tries to run it
 * rather than during.
 *
 * ⚠ **The tile is about a MONTH, not the filter's whole range.** The exception
 * report takes a month, and the dashboard takes a range; rather than silently
 * summing months or silently truncating, the tile takes the month its range ends
 * in and **passes that same month to the drill-down**. The two therefore agree,
 * which is the property that matters — but the narrowing is real, so the title
 * says "this month" rather than pretending to cover the filter exactly.
 */
class MissingPunchesWidget extends ReportBackedWidget
{
    public function __construct(AttendanceExceptionReport $report)
    {
        parent::__construct($report);
    }

    public function key(): string
    {
        return 'attendance_missing_punches';
    }

    public function title(): string
    {
        return 'Missing punches this month';
    }

    public function description(): string
    {
        return 'Days with a punch missing, which hold payroll until an approved correction resolves them (POL-07).';
    }

    /**
     * ⚠ Added after the live check: this tile had no note at all, and it is
     * every bit as dependent on `ProcessAttendance` as the present/absent/late
     * ones — a missing punch is a *processed* verdict about a day, so an
     * unprocessed month simply shows nothing wrong.
     *
     * It does not use `AttendanceFreshness`, which answers about a single date;
     * a month has no one "as at" moment. So it states the dependency plainly
     * instead, which is what C11b asks for: honest about processing status
     * rather than implying a currency it does not have.
     */
    protected function note(DashboardFilters $filters): ?string
    {
        return 'Counts days that have been processed. A month not yet processed shows nothing outstanding.';
    }

    protected function reportParameters(DashboardFilters $filters): array
    {
        return array_filter([
            // ⚠ `month()`, not `asAt()` — the latter clamps a future date back to
            // today, which under an August filter in July answered about July.
            'month' => $filters->month()->format('Y-m'),
            'exception' => 'missing',
            'office_id' => $filters->officeId(),
            'department_id' => $filters->departmentId(),
        ], fn ($value) => $value !== null);
    }
}
