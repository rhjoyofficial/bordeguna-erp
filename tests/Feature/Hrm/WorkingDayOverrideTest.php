<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\AssignSchedule;
use App\Modules\Hrm\Actions\ProcessAttendance;
use App\Modules\Hrm\Actions\RecordPunch;
use App\Modules\Hrm\database\seeders\AttendancePolicySeeder;
use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Enums\LeaveDayPortion;
use App\Modules\Hrm\Enums\PunchSource;
use App\Modules\Hrm\Enums\ScheduleScope;
use App\Modules\Hrm\Models\AttendancePolicy;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\Holiday;
use App\Modules\Hrm\Models\HolidayCalendar;
use App\Modules\Hrm\Models\Shift;
use App\Modules\Hrm\Models\WorkingDayOverride;
use App\Modules\Hrm\Support\LeaveDayCalculator;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The working-day override: one date that IS a working day despite the
 * weekly-off pattern or a dated holiday. Born from Friday 7 August 2026,
 * which the office worked - and which had no row to delete, because Fridays
 * are a pattern, not rows.
 *
 * The rule this file enforces: attendance and leave charging must give the
 * SAME answer about what a working day is, and an override makes the date an
 * ORDINARY working day - lateness, absence proposals and all.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Override Co', 'slug' => 'override-co']);
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

    AttendancePolicy::create([
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

    $this->override = fn (string $date, string $reason = 'Worked day') => WorkingDayOverride::create([
        'holiday_calendar_id' => $this->calendar->id,
        'date' => $date,
        'reason' => $reason,
    ]);

    $this->punch = function (string $at) {
        app(RecordPunch::class)->handle(
            $this->employee, $at, PunchSource::Biometric,
            ['device_identifier' => 'K40-1', 'direction' => null],
        );
    };

    $this->asOwner = fn () => $this->actingAs($this->owner)->withSession(['current_business_id' => $this->business->id]);
});

it('turns an overridden Friday into an ordinary working day, judged like any other', function () {
    expect(Carbon::parse('2026-08-07')->dayOfWeek)->toBe(5);

    ($this->override)('2026-08-07', 'Friday 7 Aug was worked');

    ($this->punch)('2026-08-07 09:00:00');
    ($this->punch)('2026-08-07 18:00:00');

    $day = app(ProcessAttendance::class)->forDay($this->employee, '2026-08-07');

    expect($day->status)->toBe(AttendanceStatus::Present);
    expect($day->worked_minutes)->toBe(540);
});

it('proposes Absent on an overridden day nobody attended - that is what working day means', function () {
    ($this->override)('2026-08-07');

    $day = app(ProcessAttendance::class)->forDay($this->employee, '2026-08-07');

    expect($day->status)->toBe(AttendanceStatus::Absent);
    expect($day->is_provisional)->toBeTrue();
});

it('leaves every other Friday alone', function () {
    ($this->override)('2026-08-07');

    $day = app(ProcessAttendance::class)->forDay($this->employee, '2026-08-14');

    expect($day->status)->toBe(AttendanceStatus::WeeklyOff);
});

it('beats a dated holiday as well as the weekly pattern', function () {
    Holiday::create([
        'holiday_calendar_id' => $this->calendar->id,
        'date' => '2026-08-04',
        'name' => 'Some Holiday',
    ]);
    ($this->override)('2026-08-04', 'Holiday cancelled, office worked');

    ($this->punch)('2026-08-04 09:00:00');
    ($this->punch)('2026-08-04 18:00:00');

    $day = app(ProcessAttendance::class)->forDay($this->employee, '2026-08-04');

    expect($day->status)->toBe(AttendanceStatus::Present);
});

it('charges leave over an overridden Friday, so leave and attendance agree', function () {
    $calculator = app(LeaveDayCalculator::class);

    // Thursday 6th to Saturday 8th: the Friday between is normally free.
    $before = $calculator->calculate($this->employee, '2026-08-06', '2026-08-08', LeaveDayPortion::FullDay);
    expect($before['days'])->toBe(2.0)
        ->and($before['dates'])->not->toContain('2026-08-07');

    ($this->override)('2026-08-07');

    $after = $calculator->calculate($this->employee, '2026-08-06', '2026-08-08', LeaveDayPortion::FullDay);
    expect($after['days'])->toBe(3.0)
        ->and($after['dates'])->toContain('2026-08-07');
});

it('adds and removes overrides from the calendar screen endpoints', function () {
    ($this->asOwner)()
        ->post(route('hrm.calendars.working-days.store', $this->calendar->id), [
            'date' => '2026-08-07',
            'reason' => 'Friday 7 Aug was worked',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $override = $this->calendar->workingDayOverrides()->sole();
    expect($override->date->toDateString())->toBe('2026-08-07');

    ($this->asOwner)()
        ->delete(route('hrm.calendars.working-days.destroy', [$this->calendar->id, $override->id]))
        ->assertRedirect();

    expect($this->calendar->workingDayOverrides()->count())->toBe(0);
});

it('refuses a duplicate override and an override on a date that is already working', function () {
    ($this->override)('2026-08-07');

    ($this->asOwner)()
        ->from(route('hrm.calendars.index'))
        ->post(route('hrm.calendars.working-days.store', $this->calendar->id), [
            'date' => '2026-08-07',
            'reason' => 'Again',
        ])
        ->assertSessionHasErrors('date');

    // Monday 3 Aug is an ordinary working day: an override there is a no-op
    // that would only teach the operator the button does nothing.
    ($this->asOwner)()
        ->from(route('hrm.calendars.index'))
        ->post(route('hrm.calendars.working-days.store', $this->calendar->id), [
            'date' => '2026-08-03',
            'reason' => 'Pointless',
        ])
        ->assertSessionHasErrors('date');
});

it('needs the scheduling permission', function () {
    $this->actingAs($this->staff)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.calendars.working-days.store', $this->calendar->id), [
            'date' => '2026-08-07',
            'reason' => 'No',
        ])
        ->assertForbidden();
});

it('makes an exempt employee Present through the real pipeline on a punchless working day', function () {
    // The employee-scoped Exempt assignment (precedence 4) overrides the
    // business-wide fixed schedule - the same mechanism that fixed Shoykod.
    app(AssignSchedule::class)->handle(ScheduleScope::Employee, $this->employee->id, [
        'schedule_type' => 'exempt',
        'shift_id' => null,
        'holiday_calendar_id' => $this->calendar->id,
        'effective_from' => '2026-08-01',
    ]);

    $working = app(ProcessAttendance::class)->forDay($this->employee, '2026-08-03');
    $friday = app(ProcessAttendance::class)->forDay($this->employee, '2026-08-14');

    expect($working->status)->toBe(AttendanceStatus::Present)
        ->and($working->is_provisional)->toBeFalse()
        ->and($working->holds_payroll)->toBeFalse()
        ->and($friday->status)->toBe(AttendanceStatus::WeeklyOff);
});
