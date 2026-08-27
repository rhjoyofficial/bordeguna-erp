<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\AssignSchedule;
use App\Modules\Hrm\Actions\ProcessAttendance;
use App\Modules\Hrm\Actions\RecordPunch;
use App\Modules\Hrm\database\seeders\AttendancePolicySeeder;
use App\Modules\Hrm\Enums\AttendanceConsequence;
use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Enums\PunchSource;
use App\Modules\Hrm\Enums\ScheduleScope;
use App\Modules\Hrm\Models\AttendancePolicy;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\Holiday;
use App\Modules\Hrm\Models\HolidayCalendar;
use App\Modules\Hrm\Models\Shift;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->business = Business::create(['name' => 'Proc Co', 'slug' => 'proc-co']);
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

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');

    $this->office = Office::create(['name' => 'Head Office', 'is_active' => true]);

    $this->shift = Shift::create([
        'name' => 'General', 'start_time' => '09:00', 'end_time' => '18:00',
        'grace_minutes' => 15, 'break_minutes' => 60, 'is_overnight' => false,
    ]);

    $this->calendar = HolidayCalendar::create([
        'name' => 'Bangladesh', 'year' => 2026, 'weekly_off_days' => [5], 'is_default' => true,
    ]);

    $this->policy = AttendancePolicy::create([
        'name' => 'Standard attendance policy',
        'version' => 1,
        'config' => AttendancePolicySeeder::defaultConfig(),
        'effective_from' => '2026-01-01',
    ]);

    $this->employee = Employee::create([
        'employee_code' => 'BDG-000001',
        'first_name' => 'Karim', 'last_name' => 'Ahmed',
        'employment_type' => 'permanent', 'status' => 'active',
    ]);
    EmployeeAssignment::create([
        'employee_id' => $this->employee->id,
        'office_id' => $this->office->id,
        'is_primary' => true,
        'effective_from' => '2026-01-01',
    ]);

    app(AssignSchedule::class)->handle(ScheduleScope::Business, null, [
        'schedule_type' => 'fixed',
        'shift_id' => $this->shift->id,
        'holiday_calendar_id' => $this->calendar->id,
        'effective_from' => '2026-01-01',
    ]);
});

function procOwner()
{
    return test()->actingAs(test()->owner)->withSession(['current_business_id' => test()->business->id]);
}

function procPunch(string $at, ?string $direction = null): void
{
    app(RecordPunch::class)->handle(
        test()->employee,
        $at,
        PunchSource::Biometric,
        ['device_identifier' => 'K40-1', 'direction' => $direction],
    );
}

it('resolves the schedule and calendar through the real pipeline', function () {
    procPunch('2026-08-03 09:00:00');
    procPunch('2026-08-03 18:00:00');

    $day = app(ProcessAttendance::class)->forDay($this->employee, '2026-08-03');

    expect($day->status)->toBe(AttendanceStatus::Present);
    expect($day->worked_minutes)->toBe(540);
    expect($day->payable_minutes)->toBe(480);
    expect($day->shift_id)->toBe($this->shift->id);
});

it('reads the weekly off day from the assigned calendar', function () {
    // 2026-08-07 is a Friday, the seeded Bangladesh weekly off.
    expect(Carbon::parse('2026-08-07')->dayOfWeek)->toBe(5);

    $day = app(ProcessAttendance::class)->forDay($this->employee, '2026-08-07');

    expect($day->status)->toBe(AttendanceStatus::WeeklyOff);
});

it('reads a dated holiday from the assigned calendar', function () {
    Holiday::create([
        'holiday_calendar_id' => $this->calendar->id,
        'date' => '2026-08-04',
        'name' => 'National Mourning Day',
    ]);

    $day = app(ProcessAttendance::class)->forDay($this->employee, '2026-08-04');

    expect($day->status)->toBe(AttendanceStatus::Holiday);
    expect(implode(' ', $day->calculation['notes']))->toContain('National Mourning Day');
});

it('escalates the POL-03 ladder across a real month of punches', function () {
    // Five separate days arriving 09:10, each inside the grace band.
    $days = ['2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06', '2026-08-10'];

    foreach ($days as $date) {
        procPunch("{$date} 09:10:00");
        procPunch("{$date} 18:00:00");
    }

    app(ProcessAttendance::class)->forRange($this->employee, '2026-08-01', '2026-08-10');

    $results = DailyAttendance::where('employee_id', $this->employee->id)
        ->orderBy('attendance_date')
        ->get()
        ->filter(fn (DailyAttendance $day) => in_array($day->attendance_date->toDateString(), $days, true))
        ->values();

    expect($results->pluck('occurrence_number')->all())->toBe([1, 2, 3, 4, 5]);
    expect($results->pluck('consequence')->all())->toBe([
        AttendanceConsequence::None,
        AttendanceConsequence::None,
        AttendanceConsequence::None,
        AttendanceConsequence::Warning,
        AttendanceConsequence::Deduction,
    ]);
});

