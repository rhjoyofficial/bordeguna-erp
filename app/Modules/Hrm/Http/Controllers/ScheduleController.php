<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Models\Section;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\AssignSchedule;
use App\Modules\Hrm\Enums\ScheduleScope;
use App\Modules\Hrm\Enums\ScheduleType;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\HolidayCalendar;
use App\Modules\Hrm\Models\ScheduleAssignment;
use App\Modules\Hrm\Models\Shift;
use App\Modules\Hrm\Support\ScheduleResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Exists;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Assigns shifts and holiday calendars to groups and individuals (SCH-01/06/07)
 * and shows the resulting roster — who actually works to which schedule today,
 * and which assignment decided it.
 *
 * The write path is AssignSchedule (close-old-open-new); this controller only
 * validates and presents.
 */
class ScheduleController extends Controller
{
    public function index(Request $request, ScheduleResolver $resolver): Response
    {
        $this->authorize('hrm.scheduling.manage');

        // The roster is answerable for any date, so HR can check a schedule
        // before it takes effect (or reconstruct a past one).
        $on = $request->date('on')
            ? Carbon::parse($request->string('on')->toString())
            : Carbon::today();

        $employees = Employee::whereHas('assignments', fn ($query) => $query->activeOn($on)->where('is_primary', true))
            ->with(['assignments' => fn ($query) => $query->activeOn($on)->where('is_primary', true)
                ->with(['office:id,name', 'department:id,name', 'section:id,name'])])
            ->orderBy('employee_code')->get();

        $resolved = $resolver->resolveMany($employees, $on);

        return Inertia::render('hrm/schedules/index', [
            'on' => $on->toDateString(),
            'assignments' => ScheduleAssignment::with(['shift:id,name', 'calendar:id,name', 'creator:id,name'])
                ->orderByDesc('effective_from')->orderByDesc('id')->get()
                ->map(fn (ScheduleAssignment $assignment) => $this->present($assignment, $on)),
            'roster' => $employees->map(function (Employee $employee) use ($resolved) {
                $placement = $employee->assignments->first();
                $schedule = $resolved[$employee->id] ?? null;

                return [
                    'id' => $employee->id,
                    'employee_code' => $employee->employee_code,
                    'name' => $employee->fullName(),
                    'placement' => collect([
                        $placement?->office?->name,
                        $placement?->department?->name,
                        $placement?->section?->name,
                    ])->filter()->implode(' › ') ?: null,
                    'schedule_type' => $schedule?->schedule_type->label(),
                    'shift' => $schedule?->shift?->name,
                    'shift_times' => $schedule?->shift
                        ? $schedule->shift->timeValue('start_time').' – '.$schedule->shift->timeValue('end_time')
                        : null,
                    'calendar' => $schedule?->calendar?->name,
                    // Which assignment won, so an unexpected schedule is traceable
                    // to the row that produced it rather than being a mystery.
                    'source' => $schedule?->describeTarget(),
                ];
            }),
            'options' => $this->options(),
        ]);
    }

    public function store(Request $request, AssignSchedule $assign): RedirectResponse
    {
        $this->authorize('hrm.scheduling.manage');

        $data = $this->validated($request);
        $scope = ScheduleScope::from($data['scope_type']);

        $assign->handle($scope, $data['scope_id'] ?? null, $data);

        return back()->with('success', 'Schedule assigned.');
    }

    /**
     * Closes an assignment as of a date. Ending is the normal way to stop a
     * schedule — the row stays as history.
     */
    public function end(Request $request, ScheduleAssignment $assignment, AssignSchedule $assign): RedirectResponse
    {
        $this->authorize('hrm.scheduling.manage');

        $validated = $request->validate([
            'effective_to' => ['required', 'date'],
        ]);

        $assign->end($assignment, $validated['effective_to']);

        return back()->with('success', 'Schedule ended.');
    }

