<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Models\Section;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\AssignSchedule;
use App\Modules\Hrm\Enums\ScheduleScope;
use App\Modules\Hrm\Enums\ScheduleType;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\HolidayCalendar;
use App\Modules\Hrm\Models\ScheduleAssignment;
use App\Modules\Hrm\Models\Shift;
use App\Modules\Hrm\Support\ScheduleResolver;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->business = Business::create(['name' => 'Sched Co', 'slug' => 'sched-co']);
    $this->otherBusiness = Business::create(['name' => 'Other Sched', 'slug' => 'other-sched']);
    app(BusinessContext::class)->set($this->business->id);

    Permission::firstOrCreate(['name' => 'hrm.scheduling.manage', 'guard_name' => 'web']);
    foreach ([$this->business, $this->otherBusiness] as $biz) {
        foreach (['Owner', 'Staff'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'business_id' => $biz->id, 'guard_name' => 'web']);
        }
        Role::where('business_id', $biz->id)->where('name', 'Owner')->first()->givePermissionTo('hrm.scheduling.manage');
    }
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');

    // The scheduling building blocks the assignments point at.
    $this->generalShift = Shift::create([
        'name' => 'General', 'start_time' => '09:00', 'end_time' => '18:00',
        'grace_minutes' => 15, 'break_minutes' => 60, 'is_overnight' => false,
    ]);
    $this->nightShift = Shift::create([
        'name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00',
        'grace_minutes' => 15, 'break_minutes' => 45, 'is_overnight' => true,
    ]);
    $this->ramadanShift = Shift::create([
        'name' => 'Ramadan', 'start_time' => '09:00', 'end_time' => '15:00',
        'grace_minutes' => 15, 'break_minutes' => 0, 'is_overnight' => false,
    ]);
    $this->calendar = HolidayCalendar::create([
        'name' => 'Bangladesh', 'year' => 2026, 'weekly_off_days' => [5], 'is_default' => true,
    ]);

    $this->office = Office::create(['name' => 'Head Office', 'is_active' => true]);
    $this->department = Department::create(['office_id' => $this->office->id, 'name' => 'Production', 'is_active' => true]);
    $this->section = Section::create(['department_id' => $this->department->id, 'name' => 'Line A', 'is_active' => true]);
});

function schedOwner()
{
    return test()->actingAs(test()->owner)->withSession(['current_business_id' => test()->business->id]);
}

/**
 * An employee placed in the given org units from a date — the placement is what
 * makes a group-level schedule assignment reach them.
 */
function schedEmployee(string $name, array $placement = [], string $from = '2026-01-01'): Employee
{
    $employee = Employee::create([
        'employee_code' => 'BDG-'.str_pad((string) (Employee::count() + 1), 6, '0', STR_PAD_LEFT),
        'first_name' => $name,
        'last_name' => 'Test',
        'employment_type' => 'permanent',
        'status' => 'active',
    ]);

    EmployeeAssignment::create(array_merge([
        'employee_id' => $employee->id,
        'office_id' => test()->office->id,
        'is_primary' => true,
        'effective_from' => $from,
    ], $placement));

    return $employee;
}

function schedPayload(array $overrides = []): array
{
    return array_merge([
        'scope_type' => 'business',
        'scope_id' => null,
        'schedule_type' => 'fixed',
        'shift_id' => test()->generalShift->id,
        'holiday_calendar_id' => test()->calendar->id,
        'effective_from' => '2026-01-01',
        'effective_to' => null,
        'note' => null,
    ], $overrides);
}

it('assigns a business-wide schedule scoped to the current business', function () {
    schedOwner()->post(route('hrm.schedules.store'), schedPayload())->assertRedirect();

    $assignment = ScheduleAssignment::sole();
    expect($assignment->business_id)->toBe($this->business->id);
    expect($assignment->scope_type)->toBe(ScheduleScope::Business);
    expect($assignment->scope_id)->toBeNull();
    expect($assignment->shift_id)->toBe($this->generalShift->id);
    expect($assignment->created_by)->toBe($this->owner->id);
});

