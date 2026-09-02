<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\LeaveRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The manager's team view (Module 5, Slice 1), built ON the Module-1
 * hierarchy: whoever supervises anyone sees their direct reports with
 * today's attendance and any pending leave.
 *
 * ⚠ READ-ONLY on purpose. Approving stays with the Approvals inbox - the
 * single approval surface. A second approve button on this page is exactly
 * how unreachable engines are born.
 */
class MyTeamController extends Controller
{
    public function show(Request $request): Response
    {
        $me = Employee::query()->where('user_id', $request->user()->id)->first();

        $reports = $me === null ? collect() : Employee::query()
            ->whereIn('id', EmployeeAssignment::query()
                ->where('supervisor_id', $me->id)
                ->whereNull('effective_to')
                ->pluck('employee_id'))
            ->where('status', 'active')
            ->orderBy('employee_code')
            ->get();

        $today = Carbon::today()->toDateString();

        $attendanceToday = DailyAttendance::query()
            ->whereIn('employee_id', $reports->pluck('id'))
            ->whereDate('attendance_date', $today)
            ->pluck('status', 'employee_id');

        // "Waiting" = not yet applied to attendance and not decided against:
        // either no approval request exists yet, or it still says pending.
        $pendingLeaves = LeaveRequest::query()
            ->whereIn('employee_id', $reports->pluck('id'))
            ->whereNull('applied_at')
            ->where(fn ($query) => $query
                ->whereDoesntHave('approvalRequest')
                ->orWhereHas('approvalRequest', fn ($approval) => $approval->where('status', 'pending')))
            ->with('leaveType:id,name')
            ->get()
            ->groupBy('employee_id');

        return Inertia::render('hrm/my-team', [
            'team' => $reports->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'name' => trim($employee->first_name.' '.$employee->last_name),
                'designation' => $employee->currentAssignment()?->designation?->name,
                'today' => $attendanceToday[$employee->id]?->value ?? $attendanceToday[$employee->id] ?? null,
                'pending_leave' => ($pendingLeaves[$employee->id] ?? collect())->map(fn (LeaveRequest $leave) => [
                    'id' => $leave->id,
                    'type' => $leave->leaveType?->name,
                    'start_date' => $leave->start_date?->toDateString(),
                    'end_date' => $leave->end_date?->toDateString(),
                    'days' => $leave->days,
                    'reason' => $leave->reason,
                ])->values(),
            ]),
        ]);
    }
}