it('resets the occurrence counter at the start of a new month', function () {
    foreach (['2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06'] as $date) {
        procPunch("{$date} 09:10:00");
        procPunch("{$date} 18:00:00");
    }
    procPunch('2026-09-01 09:10:00');
    procPunch('2026-09-01 18:00:00');

    app(ProcessAttendance::class)->forRange($this->employee, '2026-08-01', '2026-09-01');

    $august = DailyAttendance::where('employee_id', $this->employee->id)->onDate('2026-08-06')->sole();
    $september = DailyAttendance::where('employee_id', $this->employee->id)->onDate('2026-09-01')->sole();

    expect($august->occurrence_number)->toBe(4);
    expect($september->occurrence_number)->toBe(1);
    expect($september->consequence)->toBe(AttendanceConsequence::None);
});

it('does not count the day being reprocessed towards its own occurrence number', function () {
    procPunch('2026-08-03 09:10:00');
    procPunch('2026-08-03 18:00:00');

    $action = app(ProcessAttendance::class);
    $action->forDay($this->employee, '2026-08-03');
    $again = $action->forDay($this->employee, '2026-08-03');

    expect($again->occurrence_number)->toBe(1);
});

it('stores the policy version that judged the day', function () {
    procPunch('2026-08-03 09:00:00');
    procPunch('2026-08-03 18:00:00');

    $day = app(ProcessAttendance::class)->forDay($this->employee, '2026-08-03');

    expect($day->policy_id)->toBe($this->policy->id);
    expect($day->policy_version)->toBe(1);
});

it('judges a day by the policy in force on that date, not the newest one', function () {
    // POL-12: a revision applies prospectively only.
    $this->policy->update(['effective_to' => '2026-08-31']);

    $strict = AttendancePolicySeeder::defaultConfig();
    $strict['grace_windows'][0]['tiers'] = [['from_occurrence' => 1, 'consequence' => 'deduction']];

    AttendancePolicy::create([
        'name' => 'Stricter policy',
        'version' => 2,
        'config' => $strict,
        'effective_from' => '2026-09-01',
    ]);

    foreach (['2026-08-03', '2026-09-02'] as $date) {
        procPunch("{$date} 09:10:00");
        procPunch("{$date} 18:00:00");
    }

    $action = app(ProcessAttendance::class);
    $august = $action->forDay($this->employee, '2026-08-03');
    $september = $action->forDay($this->employee, '2026-09-02');

    expect($august->policy_version)->toBe(1);
    expect($august->consequence)->toBe(AttendanceConsequence::None);

    expect($september->policy_version)->toBe(2);
    expect($september->consequence)->toBe(AttendanceConsequence::Deduction);
});

it('stores the derivation so every figure can be explained later', function () {
    procPunch('2026-08-03 09:10:00');
    procPunch('2026-08-03 18:00:00');

    $day = app(ProcessAttendance::class)->forDay($this->employee, '2026-08-03');

    expect($day->calculation)->toHaveKeys(['notes', 'flags', 'pairs', 'occurrence_number']);
    expect(implode(' ', $day->calculation['notes']))->toContain('after the General start of 09:00');
    expect($day->calculation['pairs'][0]['minutes'])->toBe(530);
});

it('recalculates without ever touching the raw punches', function () {
    procPunch('2026-08-03 09:00:00');
    procPunch('2026-08-03 18:00:00');

    $action = app(ProcessAttendance::class);
    $action->forDay($this->employee, '2026-08-03');

    $snapshot = fn () => \App\Modules\Hrm\Models\AttendancePunch::orderBy('id')->get()
        ->map(fn ($punch) => [
            'id' => $punch->id,
            'punched_at' => $punch->punched_at->format('Y-m-d H:i:s'),
            'dedup_key' => $punch->dedup_key,
            'updated_at' => $punch->updated_at?->format('Y-m-d H:i:s'),
        ])->all();

    $before = $snapshot();

    $action->forDay($this->employee, '2026-08-03');

    expect($snapshot())->toBe($before);
    expect(DailyAttendance::count())->toBe(1);
});

