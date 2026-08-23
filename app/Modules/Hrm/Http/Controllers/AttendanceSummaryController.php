<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Support\AttendanceSummariser;
use App\Modules\Hrm\Support\PayrollCycle;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One row per employee: how many days late, absent, present and the rest,
 * across any range.
 *
 * ⚠ The daily review screen already answers "what happened on these days".
 * It could not answer "how is this person doing this month" without a human
 * counting rows, which is the question a manager actually asks. Same data,
 * different axis; the totals come from AttendanceSummariser so the two
 * screens cannot disagree.
 *
 * ⚠ Defaults to the current month rather than a rolling 30 days, because
 * attendance is discussed and paid by month. A rolling window makes "how many
 * times was he late" depend on the day you asked.
 */
class AttendanceSummaryController extends Controller
{
    public function index(Request $request, AttendanceSummariser $summariser): Response
    {
        $this->authorize('hrm.attendance.manage');

        [$from, $to] = $this->range($request);

        $rows = $summariser->forRange($from, $to);

        return Inertia::render('hrm/attendance/summary', [
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'rows' => $rows,
            // ⚠ Totals summed from the same rows the table shows, not queried
            // again. A second query is a second chance to disagree with the
            // thing directly above it.
            'totals' => [
                'employees' => count($rows),
                'days_recorded' => array_sum(array_column($rows, 'days_recorded')),
                'attended' => array_sum(array_column($rows, 'attended')),
                'late' => array_sum(array_column($rows, 'late')),
                'absent' => array_sum(array_column($rows, 'absent')),
                'missing_punch' => array_sum(array_column($rows, 'missing_punch')),
                'on_leave' => array_sum(array_column($rows, 'on_leave')),
                'late_minutes' => array_sum(array_column($rows, 'late_minutes')),
                'worked_hours' => round(array_sum(array_column($rows, 'worked_hours')), 1),
                'holds_payroll' => array_sum(array_column($rows, 'holds_payroll')),
                'provisional' => array_sum(array_column($rows, 'provisional')),
            ],
        ]);
    }

    /**
     * The month being summarised, or whatever range was asked for.
     *
     * ⚠ Anchored on the newest processed day rather than today, for the same
     * reason the daily screen is: today is routinely a day nothing has been
     * processed for, and an empty table reads as "nobody came to work".
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(Request $request): array
    {
        $latest = DailyAttendance::max('attendance_date');
        $anchor = $latest !== null ? Carbon::parse($latest) : Carbon::today();

        $to = $request->filled('to') ? Carbon::parse($request->string('to')->toString()) : $anchor;
        $from = $request->filled('from')
            ? Carbon::parse($request->string('from')->toString())
            // The current PAYROLL month, not the calendar one - for Bor De Guna
            // that is the 24th (Settings → Business), so the default answers
            // "how is this person doing this pay month", which is the month the
            // consequences and the payslip are counted in.
            : PayrollCycle::containing($to)['start'];

        return $from->gt($to) ? [$to, $from] : [$from, $to];
    }
}