it('resolves the most specific assignment covering an employee', function () {
    $employee = schedEmployee('Karim', [
        'department_id' => $this->department->id,
        'section_id' => $this->section->id,
    ]);
    $assign = app(AssignSchedule::class);

    $assign->handle(ScheduleScope::Business, null, schedPayload());
    expect(app(ScheduleResolver::class)->resolveFor($employee, '2026-06-01')->scope_type)
        ->toBe(ScheduleScope::Business);

    $assign->handle(ScheduleScope::Office, $this->office->id, schedPayload(['shift_id' => $this->nightShift->id]));
    expect(app(ScheduleResolver::class)->resolveFor($employee, '2026-06-01')->scope_type)
        ->toBe(ScheduleScope::Office);

    $assign->handle(ScheduleScope::Department, $this->department->id, schedPayload());
    expect(app(ScheduleResolver::class)->resolveFor($employee, '2026-06-01')->scope_type)
        ->toBe(ScheduleScope::Department);

    $assign->handle(ScheduleScope::Section, $this->section->id, schedPayload());
    expect(app(ScheduleResolver::class)->resolveFor($employee, '2026-06-01')->scope_type)
        ->toBe(ScheduleScope::Section);

    // The employee-level exception beats every group above it.
    $assign->handle(ScheduleScope::Employee, $employee->id, schedPayload(['shift_id' => $this->nightShift->id]));
    $winner = app(ScheduleResolver::class)->resolveFor($employee, '2026-06-01');
    expect($winner->scope_type)->toBe(ScheduleScope::Employee);
    expect($winner->shift_id)->toBe($this->nightShift->id);
});

it('gives a new hire their department schedule without any backfill', function () {
    app(AssignSchedule::class)->handle(
        ScheduleScope::Department,
        $this->department->id,
        schedPayload(['shift_id' => $this->nightShift->id])
    );

    // Hired months after the schedule was assigned; nobody touched the schedule.
    $newHire = schedEmployee('Rahim', ['department_id' => $this->department->id], '2026-05-01');

    $resolved = app(ScheduleResolver::class)->resolveFor($newHire, '2026-06-01');
    expect($resolved)->not->toBeNull();
    expect($resolved->shift_id)->toBe($this->nightShift->id);
    expect(ScheduleAssignment::count())->toBe(1);
});

it('resolves nothing before a schedule takes effect', function () {
    $employee = schedEmployee('Karim');
    app(AssignSchedule::class)->handle(ScheduleScope::Business, null, schedPayload(['effective_from' => '2026-03-01']));

    expect(app(ScheduleResolver::class)->resolveFor($employee, '2026-02-28'))->toBeNull();
    expect(app(ScheduleResolver::class)->resolveFor($employee, '2026-03-01'))->not->toBeNull();
});

it('closes the old assignment the day before the new one instead of editing it', function () {
    $assign = app(AssignSchedule::class);
    $first = $assign->handle(ScheduleScope::Business, null, schedPayload());
    $second = $assign->handle(ScheduleScope::Business, null, schedPayload([
        'shift_id' => $this->nightShift->id,
        'effective_from' => '2026-04-01',
    ]));

    expect($first->fresh()->effective_to->toDateString())->toBe('2026-03-31');
    expect($first->fresh()->shift_id)->toBe($this->generalShift->id); // history untouched
    expect($second->effective_to)->toBeNull();
    expect(ScheduleAssignment::count())->toBe(2);

    // Being closed by a successor is not the same as having been temporary.
    expect($first->fresh()->isTemporary())->toBeFalse();
});

