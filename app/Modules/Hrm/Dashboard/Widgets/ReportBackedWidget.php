<?php

namespace App\Modules\Hrm\Dashboard\Widgets;

use App\Modules\Hrm\Dashboard\Contracts\DashboardWidget;
use App\Modules\Hrm\Dashboard\DashboardFilters;
use App\Modules\Hrm\Dashboard\WidgetValue;
use App\Modules\Hrm\Reporting\Contracts\ReportDefinition;
use App\Modules\Hrm\Reporting\ReportColumnType;
use App\Modules\Hrm\Reporting\ReportParameters;

/**
 * A tile whose figure **is** the row count of the report that explains it.
 *
 * This is Correction C11a's answer to Joy's "reuse the reporting query layer
 * instead of duplicating report logic", and the reuse it achieves is a guarantee
 * rather than a saving: the tile and the report are the *same query*, so
 * clicking through from a tile reading 7 cannot produce a report with 6 rows.
 * A test asserts it for every widget built on this base.
 *
 * ⚠ **It counts through `countRows()`, never `estimatedRows()`.** The estimate
 * is contractually allowed to be approximate — two of the report definitions
 * return a deliberate 0 from it — and an approximation displayed on a dashboard
 * is simply a wrong number somebody makes decisions from. That distinction is
 * the reason `countRows()` was added to the framework at all.
 *
 * ⚠ **The filters go to the report untranslated.** `DashboardFilters` wraps
 * `ReportParameters`, so the office and department the dashboard was filtered by
 * are the ones the report is filtered by — including the `department_id` that
 * `ReportScope` already overwrote for a restricted manager. There is no second
 * place for the access rule to be applied, and therefore no second place for it
 * to be forgotten.
 */
abstract class ReportBackedWidget implements DashboardWidget
{
    public function __construct(
        protected readonly ReportDefinition $report,
    ) {}

    /**
     * The report parameters this tile's question implies, on top of the
     * dashboard's own filters.
     *
     * @return array<string, mixed>
     */
    abstract protected function reportParameters(DashboardFilters $filters): array;

    public function group(): string
    {
        // Taken from the report rather than restated, so a tile can never end up
        // in the Attendance group while reading payroll data — which would let
        // it past the C11d access check the group decides.
        return $this->report->group();
    }

    public function permission(): string
    {
        return $this->report->permission();
    }

    public function cacheSeconds(): int
    {
        return (int) config('hrm.dashboard.cache_seconds', 60);
    }

    public function value(DashboardFilters $filters): WidgetValue
    {
        $parameters = $this->reportParameters($filters);

        return new WidgetValue(
            value: $this->report->countRows(new ReportParameters($parameters)),
            type: ReportColumnType::Integer,
            note: $this->note($filters),
            stale: $this->isStale($filters),
            drilldownReport: $this->report->key(),
            drilldownParameters: $parameters,
        );
    }

    /**
     * Anything the figure needs said about it — freshness under C11b, or a
     * provisionality warning under POL-06/07. Most widgets have nothing to add.
     */
    protected function note(DashboardFilters $filters): ?string
    {
        return null;
    }

    /**
     * Whether this figure describes a state the system has not caught up with.
     * Default false: a widget over settled data is not stale, and claiming
     * otherwise would make the marker meaningless on the tiles that need it.
     */
    protected function isStale(DashboardFilters $filters): bool
    {
        return false;
    }
}