it('keeps a finalised day from being silently recalculated', function () {
    procPunch('2026-08-03 09:00:00');
    procPunch('2026-08-03 18:00:00');

    $action = app(ProcessAttendance::class);
    $day = $action->forDay($this->employee, '2026-08-03');
    $day->update(['is_provisional' => false, 'finalized_at' => now(), 'finalized_by' => $this->owner->id]);

    expect($action->forDay($this->employee, '2026-08-03'))->toBeNull();

    // Only an explicit force reopens it.
    $forced = $action->forDay($this->employee, '2026-08-03', force: true);
    expect($forced)->not->toBeNull();
    expect($forced->finalized_at)->toBeNull();
});

it('processes a whole date for every employee from the screen', function () {
    procPunch('2026-08-03 09:00:00');
    procPunch('2026-08-03 18:00:00');

    procOwner()->post(route('hrm.attendance.daily.process'), ['on' => '2026-08-03'])->assertRedirect();

    expect(DailyAttendance::onDate('2026-08-03')->count())->toBe(1);
});

it('finalises a provisional day but refuses one holding payroll', function () {
    // A lone punch never closes its pair — POL-07 holds payroll.
    procPunch('2026-08-03 09:00:00');
    procPunch('2026-08-04 09:00:00');
    procPunch('2026-08-04 18:00:00');

    $action = app(ProcessAttendance::class);
    $held = $action->forDay($this->employee, '2026-08-03');
    $clean = $action->forDay($this->employee, '2026-08-04');

    expect($held->holds_payroll)->toBeTrue();

    procOwner()->post(route('hrm.attendance.daily.finalize'), ['on' => '2026-08-03'])->assertRedirect();
    expect($held->fresh()->is_provisional)->toBeTrue();

    // A clean day with no exception is not provisional to begin with.
    expect($clean->is_provisional)->toBeFalse();
});

it('finalises a provisional absent day once a human reviews it', function () {
    $day = app(ProcessAttendance::class)->forDay($this->employee, '2026-08-03');

    expect($day->status)->toBe(AttendanceStatus::Absent);
    expect($day->is_provisional)->toBeTrue();

    procOwner()->post(route('hrm.attendance.daily.finalize'), ['on' => '2026-08-03', 'ids' => [$day->id]])
        ->assertRedirect();

    $day->refresh();
    expect($day->is_provisional)->toBeFalse();
    expect($day->finalized_by)->toBe($this->owner->id);
});

