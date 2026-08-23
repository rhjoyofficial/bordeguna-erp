<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hrm\Models\AttendancePeriodLock;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Support\AttendanceSummariser;
use App\Modules\Hrm\Support\PayrollCycle;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One employee's attendance, on its own page (Joy, 23 Aug 2026: "If i wish to
 * see for one employee attendance show like [the SmartHR employee view], can
 * filter as i wish, default last month 24 to today").
 *
 * The summary screen answers "how is everyone doing"; this answers "show me
 * Karim". Same AttendanceSummariser, so the row a manager clicked and the page
 * it opens can never disagree about the numbers.
 *
 * ⚠ The default range is the CURRENT PAYROLL CYCLE up to today - for Bor De
 * Guna that is literally "the 24th to today", which is the phrase Joy used.
 */
class EmployeeAttendanceController extends Controller
{
    public function show(Request $request, Employee $employee, AttendanceSummariser $summariser): Response
    {
        $this->authorize('hrm.attendance.manage');

        // Same confinement as the employee pages: visible here only if they
        // hold an assignment in the current business.
        abort_unless($employee->assignments()->exists(), 404);

        [$from, $to] = $this->range($request);

        return Inertia::render('hrm/attendance/employee', [
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->fullName(),
                'employee_code' => $employee->employee_code,
                'photo_url' => $employee->attachmentInCollection('photo')?->url(),
                'department' => $employee->currentAssignment()?->department?->name,
                'designation' => $employee->currentAssignment()?->designation?->name,
            ],
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'summary' => $summariser->forEmployee($from, $to, $employee->id),
            'days' => DailyAttendance::query()
                ->where('employee_id', $employee->id)
                ->whereDate('attendance_date', '>=', $from->toDateString())
                ->whereDate('attendance_date', '<=', $to->toDateString())
                ->orderByDesc('attendance_date')
                ->get()
                ->map(fn (DailyAttendance $day) => [
                    'date' => $day->attendance_date->toDateString(),
                    'weekday' => $day->attendance_date->format('D'),
                    'status' => $day->status->label(),
                    'status_value' => $day->status->value,
                    'first_in' => $day->first_in?->format('H:i'),
                    'last_out' => $day->last_out?->format('H:i'),
                    'worked_minutes' => (int) $day->worked_minutes,
                    'late_minutes' => (int) $day->late_minutes,
                    'holds_payroll' => $day->holds_payroll,
                    'is_provisional' => $day->is_provisional,
                    'is_locked' => AttendancePeriodLock::isLocked($day->attendance_date),
                ]),
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(Request $request): array
    {
        $to = $request->filled('to')
            ? Carbon::parse($request->string('to')->toString())
            : Carbon::today();

        $from = $request->filled('from')
            ? Carbon::parse($request->string('from')->toString())
            : PayrollCycle::containing($to)['start'];

        return $from->gt($to) ? [$to, $from] : [$from, $to];
    }
}