    /**
     * Deleting is only for a schedule that never took effect — anything already
     * in force must be ended, so history stays intact (SCH-08).
     */
    public function destroy(ScheduleAssignment $assignment): RedirectResponse
    {
        $this->authorize('hrm.scheduling.manage');

        if (Carbon::parse($assignment->effective_from)->lte(Carbon::today())) {
            return back()->with('error', 'This schedule has already taken effect — end it instead of deleting it.');
        }

        $assignment->delete();

        return back()->with('success', 'Scheduled assignment removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $scope = ScheduleScope::tryFrom($request->string('scope_type')->toString());
        $type = ScheduleType::tryFrom($request->string('schedule_type')->toString());

        $businessId = app(BusinessContext::class)->id();

        $validator = Validator::make($request->all(), [
            'scope_type' => ['required', new Enum(ScheduleScope::class)],
            'scope_id' => array_filter([
                $scope?->needsTarget() ? 'required' : 'nullable',
                'integer',
                $scope ? $this->targetExists($scope) : null,
            ]),
            'schedule_type' => ['required', new Enum(ScheduleType::class)],
            // A calendar is always required: it is what makes a day a working
            // day at all, so no schedule is meaningful without one.
            'holiday_calendar_id' => ['required', Rule::exists('holiday_calendars', 'id')->where('business_id', $businessId)->where('is_active', true)],
            'shift_id' => [
                $type?->requiresShift() ? 'required' : 'nullable',
                Rule::exists('shifts', 'id')->where('business_id', $businessId)->where('is_active', true),
            ],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'shift_id.required' => 'A '.($type?->label() ?? 'fixed').' schedule needs a shift.',
        ]);

        return $validator->validate();
    }

    /**
     * Every scope target is a business-scoped row, so pinning the tenant here
     * means an id borrowed from another business simply does not exist.
     */
    private function targetExists(ScheduleScope $scope): ?Exists
    {
        $businessId = app(BusinessContext::class)->id();

        $table = match ($scope) {
            ScheduleScope::Office => 'offices',
            ScheduleScope::Department => 'departments',
            ScheduleScope::Section => 'sections',
            // An employee "belongs" to this business through an assignment.
            ScheduleScope::Employee => 'employee_assignments',
            ScheduleScope::Business => null,
        };

        if ($table === null) {
            return null;
        }

        $column = $scope === ScheduleScope::Employee ? 'employee_id' : 'id';

        return Rule::exists($table, $column)->where('business_id', $businessId);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ScheduleAssignment $assignment, Carbon $on): array
    {
        return [
            'id' => $assignment->id,
            'scope' => $assignment->scope_type->label(),
            // Named alone for the business-wide scope, which has no target.
            'target' => $assignment->scope_type->needsTarget() ? $assignment->targetName() : null,
            'schedule_type' => $assignment->schedule_type->label(),
            'shift' => $assignment->shift?->name,
            'calendar' => $assignment->calendar?->name,
            'effective_from' => Carbon::parse($assignment->effective_from)->toDateString(),
            'effective_to' => $assignment->effective_to ? Carbon::parse($assignment->effective_to)->toDateString() : null,
            'is_temporary' => $assignment->isTemporary(),
            'is_auto_restored' => $assignment->isAutoRestored(),
            'is_active' => $assignment->isActiveOn($on),
            'is_future' => Carbon::parse($assignment->effective_from)->gt(Carbon::today()),
            'note' => $assignment->note,
            'created_by' => $assignment->creator?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'scopes' => collect(ScheduleScope::cases())
                ->map(fn (ScheduleScope $scope) => [
                    'value' => $scope->value,
                    'label' => $scope->label(),
                    'needs_target' => $scope->needsTarget(),
                ]),
            'schedule_types' => collect(ScheduleType::cases())
                ->map(fn (ScheduleType $type) => [
                    'value' => $type->value,
                    'label' => $type->label(),
                    'requires_shift' => $type->requiresShift(),
                ]),
            'shifts' => Shift::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'calendars' => HolidayCalendar::where('is_active', true)->orderByDesc('year')->orderBy('name')->get()
                ->map(fn (HolidayCalendar $calendar) => [
                    'id' => $calendar->id,
                    'name' => $calendar->name.' ('.$calendar->year.')',
                ]),
            'offices' => Office::where('is_active', true)
                ->with(['departments' => fn ($query) => $query->where('is_active', true)->orderBy('name')
                    ->with(['sections' => fn ($q) => $q->where('is_active', true)->orderBy('name')])])
                ->orderBy('name')->get()
                ->map(fn (Office $office) => [
                    'id' => $office->id,
                    'name' => $office->name,
                    'departments' => $office->departments->map(fn (Department $department) => [
                        'id' => $department->id,
                        'name' => $department->name,
                        'sections' => $department->sections->map(fn (Section $section) => [
                            'id' => $section->id,
                            'name' => $section->name,
                        ]),
                    ]),
                ]),
            'employees' => Employee::whereHas('assignments', fn ($query) => $query->active()->where('is_primary', true))
                ->orderBy('employee_code')->get()
                ->map(fn (Employee $employee) => [
                    'id' => $employee->id,
                    'name' => $employee->fullName().' ('.$employee->employee_code.')',
                ]),
        ];
    }
}
