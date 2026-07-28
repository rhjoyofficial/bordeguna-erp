<?php

namespace App\Modules\Hrm\Dashboard\Widgets;

use App\Modules\Hrm\Dashboard\Contracts\DashboardWidget;
use App\Modules\Hrm\Dashboard\DashboardFilters;
use App\Modules\Hrm\Dashboard\WidgetValue;
use App\Modules\Hrm\Enums\PayrollStatus;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Reporting\ReportColumnType;

/**
 * RPT-04 #5 — Current payroll status.
 *
 * The proof widget for Correction C11a's *other* half: a tile that is not a row
 * count and should not be forced into the shape of one. "Where is this month's
 * payroll up to" is a status, and `PayrollStatus` already owns the answer.
 *
 * ⚠ **A run is a version (WFL-06).** A reopened payroll produces v2 and cancels
 * v1, so "the current run" is the **highest version that has not been
 * cancelled** — not simply the newest row, which would show a cancelled v1 as
 * the state of the month. The version is on the tile for the same reason the
 * salary sheet carries it: two contradictory answers about July must not look
 * like one answer.
 *
 * ⚠ **It reads the run's own stored status and recomputes nothing.** Deriving
 * "is this approved" from the approval request here would be a second opinion
 * about a lifecycle `TransitionPayrollRun` already owns.
 *
 * **Office and department filters do not apply**, and the tile says so rather
 * than silently ignoring them: a payroll run is a whole-business event, and
 * pretending it could be filtered to a department would invite the reading that
 * each department has its own payroll state.
 */
class PayrollStatusWidget implements DashboardWidget
{
    public function key(): string
    {
        return 'payroll_current_status';
    }

    public function title(): string
    {
        return 'Current payroll';
    }

    public function description(): string
    {
        return 'Where the most recent payroll run for the selected period has reached. A payroll run covers the whole business, so office and department filters do not narrow it.';
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
        $run = $this->currentRun($filters);

        if ($run === null) {
            return new WidgetValue(
                value: 'Not started',
                type: ReportColumnType::Text,
                // A month with no run at all is a real and common state, and it
                // is different from a run sitting in Draft. Collapsing the two
                // would hide the fact that nobody has begun.
                note: 'No payroll run exists for '.$filters->month()->format('F Y').' yet.',
            );
        }

        return new WidgetValue(
            value: $run->status->label(),
            type: ReportColumnType::Text,
            note: $this->note($run),
            drilldownReport: 'payroll_salary_sheet',
            drilldownParameters: ['run_id' => $run->id],
        );
    }

    private function note(PayrollRun $run): string
    {
        $note = sprintf('%s — run v%d', (string) $run->period?->label(), $run->version);

        if ($run->blocked_count > 0) {
            // PAY-09/PAY-11 blocked payslips are the reason a run stalls, and
            // the count is the first thing somebody needs in order to unstick
            // it. Stating only the status would leave them to go looking.
            $note .= sprintf(', %d payslip%s blocked', $run->blocked_count, $run->blocked_count === 1 ? '' : 's');
        }

        if (! $run->status->isFinal()) {
            $note .= ' — figures can still change';
        }

        return $note;
    }

    /**
     * The live run for the period the dashboard is looking at.
     *
     * ⚠ Cancelled runs are excluded, then the highest version wins. WFL-06's
     * reopen path leaves the superseded version behind as Cancelled on purpose,
     * and ordering by id alone would eventually surface it.
     */
    private function currentRun(DashboardFilters $filters): ?PayrollRun
    {
        // ⚠ `month()`, not `asAt()`. A payroll run belongs to a period, and
        // asking about August must not silently answer about July because
        // August has not finished yet.
        $asAt = $filters->month();

        return PayrollRun::query()
            ->with('period')
            ->whereHas('period', fn ($q) => $q
                ->where('year', $asAt->year)
                ->where('month', $asAt->month))
            ->where('status', '!=', PayrollStatus::Cancelled->value)
            ->orderByDesc('version')
            ->first();
    }
}