it('restores the previous schedule after a temporary one ends', function () {
    $employee = schedEmployee('Karim');
    $assign = app(AssignSchedule::class);

    $assign->handle(ScheduleScope::Business, null, schedPayload());
    $temporary = $assign->handle(ScheduleScope::Business, null, schedPayload([
        'shift_id' => $this->ramadanShift->id,
        'effective_from' => '2026-03-01',
        'effective_to' => '2026-03-30',
        'note' => 'Ramadan hours 2026',
    ]));

    expect(ScheduleAssignment::count())->toBe(3);

    $restored = ScheduleAssignment::where('restores_assignment_id', $temporary->id)->sole();
    expect($restored->effective_from->toDateString())->toBe('2026-03-31');
    expect($restored->effective_to)->toBeNull();
    expect($restored->shift_id)->toBe($this->generalShift->id);
    expect($temporary->isTemporary())->toBeTrue();
    expect($restored->isTemporary())->toBeFalse();

    $resolver = app(ScheduleResolver::class);
    expect($resolver->resolveFor($employee, '2026-02-28')->shift_id)->toBe($this->generalShift->id);
    expect($resolver->resolveFor($employee, '2026-03-15')->shift_id)->toBe($this->ramadanShift->id);
    expect($resolver->resolveFor($employee, '2026-03-31')->shift_id)->toBe($this->generalShift->id);
});

it('does not invent a restore when a temporary schedule has no predecessor', function () {
    app(AssignSchedule::class)->handle(ScheduleScope::Business, null, schedPayload([
        'effective_from' => '2026-03-01',
        'effective_to' => '2026-03-30',
    ]));

    expect(ScheduleAssignment::count())->toBe(1);
});

it('refuses to assign in front of an already-scheduled future assignment', function () {
    $assign = app(AssignSchedule::class);
    $assign->handle(ScheduleScope::Business, null, schedPayload(['effective_from' => '2026-06-01']));

    expect(fn () => $assign->handle(ScheduleScope::Business, null, schedPayload(['effective_from' => '2026-04-01'])))
        ->toThrow(ValidationException::class);

    expect(ScheduleAssignment::count())->toBe(1);
});

it('keeps scopes independent when closing the old assignment', function () {
    $assign = app(AssignSchedule::class);
    $businessWide = $assign->handle(ScheduleScope::Business, null, schedPayload());
    $assign->handle(ScheduleScope::Office, $this->office->id, schedPayload(['effective_from' => '2026-04-01']));

    // Assigning an office schedule must not close the business-wide one.
    expect($businessWide->fresh()->effective_to)->toBeNull();
});

it('requires a shift for a fixed schedule but not for field duty', function () {
    schedOwner()->post(route('hrm.schedules.store'), schedPayload(['shift_id' => null]))
        ->assertSessionHasErrors('shift_id');

    schedOwner()->post(route('hrm.schedules.store'), schedPayload([
        'schedule_type' => ScheduleType::FieldDuty->value,
        'shift_id' => null,
    ]))->assertRedirect();

    expect(ScheduleAssignment::sole()->schedule_type)->toBe(ScheduleType::FieldDuty);
});

it('requires a target for every scope except the whole business', function () {
    schedOwner()->post(route('hrm.schedules.store'), schedPayload(['scope_type' => 'office', 'scope_id' => null]))
        ->assertSessionHasErrors('scope_id');
});

it('rejects a scope target belonging to another business', function () {
    app(BusinessContext::class)->set($this->otherBusiness->id);
    $foreignOffice = Office::create(['name' => 'Their Office', 'is_active' => true]);
    app(BusinessContext::class)->set($this->business->id);

    schedOwner()->post(route('hrm.schedules.store'), schedPayload([
        'scope_type' => 'office',
        'scope_id' => $foreignOffice->id,
    ]))->assertSessionHasErrors('scope_id');
});

it('ends an assignment without deleting its history', function () {
    $assignment = app(AssignSchedule::class)->handle(ScheduleScope::Business, null, schedPayload());

    schedOwner()->patch(route('hrm.schedules.end', $assignment->id), ['effective_to' => '2026-08-31'])
        ->assertRedirect();

    expect($assignment->fresh()->effective_to->toDateString())->toBe('2026-08-31');
    expect(ScheduleAssignment::count())->toBe(1);
});

