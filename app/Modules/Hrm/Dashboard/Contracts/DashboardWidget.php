<?php

namespace App\Modules\Hrm\Dashboard\Contracts;

use App\Modules\Hrm\Dashboard\DashboardFilters;
use App\Modules\Hrm\Dashboard\WidgetValue;

/**
 * One KPI tile — the thin part of RPT-04.
 *
 * A widget declares what it is called, who may see it, how stale it may be, and
 * returns a figure. **It renders nothing.** That separation is the whole point:
 * the existing ERP dashboard (`App\Http\Controllers\DashboardController`) is one
 * controller holding six private query methods with permission checks inlined at
 * the top, and there is no way to add a seventh tile without editing it. Here a
 * tile is a class plus a line in `HrmServiceProvider::WIDGETS` — the promise
 * `REPORTS` already makes for reports.
 *
 * **Three rules a widget must not break.**
 *
 * 1. **It reads. It recomputes nothing.** The rule W3 inherited from
 *    `AccountingExport` applies unchanged: a tile that re-derives a figure has
 *    invented a second source of truth. Attendance figures come from
 *    `DailyAttendance` — the engine's own output — and payroll figures from
 *    finished payslips.
 * 2. **It aggregates in SQL.** A dashboard is the first page everybody opens.
 *    Loading rows to count them in PHP is the shape that makes it the slowest.
 * 3. **It never applies its own access rule.** `ReportScope` (C10d) decides what
 *    this viewer may see, and `DashboardRegistry` applies it. A widget that
 *    filtered by department itself would be one grant away from being wrong.
 */
interface DashboardWidget
{
    /** Stable identifier, used in the payload and as part of the cache key. */
    public function key(): string;

    /** What the tile is called. */
    public function title(): string;

    /** One line stating what the figure means — and, where it matters, what it does not. */
    public function description(): string;

    /** The grouping on the page: 'Attendance' or 'Payroll'. */
    public function group(): string;

    /**
     * The permission required to see it (Correction C10d, extended to tiles by
     * C11d).
     *
     * ⚠ **C5 / ACL-02 is enforced on top of this and is not negotiable: a
     * Department Manager sees no payroll or salary tile at all.** A tile is the
     * same figure as the report, delivered faster and without the audit trail a
     * report run leaves, so the rule is if anything more important here.
     */
    public function permission(): string;

    /**
     * How many seconds this figure may be stale (Correction C11e).
     *
     * Declared by the widget rather than set globally, because the right answer
     * differs per tile and the widget is where the data is understood: a payroll
     * total is fine a minute old, a pending-approvals count is what somebody is
     * about to act on and returns 0.
     */
    public function cacheSeconds(): int;

    public function value(DashboardFilters $filters): WidgetValue;
}
