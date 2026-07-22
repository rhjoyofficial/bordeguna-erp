<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Shift;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
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
            'minimum_payable_hours' => $shift->minimum_payable_hours,
            'late_threshold_minutes' => $shift->late_threshold_minutes,
            'early_leave_threshold_minutes' => $shift->early_leave_threshold_minutes,
            'is_overnight' => $shift->is_overnight,
            'cross_midnight_window_minutes' => $shift->cross_midnight_window_minutes,
            'is_active' => $shift->is_active,
        ];
    }
}
