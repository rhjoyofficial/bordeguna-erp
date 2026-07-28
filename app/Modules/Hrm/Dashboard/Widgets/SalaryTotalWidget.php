<?php

namespace App\Modules\Hrm\Dashboard\Widgets;

use App\Modules\Hrm\Dashboard\Concerns\ResolvesPayrollRun;
use App\Modules\Hrm\Dashboard\Concerns\VisibleByPermission;
use App\Modules\Hrm\Dashboard\Contracts\DashboardWidget;
use App\Modules\Hrm\Dashboard\DashboardFilters;
use App\Modules\Hrm\Dashboard\WidgetValue;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Reporting\ReportColumnType;

/**
 * RPT-04 #6 — Salary total and variance.
 *
 * Correction C11c decided what a one-figure variance means, because C10c had
 * only decided it for the *report*: **the latest run's net, with the delta
 * against the previous period's run**, both periods named on the tile.
 *
 * ⚠⚠ **C10c's headline rule is reapplied here, and this is the tile where
 * breaking it would be most visible: no percentage is shown when there is
 * nothing to be a percentage of.** A first payroll run has no predecessor, and
 * `(current − 0) / 0` rendered as "+100%" is a real-looking number describing a
 * non-event — sitting at the top of the page every month until a second run
 * exists. The delta is shown; the percentage is blank; the reason is stated.
 *
 * ⚠ **It reads the run's own stored totals and recomputes nothing.** `PayrollRun`
 * carries `total_net` from the moment `CalculatePayroll` persisted it inside one
 * transaction. Summing payslips here would be a second opinion about what a
 * payroll cost, and the two would eventually differ — which is the failure the
 * whole reporting layer is arranged to prevent.
 *
 * ⚠ **A run is a version (WFL-06)**, so "the latest run" is the highest
 * non-cancelled version of its period, exactly as `PayrollStatusWidget` resolves
 * it. Comparing against a cancelled v1 would report a difference that was never
 * paid to anybody.
 *
 * **Office and department filters do not apply.** A payroll run's total is a
 * whole-business figure; narrowing it would need per-department sums, which is
 * the cost allocation report's job and says so on the tile.
 */
class SalaryTotalWidget implements DashboardWidget
{
    use ResolvesPayrollRun, VisibleByPermission;

    public function key(): string
    {
        return 'payroll_salary_total';
    }

    public function title(): string
    {
        return 'Payroll total';
    }

    public function description(): string
    {
        return 'Net payable on the latest run for the selected period, against the previous period. A whole-business figure — use the cost allocation report to break it down.';
    }

    public function group(): string
    {
        return 'Payroll';
    }

    public function permission(): string
    {
        return 'hrm.payroll.manage';
    }

    public function cacheSeconds(): int
    {
        return (int) config('hrm.dashboard.cache_seconds', 60);
    }

    public function value(DashboardFilters $filters): WidgetValue
    {
        $month = $filters->month();
        $current = $this->runForPeriod($month->year, $month->month);

        if ($current === null) {
            return new WidgetValue(
                value: 0,
                type: ReportColumnType::Money,
                note: 'No payroll run exists for '.$month->format('F Y').' yet.',
            );
        }

        $previousMonth = $month->copy()->subMonth();
        $previous = $this->runForPeriod($previousMonth->year, $previousMonth->month);

        $net = round((float) $current->total_net, 2);

        return new WidgetValue(
            value: $net,
            type: ReportColumnType::Money,
            delta: $previous === null ? null : round($net - (float) $previous->total_net, 2),
            deltaPercent: $this->percent($previous, $net),
            deltaLabel: $previous === null ? null : 'vs '.(string) $previous->period?->label(),
            note: $this->note($current, $previous, $previousMonth->format('F Y')),
            drilldownReport: $previous === null ? 'payroll_salary_sheet' : 'payroll_variance',
            drilldownParameters: $previous === null
                ? ['run_id' => $current->id]
                : ['run_id' => $current->id, 'compare_run_id' => $previous->id],
        );
    }

    /**
     * ⚠ C10c / C11c, in one function. A percentage needs something to be a
     * percentage *of*. With no previous run — or a previous run that somehow
     * paid nothing — there is no such thing, and blank is the honest rendering.
     * The tempting alternatives are both worse: 100% is arithmetically
     * indefensible and reads as a real movement, and 0% reads as "no change" on
     * a month where a whole payroll appeared.
     */
    private function percent(?PayrollRun $previous, float $net): ?string
    {
        if ($previous === null) {
            return null;
        }

        $before = round((float) $previous->total_net, 2);

        if (abs($before) < 0.005) {
            return null;
        }

        return sprintf('%+.1f%%', ($net - $before) / abs($before) * 100);
    }

    private function note(PayrollRun $current, ?PayrollRun $previous, string $previousLabel): string
    {
        $note = sprintf('%s — run v%d (%s)', (string) $current->period?->label(), $current->version, $current->status->label());

        if ($previous === null) {
            // Stated rather than left as an unexplained blank where a percentage
            // would be. "No comparison" is information; an empty space is not.
            return $note.'. No run for '.$previousLabel.' to compare against.';
        }

        if (! $current->status->isFinal()) {
            $note .= ' — figures can still change';
        }

        return $note.'.';
    }

}
