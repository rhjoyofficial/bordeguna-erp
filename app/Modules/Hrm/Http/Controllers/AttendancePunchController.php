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
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum as EnumRule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The raw punch log (ATT-01/03) — HR's window onto what was actually captured,
 * plus the controlled manual entry ATT-01 allows for the offline fallback in
 * ATT-07 (manual register, later keyed in by HR).
 *
 * There is deliberately no edit or delete action here: punches are immutable
 * (ATT-03) and the model refuses either. Manual entry requires a reason, which
 * is what makes the audit trail worth having.
 */
class AttendancePunchController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('hrm.attendance.manage');

        $on = $request->date('on') ? Carbon::parse($request->string('on')->toString()) : Carbon::today();
        $employeeId = $request->integer('employee_id') ?: null;
        $onlyReview = $request->boolean('review');

        $punches = AttendancePunch::with(['employee:id,employee_code,first_name,last_name', 'recorder:id,name'])
            ->onDate($on)
            ->when($employeeId, fn ($query) => $query->where('employee_id', $employeeId))
            ->when($onlyReview, fn ($query) => $query->needingReview())
            ->orderBy('punched_at')
            ->get()
            ->map(fn (AttendancePunch $punch) => $this->present($punch));

        return Inertia::render('hrm/attendance/index', [
            'on' => $on->toDateString(),
            'filters' => ['employee_id' => $employeeId, 'review' => $onlyReview],
            'punches' => $punches,
            'summary' => [
                'total' => $punches->count(),
                'needs_review' => $punches->where('needs_review', true)->count(),
                'employees' => $punches->pluck('employee_id')->unique()->count(),
            ],
            'employees' => $this->employeeOptions(),
            'directions' => collect(PunchDirection::cases())
                ->map(fn (PunchDirection $d) => ['value' => $d->value, 'label' => $d->label()]),
        ]);
    }

    /**
     * HR keys in a punch from the manual register (ATT-07). Recorded as a
     * normal immutable punch tagged Manual, with a mandatory reason so the
     * activity trail ATT-01 requires is meaningful.
     */
    public function store(Request $request, RecordPunch $recordPunch): RedirectResponse
    {
        $this->authorize('hrm.attendance.manage');

        $validated = $request->validate([
            'employee_id' => [
                'required', 'integer',
                Rule::exists('employee_assignments', 'employee_id')->where('business_id', $this->businessId()),
            ],
            'punched_at' => ['required', 'date'],
            'direction' => ['nullable', new EnumRule(PunchDirection::class)],
            // A manual punch without a stated reason is exactly the record an
            // auditor cannot evaluate later, so it is required rather than nice.
            'note' => ['required', 'string', 'max:255'],
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);

        $punch = $recordPunch->handle($employee, $validated['punched_at'], PunchSource::Manual, [
            'direction' => $validated['direction'] ?? null,
            'note' => $validated['note'],
        ]);

        return back()->with(
            $punch === null ? 'error' : 'success',
            $punch === null
                ? 'That punch is already recorded for this employee and time.'
                : 'Punch recorded.',
        );
    }

    private function businessId(): int
    {
        return app(\App\Modules\Foundation\Support\BusinessContext::class)->id();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AttendancePunch $punch): array
    {
        return [
            'id' => $punch->id,
            'employee_id' => $punch->employee_id,
            'employee' => $punch->employee?->fullName(),
            'employee_code' => $punch->employee?->employee_code,
            'punched_at' => $punch->punched_at->format('Y-m-d H:i:s'),
            'time' => $punch->punched_at->format('H:i:s'),
            'source' => $punch->source->label(),
            'direction' => $punch->direction?->label(),
            'device_identifier' => $punch->device_identifier,
            'enroll_id' => $punch->enroll_id,
            'has_location' => $punch->latitude !== null && $punch->longitude !== null,
            'location' => $punch->latitude !== null && $punch->longitude !== null
                ? $punch->latitude.', '.$punch->longitude
                : null,
            'gps_accuracy_meters' => $punch->gps_accuracy_meters,
            'ip_address' => $punch->ip_address,
            'is_outside_geofence' => $punch->is_outside_geofence,
            'needs_review' => $punch->needs_review,
            'review_reason' => $punch->review_reason,
            'note' => $punch->note,
            'recorded_by' => $punch->recorder?->name,
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function employeeOptions()
    {
        return Employee::whereHas('assignments', fn ($query) => $query->active()->where('is_primary', true))
            ->orderBy('first_name')->orderBy('last_name')->get()
            ->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'name' => $employee->fullName().' ('.$employee->employee_code.')',
            ]);
    }
}
