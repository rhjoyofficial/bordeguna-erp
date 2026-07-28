<?php

namespace App\Modules\Hrm\Dashboard\Widgets;

use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Dashboard\AttendanceFreshness;
use App\Modules\Hrm\Dashboard\DashboardFilters;
use App\Modules\Hrm\Reporting\Definitions\DailyAttendanceReport;

/**
 * A tile counting one day's processed attendance, filtered to a status.
 *
 * The three RPT-04b figures — present, absent, late — differ only by which
 * status they ask for, so they share everything else: the report they are backed
 * by, the date they are about, and **the freshness state they must state**.
 *
 * ⚠ **Every widget on this base is stale-aware by construction.** Correction
 * C11b asked the dashboard to be honest about processing status rather than
 * imply a real-time correctness it does not have, and putting that in the base
 * is what stops the fourth attendance tile — whenever it is added — from being
 * the one that quietly forgets. A subclass chooses a status; it cannot choose
 * to skip the freshness stamp.
 *
 * ⚠ **The date is `asAt()`, which clamps to today**, unlike the month-shaped
 * tiles. A single-date tile must never describe tomorrow, and the default range
 * ends at the end of the current month — so this clamp is on the ordinary path,
 * not an edge case.
 */
abstract class AttendanceDayWidget extends ReportBackedWidget
{
    public function __construct(
        DailyAttendanceReport $report,
        private readonly BusinessContext $context,
    ) {
        parent::__construct($report);
    }

    /** The `status` value this tile filters the report by. */
    abstract protected function status(): string;

    protected function reportParameters(DashboardFilters $filters): array
    {
        return array_filter([
            'date' => $filters->asAt()->toDateString(),
            'status' => $this->status(),
            'office_id' => $filters->officeId(),
            'department_id' => $filters->departmentId(),
        ], fn ($value) => $value !== null);
    }

    protected function note(DashboardFilters $filters): ?string
    {
        return $this->freshness($filters)->note($filters->asAt());
    }

    protected function isStale(DashboardFilters $filters): bool
    {
        return $this->freshness($filters)->isStale();
    }

    /**
     * Resolved once per request, on the filters.
     *
     * The three tiles ask the same freshness question about the same day, and
     * without this each would repeat both of its queries.
     *
     * ⚠ **Memoised on `DashboardFilters`, never in a method-level `static`.**
     * A `static` here persists for the life of the process: under Octane or a
     * queue worker one request's freshness — including a scoped manager's
     * unprocessed count — would be handed to the next, and would never expire.
     * The filters object is built once per request, which is exactly the
     * lifetime this memo should have. It is also why freshness is deliberately
     * *not* run through the C11e cache: a figure whose entire job is to say how
     * current things are must not itself be a minute old.
     */
    private function freshness(DashboardFilters $filters): AttendanceFreshness
    {
        return $filters->remember(
            'attendance-freshness',
            fn () => AttendanceFreshness::for($filters->asAt(), $filters, $this->context),
        );
    }
}
