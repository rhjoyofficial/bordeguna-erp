<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\AssignSchedule;
use App\Modules\Hrm\Actions\ProcessAttendance;
use App\Modules\Hrm\Actions\RecordPunch;
use App\Modules\Hrm\Attendance\Policy\PolicyConfig;
use App\Modules\Hrm\Enums\AttendanceConsequence;
use App\Modules\Hrm\Enums\PunchSource;
use App\Modules\Hrm\Enums\ScheduleScope;
use App\Modules\Hrm\Models\AttendancePolicy;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\HolidayCalendar;
use App\Modules\Hrm\Models\Shift;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The attendance policy screen (Joy, 24 Aug 2026: the POL-03 ladders become
 * adjustable data). The rule worth pinning hardest: a revision is a NEW
 * VERSION from a chosen date, and a day already judged keeps the version that
 * judged it - the screen must be unable to rewrite history.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Policy Co', 'slug' => 'policy-co']);
    app(BusinessContext::class)->set($this->business->id);

    foreach (['hrm.attendance.manage', 'hrm.scheduling.manage'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    foreach (['Owner', 'Staff'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }
    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()
        ->givePermissionTo(['hrm.attendance.manage', 'hrm.scheduling.manage']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->staffUser = User::factory()->create();
    $this->staffUser->businesses()->attach($this->business->id);
    $this->staffUser->assignRole('Staff');

    $this->office = Office::create(['name' => 'Head Office', 'is_active' => true]);
    $this->retailDept = Department::create(['name' => 'Retail Sales', 'office_id' => $this->office->id, 'is_active' => true]);

    // The two live shapes, verbatim - the round-trip tests below depend on
    // these matching what production actually stores.
    $this->officeConfig = [
        'grace_windows' => [
            ['key' => 'slightly_late', 'label' => 'Up to 15 minutes late', 'from_minutes' => 0, 'to_minutes' => 15,
                'tiers' => [['from_occurrence' => 1, 'consequence' => 'none'], ['from_occurrence' => 4, 'consequence' => 'deduction']]],
            ['key' => 'late_arrival', 'label' => '15 minutes to 3 hours late', 'from_minutes' => 15, 'to_minutes' => 180,
                'tiers' => [['from_occurrence' => 1, 'consequence' => 'none'], ['from_occurrence' => 3, 'consequence' => 'deduction']]],
            ['key' => 'after_midday', 'label' => 'More than 3 hours late', 'from_minutes' => 180, 'to_minutes' => null,
                'tiers' => [['from_occurrence' => 1, 'consequence' => 'deduction']], 'requires_review' => true],
        ],
        'full_day_minimum_hours' => 8,
        'half_day_maximum_hours' => 4,
        'early_leave_grace_minutes' => 15,
        'half_day_arrival_after_minutes' => null,
        'count_holiday_work_without_approval' => false,
    ];
    $this->retailConfig = [
        'grace_windows' => [
            ['key' => 'ordinary_late', 'label' => 'Ordinary late (08:15:01 to 08:29:59)', 'from_minutes' => 0, 'to_minutes' => 15,
                'tiers' => [['from_occurrence' => 1, 'consequence' => 'none'], ['from_occurrence' => 5, 'consequence' => 'disciplinary']]],
            ['key' => 'serious_late', 'label' => 'Serious late (08:30 or later, ritual disrupted)', 'from_minutes' => 15, 'to_minutes' => null,
                'tiers' => [['from_occurrence' => 1, 'consequence' => 'none'], ['from_occurrence' => 3, 'consequence' => 'disciplinary']]],
        ],
        'full_day_minimum_hours' => 8,
        'half_day_maximum_hours' => 4,
        'early_leave_grace_minutes' => 15,
        'duplicate_scan_window_seconds' => 300,
        'half_day_arrival_after_minutes' => null,
        'count_holiday_work_without_approval' => false,
    ];

    $this->default = AttendancePolicy::create([
        'name' => 'Bor De Guna attendance policy', 'version' => 1,
        'config' => $this->officeConfig, 'effective_from' => '2026-01-01',
    ]);
    $this->retail = AttendancePolicy::create([
        'name' => 'Retail Sales attendance policy', 'version' => 1, 'department_id' => $this->retailDept->id,
        'config' => $this->retailConfig, 'effective_from' => '2026-07-19',
    ]);
});

function policyOwner()
{
    return test()->actingAs(test()->owner)->withSession(['current_business_id' => test()->business->id]);
}

function policyStaff()
{
    return test()->actingAs(test()->staffUser)->withSession(['current_business_id' => test()->business->id]);
}

/**
 * The payload the form sends: the config's fields flattened, exactly as the
 * dialog builds them from the normalised config it was given.
 *
 * @param  array<string, mixed>  $config
 * @return array<string, mixed>
 */
function policyPayload(array $config, string $effectiveFrom): array
{
    $normalised = PolicyConfig::fromArray($config)->toArray();

    return [
        'effective_from' => $effectiveFrom,
        'early_leave_grace_minutes' => $normalised['early_leave_grace_minutes'],
        'half_day_maximum_hours' => $normalised['half_day_maximum_hours'],
        'full_day_minimum_hours' => $normalised['full_day_minimum_hours'],
        'half_day_arrival_after_minutes' => $normalised['half_day_arrival_after_minutes'],
        'count_holiday_work_without_approval' => $normalised['count_holiday_work_without_approval'],
        'duplicate_scan_window_seconds' => $normalised['duplicate_scan_window_seconds'],
        'grace_windows' => $normalised['grace_windows'],
    ];
}

it('shows both scopes with their ladders and history', function () {
    policyOwner()->get(route('hrm.policies.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('hrm/policies/index')
            ->has('scopes', 2)
            ->where('scopes.0.department', null)
            ->where('scopes.0.current.version', 1)
            ->has('scopes.0.current.config.grace_windows', 3)
            ->where('scopes.1.department', 'Retail Sales')
            ->has('scopes.1.current.config.grace_windows', 2)
            ->has('consequences', 5));
});

it('blocks a user without the attendance permission', function () {
    policyStaff()->get(route('hrm.policies.index'))->assertForbidden();
    policyStaff()->post(route('hrm.policies.revise', test()->default), policyPayload(test()->officeConfig, '2026-09-01'))
        ->assertForbidden();
});

it('saves a revision as a new version and leaves the judged history untouched', function () {
    $before = $this->default->config;

    $config = $this->officeConfig;
    $config['grace_windows'][0]['tiers'][1]['from_occurrence'] = 6; // deduction later

    policyOwner()->post(route('hrm.policies.revise', $this->default), policyPayload($config, '2026-09-01'))
        ->assertRedirect()->assertSessionHasNoErrors();

    $old = $this->default->fresh();
    $new = AttendancePolicy::where('name', $old->name)->whereNull('effective_to')->firstOrFail();

    expect($old->version)->toBe(1)
        ->and($old->effective_to->toDateString())->toBe('2026-08-31')
        ->and($old->config)->toBe($before)
        ->and($new->version)->toBe(2)
        ->and($new->department_id)->toBeNull()
        ->and($new->effective_from->toDateString())->toBe('2026-09-01')
        ->and($new->config['grace_windows'][0]['tiers'][1]['from_occurrence'])->toBe(6);
});

it('judges each day by the version in force on that day, through the real engine', function () {
    // A real employee with a schedule, punching 10 minutes late on a day
    // before the revision and a day after it.
    $shift = Shift::create(['name' => 'General', 'start_time' => '09:00', 'end_time' => '18:00',
        'grace_minutes' => 0, 'break_minutes' => 60, 'is_overnight' => false]);
    HolidayCalendar::create(['name' => 'BD', 'year' => 2026, 'weekly_off_days' => [5], 'is_default' => true]);
    $employee = Employee::create(['employee_code' => 'BDG-000001', 'first_name' => 'Karim', 'last_name' => 'Ahmed',
        'employment_type' => 'permanent', 'status' => 'active']);
    EmployeeAssignment::create(['employee_id' => $employee->id, 'office_id' => $this->office->id,
        'is_primary' => true, 'effective_from' => '2026-01-01']);
    app(AssignSchedule::class)->handle(ScheduleScope::Business, null, [
        'schedule_type' => 'fixed', 'shift_id' => $shift->id,
        'holiday_calendar_id' => HolidayCalendar::first()->id, 'effective_from' => '2026-01-01',
    ]);

    // The revision: first occurrence of even slight lateness deducts.
    $strict = $this->officeConfig;
    $strict['grace_windows'][0]['tiers'] = [['from_occurrence' => 1, 'consequence' => 'deduction']];

    policyOwner()->post(route('hrm.policies.revise', $this->default), policyPayload($strict, '2026-09-01'))
        ->assertRedirect()->assertSessionHasNoErrors();

    foreach (['2026-08-03', '2026-09-02'] as $date) {
        app(RecordPunch::class)->handle($employee, "$date 09:10:00", PunchSource::Biometric, ['direction' => null]);
        app(RecordPunch::class)->handle($employee, "$date 18:00:00", PunchSource::Biometric, ['direction' => null]);
    }

    $action = app(ProcessAttendance::class);
    $august = $action->forDay($employee, '2026-08-03');
    $september = $action->forDay($employee, '2026-09-02');

    expect($august->policy_version)->toBe(1)
        ->and($august->consequence)->toBe(AttendanceConsequence::None)
        ->and($september->policy_version)->toBe(2)
        ->and($september->consequence)->toBe(AttendanceConsequence::Deduction);
});

it('refuses the shapes that would make the ladder unreadable', function () {
    $overlap = $this->officeConfig;
    $overlap['grace_windows'][1]['from_minutes'] = 10; // starts inside window 1

    policyOwner()->post(route('hrm.policies.revise', $this->default), policyPayload($overlap, '2026-09-01'))
        ->assertSessionHasErrors('grace_windows');

    $lateStart = $this->officeConfig;
    $lateStart['grace_windows'][0]['tiers'] = [['from_occurrence' => 2, 'consequence' => 'none']];

    policyOwner()->post(route('hrm.policies.revise', $this->default), policyPayload($lateStart, '2026-09-01'))
        ->assertSessionHasErrors('grace_windows');

    $unordered = $this->officeConfig;
    $unordered['grace_windows'][0]['tiers'] = [
        ['from_occurrence' => 1, 'consequence' => 'none'],
        ['from_occurrence' => 1, 'consequence' => 'deduction'],
    ];

    policyOwner()->post(route('hrm.policies.revise', $this->default), policyPayload($unordered, '2026-09-01'))
        ->assertSessionHasErrors('grace_windows');

    $halfOverFull = policyPayload($this->officeConfig, '2026-09-01');
    $halfOverFull['half_day_maximum_hours'] = 9;

    policyOwner()->post(route('hrm.policies.revise', $this->default), $halfOverFull)
        ->assertSessionHasErrors('full_day_minimum_hours');

    policyOwner()->post(route('hrm.policies.revise', $this->default), policyPayload($this->officeConfig, '2026-01-01'))
        ->assertSessionHasErrors('effective_from');

    // Nothing slipped through sideways.
    expect(AttendancePolicy::count())->toBe(2);
});

it('refuses to revise a closed historical version', function () {
    policyOwner()->post(route('hrm.policies.revise', $this->default), policyPayload($this->officeConfig, '2026-09-01'))
        ->assertRedirect()->assertSessionHasNoErrors();

    policyOwner()->post(route('hrm.policies.revise', $this->default->fresh()), policyPayload($this->officeConfig, '2026-10-01'))
        ->assertRedirect()->assertSessionHas('error');

    expect(AttendancePolicy::count())->toBe(3);
});

it('round-trips both live config shapes through an unedited save, behaviour-identical', function () {
    foreach ([[$this->default, $this->officeConfig, '2026-09-01'], [$this->retail, $this->retailConfig, '2026-09-01']] as [$policy, $config, $from]) {
        policyOwner()->post(route('hrm.policies.revise', $policy), policyPayload($config, $from))
            ->assertRedirect()->assertSessionHasNoErrors();

        $new = AttendancePolicy::where('name', $policy->name)->whereNull('effective_to')->firstOrFail();

        // Behaviour equality through the engine's own value object - the only
        // equality that matters to a day being judged.
        expect(PolicyConfig::fromArray($new->config)->toArray())
            ->toBe(PolicyConfig::fromArray($config)->toArray());
    }

    // The retail row's explicit 300-second scan window survived (C18's note:
    // a row that stored 300 keeps 300).
    $retailNew = AttendancePolicy::where('department_id', $this->retailDept->id)->whereNull('effective_to')->first();
    expect($retailNew->config['duplicate_scan_window_seconds'])->toBe(300);
});
