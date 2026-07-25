<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hrm\Actions\RecordPunch;
use App\Modules\Hrm\Enums\PunchDirection;
use App\Modules\Hrm\Enums\PunchSource;
use App\Modules\Hrm\Models\AttendancePunch;
use App\Modules\Hrm\Models\Employee;
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
}
