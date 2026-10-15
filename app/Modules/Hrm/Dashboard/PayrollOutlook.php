<?php

namespace App\Modules\Hrm\Dashboard;

use App\Modules\Hrm\Dashboard\Concerns\ResolvesPayrollRun;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Reporting\ReportColumnType;
use App\Modules\Hrm\Reporting\ReportValue;
use App\Modules\Hrm\Support\PayrollCycle;
use Illuminate\Support\Carbon;

/**
 * The payroll card of the HR dashboard (redesign, 15 Oct 2026): the latest
 * run that exists, and the cycle whose payroll is still to come.
 *
 * ⚠ WHY THIS IS NOT JUST THE TWO PAYROLL WIDGETS. `PayrollStatusWidget` and
 * `SalaryTotalWidget` answer about the SELECTED month, and the default range
 * is the current pay cycle, whose run does not exist until the cycle ends.
 * So for three weeks of every month the two tiles read "Not started" and
 * "0.00", which is true and useless. Joy's approved design shows the last
 * run that was actually made (its net, its movement, where it stands) and,
 * under it, the cycle that is still open with a Calculate link. This class
 * gives the card those two facts. The selected month still wins when it has
 * a run, so the card and the tiles never disagree about a month that exists.
 *
 * ⚠ The version rule is `ResolvesPayrollRun`, shared with both widgets: the
 * latest run is the highest NON-CANCELLED version, never the newest row.
 *
 * ⚠ Gated by the CALLER exactly as the payroll widgets are: the controller
 * builds this only when the registry let the viewer see `payroll_current_status`.
 * A Department Manager gets `null`, not a smaller card (ACL-02 / C11d).
 */
final class PayrollOutlook
{
    use ResolvesPayrollRun;

    /**
     * @return array{latest: array<string, mixed>|null, next: array<string, mixed>}
     */
    public static function for(DashboardFilters $filters): array
    {
        return (new self)->build($filters);
    }

    /**
     * @return array{latest: array<string, mixed>|null, next: array<string, mixed>}
     */
    private function build(DashboardFilters $filters): array
    {
        $month = $filters->month();

        $run = $this->runForPeriod($month->year, $month->month)
            ?? $this->latestRunBefore($month);

        return [
            'latest' => $run === null ? null : $this->describe($run),
            'next' => $this->next(),
        ];
    }

    /**
     * The most recent live run of any period up to the selected one.
     *
     * Periods are few (one a month), so walking them newest-first and asking
     * the shared version rule about each is cheaper to get right than a join
     * that would have to restate that rule in SQL.
     */
    private function latestRunBefore(Carbon $month): ?PayrollRun
    {
        $periods = PayrollPeriod::query()
            ->whereRaw('(year * 100 + month) < ?', [$month->year * 100 + $month->month])
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->get(['year', 'month']);

        foreach ($periods as $period) {
            $run = $this->runForPeriod($period->year, $period->month);

            if ($run !== null) {
                return $run;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(PayrollRun $run): array
    {
        $period = $run->period;
        $label = Carbon::create($period->year, $period->month, 1);
        $previousMonth = $label->copy()->subMonth();
        $previous = $this->runForPeriod($previousMonth->year, $previousMonth->month);

        $net = round((float) $run->total_net, 2);
        // ⚠ The same arithmetic as SalaryTotalWidget, so the card's movement
        // and the tile's movement are one number. No percentage here either:
        // C10c / C11c, a first run has nothing to be a percentage of.
        $delta = $previous === null ? null : round($net - (float) $previous->total_net, 2);

        return [
            'run_id' => $run->id,
            'period' => $period->label(),
            'year' => $period->year,
            'month' => $period->month,
            'version' => $run->version,
            'status' => $run->status->value,
            'status_label' => $run->status->label(),
            'blocked_count' => (int) $run->blocked_count,
            'net' => $net,
            'net_display' => ReportValue::forDisplay($net, ReportColumnType::Money),
            'delta' => $delta,
            'delta_display' => $delta === null
                ? null
                : ($delta >= 0 ? '+' : '−').ReportValue::forDisplay(abs($delta), ReportColumnType::Money),
            'delta_label' => $previous === null ? null : 'vs '.$previous->period?->label(),
            'paid_on' => $run->disbursed_at?->format('j M Y'),
        ];
    }

    /**
     * The cycle whose payroll has not been made: the current one, or the one
     * after it once the current cycle already has a live run.
     *
     * @return array<string, mixed>
     */
    private function next(): array
    {
        $cycle = PayrollCycle::containing(now());
        $run = $this->runForPeriod($cycle['year'], $cycle['month']);

        if ($run !== null && ! $run->status->isVoided()) {
            $following = Carbon::create($cycle['year'], $cycle['month'], 1)->addMonth();
            [$start, $end] = PayrollCycle::forMonth($following->year, $following->month);
            $cycle = ['start' => $start, 'end' => $end, 'year' => $following->year, 'month' => $following->month];
            $run = $this->runForPeriod($following->year, $following->month);
        }

        $today = now()->startOfDay();

        return [
            'period' => Carbon::create($cycle['year'], $cycle['month'], 1)->format('F Y'),
            'start' => $cycle['start']->toDateString(),
            'end' => $cycle['end']->toDateString(),
            'end_display' => $cycle['end']->format('j M'),
            // ⚠ Cast: Carbon 3 returns a float from diffInDays. Whole days only;
            // a cycle that ended already counts down to zero, never negative.
            'days_to_end' => $cycle['end']->lt($today) ? 0 : (int) $today->diffInDays($cycle['end']),
            // A voided run is not "started": it is a dead version, and the
            // honest next step is a fresh calculation (PayrollStatus::isVoided).
            'run_status' => $run === null ? null : $run->status->label(),
        ];
    }
}
