<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Enums\BreakPolicy;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\ScheduleAssignment;
use App\Modules\Hrm\Models\Shift;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manages a business's shift definitions (SCH-02). CRUD only — assigning a
 * shift to an employee/group is a later Phase-2a slice. Deactivate rather than
 * delete once a shift is referenced by attendance (guarded here as that arrives).
 */
class ShiftController extends Controller
{
    public function index(): Response
    {
        $this->authorize('hrm.scheduling.manage');

        $shifts = Shift::orderBy('name')->get()->map(fn (Shift $shift) => $this->present($shift));

        return Inertia::render('hrm/shifts/index', [
            'shifts' => $shifts,
            'break_policies' => collect(BreakPolicy::cases())
                ->map(fn (BreakPolicy $policy) => [
                    'value' => $policy->value,
                    'label' => $policy->label(),
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('hrm.scheduling.manage');

        Shift::create($this->validated($request));

        return back()->with('success', 'Shift created.');
    }

    public function update(Request $request, Shift $shift): RedirectResponse
    {
        $this->authorize('hrm.scheduling.manage');

        $shift->update($this->validated($request, $shift));

        return back()->with('success', 'Shift updated.');
    }

    public function toggle(Shift $shift): RedirectResponse
    {
        $this->authorize('hrm.scheduling.manage');

        $shift->update(['is_active' => ! $shift->is_active]);

        return back()->with('success', $shift->is_active ? 'Shift activated.' : 'Shift deactivated.');
    }

    public function destroy(Shift $shift): RedirectResponse
    {
        $this->authorize('hrm.scheduling.manage');

        // ⚠⚠ The foreign keys null on delete, so deleting a shift in use
        // silently stripped it from every schedule assignment and from past
        // attendance days. A shift that was ever used is deactivated, never
        // deleted.
        $inUse = ScheduleAssignment::withoutGlobalScopes()->where('shift_id', $shift->id)->exists()
            || DailyAttendance::withoutGlobalScopes()->where('shift_id', $shift->id)->exists();

        if ($inUse) {
            return back()->with('error', 'This shift is used by a schedule or by recorded attendance, so it cannot be deleted. Deactivate it instead.');
        }

        $shift->delete();

        return back()->with('success', 'Shift deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Shift $shift = null): array
    {
        $businessId = app(BusinessContext::class)->id();

        $validator = Validator::make($request->all(), [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('shifts', 'name')->where('business_id', $businessId)->ignore($shift?->id),
            ],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'grace_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'break_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            // POL-08: how break time is treated varies by shift, so the choice
            // lives here rather than in the processing engine. Optional — an
            // omitted value keeps the column default, so this stays additive
            // for anything already creating shifts without it.
            'break_policy' => ['sometimes', 'required', new Enum(BreakPolicy::class)],
            // The meal window (C21): required as a set when the mode is
            // meal_window, so a half-configured window is impossible to save
            // rather than discovered a month later in the numbers.
            'meal_window_start' => ['nullable', 'required_if:break_policy,meal_window', 'date_format:H:i'],
            'meal_window_end' => ['nullable', 'required_if:break_policy,meal_window', 'date_format:H:i'],
            'meal_standard_minutes' => ['nullable', 'required_if:break_policy,meal_window', 'integer', 'min:1', 'max:1440'],
            'meal_allowance_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'meal_deduct_when_not_punched' => ['sometimes', 'boolean'],
            'meal_short_day_tolerance_minutes' => ['nullable', 'integer', 'min:0', 'max:120'],
            'minimum_payable_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'late_threshold_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'early_leave_threshold_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'is_overnight' => ['required', 'boolean'],
            'cross_midnight_window_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
        ]);

        // A same-day shift must end after it starts; an overnight shift is
        // expected to "end" earlier in clock terms (it crosses midnight).
        $validator->after(function ($validator) use ($request) {
            if (! $request->boolean('is_overnight') && $request->input('end_time') <= $request->input('start_time')) {
                $validator->errors()->add('end_time', 'End time must be after start time for a same-day shift.');
            }

            if ($request->input('break_policy') !== BreakPolicy::MealWindow->value) {
                return;
            }

            $windowStart = $request->input('meal_window_start');
            $windowEnd = $request->input('meal_window_end');

            if ($windowStart === null || $windowEnd === null) {
                return; // required_if already reported these.
            }

            if ($windowEnd <= $windowStart) {
                $validator->errors()->add('meal_window_end', 'The meal window must end after it starts.');

                return;
            }

            // The window must sit inside the shift span. Overnight shifts are
            // excluded from the meal mode entirely for now — the window's
            // instants would be ambiguous across midnight.
            if ($request->boolean('is_overnight')) {
                $validator->errors()->add('break_policy', 'The meal window mode is not available on an overnight shift.');

                return;
            }

            if ($windowStart < $request->input('start_time') || $windowEnd > $request->input('end_time')) {
                $validator->errors()->add('meal_window_start', 'The meal window must lie inside the shift hours.');
            }

            $width = (strtotime($windowEnd) - strtotime($windowStart)) / 60;

            if ((int) $request->input('meal_standard_minutes') > $width) {
                $validator->errors()->add('meal_standard_minutes', 'The standard meal cannot be longer than the window itself.');
            }

            if ($request->filled('meal_allowance_minutes') && (int) $request->input('meal_allowance_minutes') > $width) {
                $validator->errors()->add('meal_allowance_minutes', 'The meal allowance cannot be longer than the window itself.');
            }
        });

        return $validator->validate();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Shift $shift): array
    {
        return [
            'id' => $shift->id,
            'name' => $shift->name,
            'start_time' => $shift->timeValue('start_time'),
            'end_time' => $shift->timeValue('end_time'),
            'grace_minutes' => $shift->grace_minutes,
            'break_minutes' => $shift->break_minutes,
            'break_policy' => $shift->break_policy?->value,
            'meal_window_start' => $shift->timeValue('meal_window_start'),
            'meal_window_end' => $shift->timeValue('meal_window_end'),
            'meal_standard_minutes' => $shift->meal_standard_minutes,
            'meal_allowance_minutes' => $shift->meal_allowance_minutes,
            'meal_deduct_when_not_punched' => (bool) $shift->meal_deduct_when_not_punched,
            'meal_short_day_tolerance_minutes' => $shift->meal_short_day_tolerance_minutes,
            'minimum_payable_hours' => $shift->minimum_payable_hours,
            'late_threshold_minutes' => $shift->late_threshold_minutes,
            'early_leave_threshold_minutes' => $shift->early_leave_threshold_minutes,
            'is_overnight' => $shift->is_overnight,
            'cross_midnight_window_minutes' => $shift->cross_midnight_window_minutes,
            'is_active' => $shift->is_active,
        ];
    }
}