it('renders the daily grid with its explanation', function () {
    procPunch('2026-08-03 09:10:00');
    procPunch('2026-08-03 18:00:00');
    app(ProcessAttendance::class)->forDay($this->employee, '2026-08-03');

    procOwner()->get(route('hrm.attendance.daily.index', ['on' => '2026-08-03']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('hrm/attendance/daily')
            ->where('days.0.status', 'Late')
            ->where('days.0.occurrence_number', 1)
            ->where('policy.version', 1)
            ->has('days.0.notes')
        );
});

it('blocks a user without the attendance permission', function () {
    $this->actingAs($this->staff)->withSession(['current_business_id' => $this->business->id])
        ->get(route('hrm.attendance.daily.index'))->assertForbidden();

    $this->actingAs($this->staff)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.attendance.daily.process'), ['on' => '2026-08-03'])->assertForbidden();
});

// =============================================================================
// Department-scoped policy — Bor de Guna's Retail circular BdG-HQ-OD-26071901
// binds one department to an earlier deadline and a show-cause ladder, and must
// not reach the office staff it never mentions.
// =============================================================================

it('judges a department by its own policy and everyone else by the business default', function () {
    $retail = Department::create(['name' => 'Retail Sales Team', 'office_id' => $this->office->id]);

    // Same bands as the default, but the consequence is disciplinary rather than
    // a deduction — which is exactly what the circular promises.
    $config = AttendancePolicySeeder::defaultConfig();
    $config['grace_windows'] = [[
        'key' => 'ordinary_late', 'label' => 'Ordinary late', 'from_minutes' => 0, 'to_minutes' => 15,
        'tiers' => [['from_occurrence' => 1, 'consequence' => 'disciplinary']],
    ]];

    AttendancePolicy::create([
        'department_id' => $retail->id,
        'name' => 'Retail Sales attendance policy',
        'version' => 1,
        'config' => $config,
        'effective_from' => '2026-01-01',
    ]);

    $this->employee->assignments()->update(['department_id' => $retail->id]);

    procPunch('2026-08-03 09:10:00');
    procPunch('2026-08-03 18:00:00');
    app(ProcessAttendance::class)->forDay($this->employee->fresh(), '2026-08-03');

    $day = DailyAttendance::where('employee_id', $this->employee->id)->onDate('2026-08-03')->first();

    expect($day->consequence)->toBe(AttendanceConsequence::Disciplinary)
        ->and($day->grace_window_key)->toBe('ordinary_late');

    // A colleague outside the department keeps the business-wide ladder, where
    // the first occurrence in the same band is excused.
    $officeStaff = Employee::create([
        'employee_code' => 'BDG-000002',
        'first_name' => 'Rahim', 'last_name' => 'Uddin',
        'employment_type' => 'permanent', 'status' => 'active',
    ]);
    EmployeeAssignment::create([
        'employee_id' => $officeStaff->id,
        'office_id' => $this->office->id,
        'is_primary' => true,
        'effective_from' => '2026-01-01',
    ]);

    app(RecordPunch::class)->handle($officeStaff, '2026-08-03 09:10:00', PunchSource::Biometric, []);
    app(RecordPunch::class)->handle($officeStaff, '2026-08-03 18:00:00', PunchSource::Biometric, []);
    app(ProcessAttendance::class)->forDay($officeStaff, '2026-08-03');

    $otherDay = DailyAttendance::where('employee_id', $officeStaff->id)->onDate('2026-08-03')->first();

    expect($otherDay->consequence)->toBe(AttendanceConsequence::None)
        ->and($otherDay->grace_window_key)->not->toBe('ordinary_late');
});

it('reads the department as of the day being judged, not as of today', function () {
    $retail = Department::create(['name' => 'Retail Sales Team', 'office_id' => $this->office->id]);

    $config = AttendancePolicySeeder::defaultConfig();
    $config['grace_windows'] = [[
        'key' => 'ordinary_late', 'label' => 'Ordinary late', 'from_minutes' => 0, 'to_minutes' => 15,
        'tiers' => [['from_occurrence' => 1, 'consequence' => 'disciplinary']],
    ]];

    AttendancePolicy::create([
        'department_id' => $retail->id,
        'name' => 'Retail Sales attendance policy',
        'version' => 1,
        'config' => $config,
        'effective_from' => '2026-01-01',
    ]);

    // He transfers into Retail on 10 August. The 3rd is before that, so the day
    // must still be judged by the policy that governed him when he lived it.
    $this->employee->assignments()->update(['effective_to' => '2026-08-09']);
    EmployeeAssignment::create([
        'employee_id' => $this->employee->id,
        'office_id' => $this->office->id,
        'department_id' => $retail->id,
        'is_primary' => true,
        'effective_from' => '2026-08-10',
    ]);

    procPunch('2026-08-03 09:10:00');
    procPunch('2026-08-03 18:00:00');
    app(ProcessAttendance::class)->forDay($this->employee->fresh(), '2026-08-03');

    $day = DailyAttendance::where('employee_id', $this->employee->id)->onDate('2026-08-03')->first();

    expect($day->consequence)->toBe(AttendanceConsequence::None);
});

it('shows a whole range of processed days in date order', function () {
    // ⚠⚠ Regression guard. The first version of this range used whereBetween on
    // date strings, which returns NOTHING against a date-cast column stored with
    // a time component, and returns it silently — an empty grid reads exactly
    // like "nothing has been processed". Sixth appearance of that trap here.
    foreach (['2026-08-03', '2026-08-04', '2026-08-06'] as $date) {
        procPunch("{$date} 09:00:00");
        procPunch("{$date} 18:00:00");
    }
    app(ProcessAttendance::class)->forRange($this->employee, '2026-08-03', '2026-08-06');

    procOwner()->get(route('hrm.attendance.daily.index', ['from' => '2026-08-03', 'to' => '2026-08-06']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('days.0.date', '2026-08-03')
            ->where('days.3.date', '2026-08-06')
            ->where('filters.from', '2026-08-03')
            ->where('filters.to', '2026-08-06')
        );
});

it('filters the grid down to the days that are holding payroll', function () {
    procPunch('2026-08-03 09:00:00');
    procPunch('2026-08-03 18:00:00');
    procPunch('2026-08-04 09:00:00');  // no clock-out: this is the held day
    app(ProcessAttendance::class)->forRange($this->employee, '2026-08-03', '2026-08-04');

    procOwner()->get(route('hrm.attendance.daily.index', ['from' => '2026-08-03', 'to' => '2026-08-04', 'holds' => true]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('days', 1)
            ->where('days.0.date', '2026-08-04')
            ->where('days.0.holds_payroll', true)
        );
});
