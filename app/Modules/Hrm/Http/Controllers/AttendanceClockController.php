<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hrm\Actions\RecordPunch;
use App\Modules\Hrm\Actions\RequestCorrection;
use App\Modules\Hrm\Enums\CorrectionType;
use App\Modules\Hrm\Enums\PunchDirection;
use App\Modules\Hrm\Enums\PunchSource;
use App\Modules\Hrm\Models\AttendanceCorrection;
use App\Modules\Hrm\Models\AttendancePeriodLock;
use App\Modules\Hrm\Models\AttendancePunch;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Validation\Rule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rules\Enum as EnumRule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Employee self-service clock-in / clock-out (ATT-01).
 *
 * Access is self-scope, not a management permission — this is the employee's
 * own record, the same rule My Profile uses (P3).
 *
 * Correction C2 makes proof mandatory rather than optional: GPS coordinates
 * with accuracy, IP address and device metadata are captured on every punch.
 * Geofence enforcement is separate and never blocks the punch — an
 * out-of-fence clock-in is stored and flagged for review (ATT-05).
 */
class AttendanceClockController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $employee = Employee::forUser($request->user());

        if ($employee === null) {
            return redirect()->route('dashboard')
                ->with('error', 'Your user account is not linked to an employee record yet.');
        }

        $today = Carbon::today();

        return Inertia::render('hrm/attendance/clock', [
            'employee' => [
                'name' => $employee->fullName(),
                'employee_code' => $employee->employee_code,
                'office' => $employee->currentAssignment()?->office?->name,
            ],
            'today' => $today->toDateString(),
            'punches' => AttendancePunch::where('employee_id', $employee->id)
                ->onDate($today)
                ->orderBy('punched_at')
                ->get()
                ->map(fn (AttendancePunch $punch) => [
                    'id' => $punch->id,
                    'time' => $punch->punched_at->format('H:i:s'),
                    'direction' => $punch->direction?->label(),
                    'source' => $punch->source->label(),
                    'needs_review' => $punch->needs_review,
                    'review_reason' => $punch->review_reason,
                ]),
            // The last fortnight of processed days, so an employee sees a
            // problem where they would actually notice it and can raise a
            // correction from the same screen (COR-01).
            'recent_days' => DailyAttendance::where('employee_id', $employee->id)
                ->whereDate('attendance_date', '>=', $today->copy()->subDays(14)->toDateString())
                ->whereDate('attendance_date', '<=', $today->toDateString())
                ->orderByDesc('attendance_date')
                ->get()
                ->map(fn (DailyAttendance $day) => [
                    'date' => $day->attendance_date->toDateString(),
                    'status' => $day->status->label(),
                    'first_in' => $day->first_in?->format('H:i'),
                    'last_out' => $day->last_out?->format('H:i'),
                    'holds_payroll' => $day->holds_payroll,
                    'is_provisional' => $day->is_provisional,
                    'is_locked' => AttendancePeriodLock::isLocked($day->attendance_date),
                ]),
            'my_corrections' => AttendanceCorrection::where('employee_id', $employee->id)
                ->with('approvalRequest.workflow.stages')
                ->orderByDesc('id')->limit(10)->get()
                ->map(fn (AttendanceCorrection $c) => [
                    'id' => $c->id,
                    'date' => Carbon::parse($c->attendance_date)->toDateString(),
                    'summary' => $c->summaryLine(),
                    'status' => $c->approvalRequest?->status->value ?? ($c->isApplied() ? 'applied' : 'pending'),
                    'stage' => $c->approvalRequest && $c->approvalRequest->totalStages() > 1
                        ? $c->approvalRequest->currentStage()?->name
                        : null,
                ]),
            'correction_types' => collect(CorrectionType::cases())
                ->map(fn (CorrectionType $t) => [
                    'value' => $t->value,
                    'label' => $t->label(),
                    'needs_punch' => $t->needsPunch(),
                    'needs_time' => $t->needsTime(),
                ]),
            'directions' => collect(PunchDirection::cases())
                ->map(fn (PunchDirection $d) => ['value' => $d->value, 'label' => $d->label()]),
        ]);
    }

    public function store(Request $request, RecordPunch $recordPunch): RedirectResponse
    {
        $employee = Employee::forUser($request->user());

        if ($employee === null) {
            return redirect()->route('dashboard')
                ->with('error', 'Your user account is not linked to an employee record yet.');
        }

        // Proof is required, not optional (Correction C2). An employee who
        // cannot share location falls back to the manual register (ATT-07)
        // rather than creating an unverifiable self-service punch.
        $validated = $request->validate([
            'direction' => ['required', new EnumRule(PunchDirection::class)],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'gps_accuracy_meters' => ['required', 'numeric', 'min:0'],
            'device_metadata' => ['nullable', 'array'],
        ], [
            'latitude.required' => 'Location is required to clock in. Allow location access, or ask HR to record it manually.',
            'longitude.required' => 'Location is required to clock in. Allow location access, or ask HR to record it manually.',
        ]);

        $punch = $recordPunch->handle($employee, now(), PunchSource::Web, [
            'direction' => PunchDirection::from($validated['direction']),
            'latitude' => $validated['latitude'],
            'longitude' => $validated['longitude'],
            'gps_accuracy_meters' => $validated['gps_accuracy_meters'],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'device_metadata' => $validated['device_metadata'] ?? null,
        ]);

        if ($punch === null) {
            return back()->with('error', 'That punch is already recorded.');
        }

        return back()->with(
            'success',
            $punch->needs_review
                ? 'Recorded, and flagged for review: '.$punch->review_reason
                : 'Recorded at '.$punch->punched_at->format('H:i').'.',
        );
    }

    /**
     * An employee raising a correction on their own attendance (COR-01).
     *
     * Self-scope, like everything else on this screen: the employee is resolved
     * from the signed-in user and can only ever correct their own day. Their
     * request routes through the Manager → HR workflow rather than applying —
     * the privileged direct correction is HR's path, not this one.
     */
    public function requestCorrection(Request $request, RequestCorrection $action): RedirectResponse
    {
        $employee = Employee::forUser($request->user());

        if ($employee === null) {
            return redirect()->route('dashboard')
                ->with('error', 'Your user account is not linked to an employee record yet.');
        }

        $type = CorrectionType::tryFrom($request->string('type')->toString());

        $validated = $request->validate([
            'attendance_date' => ['required', 'date', 'before_or_equal:today'],
            'type' => ['required', new EnumRule(CorrectionType::class)],
            'punch_id' => [
                $type?->needsPunch() ? 'required' : 'nullable',
                // Scoped to this employee's own punches, so one person's request
                // can never reference another's record.
                Rule::exists('attendance_punches', 'id')->where('employee_id', $employee->id),
            ],
            'corrected_at' => [$type?->needsTime() ? 'required' : 'nullable', 'date'],
            'direction' => ['nullable', new EnumRule(PunchDirection::class)],
            'reason' => ['required', 'string', 'max:1000'],
            'evidence' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);

        $action->handle(
            $employee,
            Carbon::parse($validated['attendance_date'])->toDateString(),
            $validated,
            $request->user(),
            $request->file('evidence'),
        );

        return back()->with('success', 'Correction requested. Your manager will review it.');
    }
}