it('refuses to delete an assignment that has already taken effect', function () {
    $assignment = app(AssignSchedule::class)->handle(
        ScheduleScope::Business,
        null,
        schedPayload(['effective_from' => now()->subMonth()->toDateString()])
    );

    schedOwner()->delete(route('hrm.schedules.destroy', $assignment->id))->assertRedirect();

    expect(ScheduleAssignment::find($assignment->id))->not->toBeNull();
});

it('removes an assignment that has not taken effect yet', function () {
    $assignment = app(AssignSchedule::class)->handle(
        ScheduleScope::Business,
        null,
        schedPayload(['effective_from' => now()->addMonth()->toDateString()])
    );

    schedOwner()->delete(route('hrm.schedules.destroy', $assignment->id))->assertRedirect();

    expect(ScheduleAssignment::find($assignment->id))->toBeNull();
});

it('renders the roster with the assignment that decided each schedule', function () {
    $employee = schedEmployee('Karim', ['department_id' => $this->department->id]);
    app(AssignSchedule::class)->handle(
        ScheduleScope::Department,
        $this->department->id,
        schedPayload(['shift_id' => $this->nightShift->id, 'effective_from' => now()->subMonth()->toDateString()])
    );

    schedOwner()->get(route('hrm.schedules.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('hrm/schedules/index')
            ->where('roster.0.name', $employee->fullName())
            ->where('roster.0.shift', 'Night')
            ->where('roster.0.source', 'Department: Production')
            ->has('assignments', 1)
            ->has('options.scopes', 5)
        );
});

it('names the business-wide scope once instead of repeating it', function () {
    $employee = schedEmployee('Karim');
    $businessWide = app(AssignSchedule::class)->handle(
        ScheduleScope::Business,
        null,
        schedPayload(['effective_from' => now()->subMonth()->toDateString()])
    );

    expect($businessWide->describeTarget())->toBe('Whole business');

    schedOwner()->get(route('hrm.schedules.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('roster.0.source', 'Whole business')
            ->where('assignments.0.target', null)
            ->where('assignments.0.scope', 'Whole business')
        );

    expect($employee->fresh())->not->toBeNull();
});

it('blocks a user without the scheduling permission', function () {
    $this->actingAs($this->staff)->withSession(['current_business_id' => $this->business->id])
        ->get(route('hrm.schedules.index'))->assertForbidden();

    $this->actingAs($this->staff)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.schedules.store'), schedPayload())->assertForbidden();
});

it('does not leak schedule assignments across businesses', function () {
    app(AssignSchedule::class)->handle(ScheduleScope::Business, null, schedPayload());

    app(BusinessContext::class)->set($this->otherBusiness->id);
    expect(ScheduleAssignment::count())->toBe(0);
});

it('rejects a shift or holiday calendar belonging to another business', function () {
    app(BusinessContext::class)->set($this->otherBusiness->id);
    $foreignShift = Shift::create([
        'name' => 'Their Shift', 'start_time' => '09:00', 'end_time' => '18:00',
        'grace_minutes' => 15, 'break_minutes' => 60, 'is_overnight' => false,
    ]);
    $foreignCalendar = HolidayCalendar::create([
        'name' => 'Their Calendar', 'year' => 2026, 'weekly_off_days' => [5], 'is_default' => true,
    ]);
    app(BusinessContext::class)->set($this->business->id);

    schedOwner()->post(route('hrm.schedules.store'), schedPayload([
        'shift_id' => $foreignShift->id,
        'holiday_calendar_id' => $foreignCalendar->id,
    ]))->assertSessionHasErrors(['shift_id', 'holiday_calendar_id']);

    expect(ScheduleAssignment::count())->toBe(0);
});
