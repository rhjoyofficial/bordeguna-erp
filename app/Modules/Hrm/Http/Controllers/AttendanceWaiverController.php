<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\ProcessAttendance;
use App\Modules\Hrm\Models\AttendanceWaiver;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Support\PayrollCycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Forgiving one day's salary consequence (Joy, 24 Aug 2026: "permission person
 * can forgive this kind of things which recalculate the deduction").
 *
 * Gated by hrm.attendance.waive, which is deliberately NOT part of
 * hrm.attendance.manage: managing attendance is recording what happened,
 * waiving is deciding money. The two powers read very differently on an
 * audit, so they are held separately.
 */
class AttendanceWaiverController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('hrm.attendance.waive');

        $validated = $request->validate([
            'employee_id' => [
                'required', 'integer',
                Rule::exists('employee_assignments', 'employee_id')->where('business_id', $this->businessId()),
            ],
            'attendance_date' => ['required', 'date'],
            // A waiver moves money; an unexplained one is exactly the record an
            // auditor cannot evaluate later.
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);

        $day = DailyAttendance::where('employee_id', $employee->id)
            ->onDate($validated['attendance_date'])
            ->first();

        if ($day === null) {
            return back()->with('error', 'That day has not been processed, so there is nothing to forgive yet.');
        }

        // ⭐ 27 Sep 2026 (Joy): forgiveness is not only about a day that costs
        // money today. A late arrival the boss excused on a paper application
        // may sit inside the free allowance now, yet it still counts toward the
        // monthly ladder and pushes a LATER day into a deduction. So any day
        // that is absent, that already deducts, or that carries a lateness
        // occurrence can be forgiven; forgiving it takes it out of the ladder
        // (priorOccurrences) and the later days recompute with one fewer count.
        // Only a plain present day with no lateness has nothing to forgive.
        $canForgive = $day->status->value === 'absent'
            || $day->consequence->deducts()
            || $day->grace_window_key !== null;

        if (! $canForgive) {
            return back()->with('error', 'That day was on time and costs nothing - there is nothing to forgive.');
        }

        if (AttendanceWaiver::activeFor($employee->id, $validated['attendance_date']) !== null) {
            return back()->with('error', 'That day is already forgiven.');
        }

        AttendanceWaiver::create([
            'employee_id' => $employee->id,
            'attendance_date' => $validated['attendance_date'],
            'waived_status' => $day->status->value,
            'waived_consequence' => $day->consequence->value,
            'reason' => $validated['reason'],
            'waived_by' => $request->user()->id,
        ]);

        // ⭐ 27 Sep 2026 (Joy): forgiving a day also frees the ladder, so every
        // later day in the same cycle recomputes with one fewer occurrence.
        $this->reprocessCycleFrom($employee, $validated['attendance_date']);

        return back()->with('success', 'Day forgiven. The deduction is removed and the day no longer counts against the monthly lateness allowance; the attendance record itself is unchanged.');
    }

    public function revoke(Request $request, AttendanceWaiver $waiver): RedirectResponse
    {
        $this->authorize('hrm.attendance.waive');

        if (! $waiver->isActive()) {
            return back()->with('error', 'That waiver was already undone.');
        }

        // Revoked, never deleted: forgiveness granted and withdrawn are both
        // part of the money trail.
        $waiver->update([
            'revoked_at' => now(),
            'revoked_by' => $request->user()->id,
        ]);

        $this->reprocessCycleFrom($waiver->employee, $waiver->attendance_date->toDateString());

        return back()->with('success', 'Forgiveness undone. The day counts in payroll and against the lateness allowance again.');
    }

    /**
     * Recompute this employee's days from the forgiven date to the end of its
     * payroll cycle, but never past today (a future day has no punches yet, and
     * processing it would write a spurious absence). `force` so a finalised day
     * is recomputed too, because forgiveness is exactly the kind of decision
     * that changes an already-finalised number.
     */
    private function reprocessCycleFrom(Employee $employee, string $date): void
    {
        // The waived day itself keeps its record and its captured verdict; only
        // the days AFTER it in the same cycle need re-running, because their
        // occurrence numbers were counted with the now-forgiven day in them.
        $from = Carbon::parse($date)->addDay()->startOfDay();
        $cycleEnd = PayrollCycle::containing(Carbon::parse($date)->startOfDay())['end'];
        $today = Carbon::today();
        $to = $cycleEnd->greaterThan($today) ? $today : $cycleEnd;

        if ($to->lessThan($from)) {
            return;
        }

        // ⚠ Only days that already have a record are re-run. Never process an
        // empty date here: forDay would write an Absent day for a date nobody
        // has attendance for yet, inventing an absence the forgiveness never
        // implied. Production days always exist (the nightly job writes them);
        // this guard is what keeps a bare forgiveness from fabricating a month.
        $process = app(ProcessAttendance::class);

        DailyAttendance::where('employee_id', $employee->id)
            ->whereDate('attendance_date', '>=', $from->toDateString())
            ->whereDate('attendance_date', '<=', $to->toDateString())
            ->orderBy('attendance_date')
            ->pluck('attendance_date')
            ->each(fn ($day) => $process->forDay($employee, $day, force: true));
    }

    private function businessId(): int
    {
        return app(BusinessContext::class)->id();
    }
}
