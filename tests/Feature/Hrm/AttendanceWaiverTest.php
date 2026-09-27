<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\AssignSchedule;
use App\Modules\Hrm\Actions\ProcessAttendance;
use App\Modules\Hrm\database\seeders\AttendancePolicySeeder;
use App\Modules\Hrm\Enums\AttendanceConsequence;
use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Enums\ScheduleScope;
use App\Modules\Hrm\Models\AttendancePolicy;
use App\Modules\Hrm\Models\AttendanceWaiver;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\HolidayCalendar;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\Shift;
use App\Modules\Hrm\Payroll\PayrollConfig;
use App\Modules\Hrm\Payroll\PayrollContextBuilder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Forgiving one day's salary consequence (Joy, 24 Aug 2026).
 *
 * The rules worth pinning: forgiveness changes PRICING and nothing else - the
 * day's verdict survives on every screen, presence never appears out of thin
 * air, reprocessing cannot erase a waiver, and the power to forgive money is
 * held apart from the power to manage attendance.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Waiver Co', 'slug' => 'waiver-co']);
    app(BusinessContext::class)->set($this->business->id);

    foreach (['hrm.attendance.manage', 'hrm.attendance.waive'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['hrm.attendance.manage', 'hrm.attendance.waive']);
    // Records attendance but may not move money - the separation under test.
    Role::firstOrCreate(['name' => 'Recorder', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo('hrm.attendance.manage');
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->recorder = User::factory()->create();
    $this->recorder->businesses()->attach($this->business->id);
    $this->recorder->assignRole('Recorder');

    $this->office = Office::create(['name' => 'Head Office', 'is_active' => true]);
    $this->shift = Shift::create([
        'name' => 'General', 'start_time' => '09:00', 'end_time' => '18:00',
        'grace_minutes' => 15, 'break_minutes' => 60, 'is_overnight' => false,
    ]);
    $this->calendar = HolidayCalendar::create([
        'name' => 'Bangladesh', 'year' => 2026, 'weekly_off_days' => [5], 'is_default' => true,
    ]);
    AttendancePolicy::create([
        'name' => 'Standard', 'version' => 1,
        'config' => AttendancePolicySeeder::defaultConfig(),
        'effective_from' => '2026-01-01',
    ]);
    app(AssignSchedule::class)->handle(ScheduleScope::Business, null, [
        'schedule_type' => 'fixed',
        'shift_id' => $this->shift->id,
        'holiday_calendar_id' => $this->calendar->id,
        'effective_from' => '2025-01-01',
    ]);

    $this->employee = Employee::create([
        'employee_code' => 'BDG-000001',
        'first_name' => 'Karim', 'last_name' => 'Ahmed',
        'employment_type' => 'permanent', 'status' => 'active',
    ]);
    EmployeeAssignment::create([
        'employee_id' => $this->employee->id,
        'office_id' => $this->office->id,
        'is_primary' => true, 'effective_from' => '2025-01-01',
    ]);

    $this->period = PayrollPeriod::create([
        'business_id' => $this->business->id, 'year' => 2026, 'month' => 8,
        'start_date' => '2026-07-24', 'end_date' => '2026-08-23', 'divisor_days' => 30,
    ]);

    $this->facts = fn () => app(PayrollContextBuilder::class)
        ->build($this->employee->fresh(), $this->period, PayrollConfig::defaults())
        ->attendance;

    // 2026-08-03 is a Monday with no punches: the engine's own honest Absent.
    $this->absentDay = fn () => app(ProcessAttendance::class)->forDay($this->employee, '2026-08-03', force: true);
});

function waiverOwner()
{
    return test()->actingAs(test()->owner)->withSession(['current_business_id' => test()->business->id]);
}

function waiverRecorder()
{
    return test()->actingAs(test()->recorder)->withSession(['current_business_id' => test()->business->id]);
}

it('removes a forgiven absent day from payroll pricing and from nothing else', function () {
    ($this->absentDay)();

    $before = ($this->facts)();
    expect($before->absentDays)->toBe(1.0);

    waiverOwner()->post(route('hrm.attendance.waivers.store'), [
        'employee_id' => $this->employee->id,
        'attendance_date' => '2026-08-03',
        'reason' => 'Biometric device was down; owner accepts the day.',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $after = ($this->facts)();

    expect($after->absentDays)->toBe(0.0)
        // Forgiving the deduction does not invent attendance: the day joins
        // neither presentDays nor daysReportedForWork.
        ->and($after->presentDays)->toBe($before->presentDays)
        ->and($after->daysReportedForWork)->toBe($before->daysReportedForWork)
        // And the day's own verdict is untouched.
        ->and(DailyAttendance::where('employee_id', $this->employee->id)->onDate('2026-08-03')->first()->status)
        ->toBe(AttendanceStatus::Absent);
});

it('removes a forgiven late-deduction day from deductibleDays', function () {
    // Payroll reads stored conclusions, so the fact is created as Attendance
    // would have stored it: a late day whose ladder tier reached deduction.
    DailyAttendance::create([
        'business_id' => $this->business->id,
        'employee_id' => $this->employee->id,
        'attendance_date' => '2026-08-04',
        'status' => AttendanceStatus::Late,
        'consequence' => AttendanceConsequence::Deduction,
        'worked_minutes' => 400, 'late_minutes' => 45, 'occurrence_number' => 3,
        'is_provisional' => false, 'holds_payroll' => false,
    ]);

    expect(($this->facts)()->deductibleDays)->toBe(1.0);

    waiverOwner()->post(route('hrm.attendance.waivers.store'), [
        'employee_id' => $this->employee->id,
        'attendance_date' => '2026-08-04',
        'reason' => 'Late arrivals were office-caused that week.',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(($this->facts)()->deductibleDays)->toBe(0.0)
        ->and(($this->facts)()->lateOccurrences)->toBe(1)
        ->and(($this->facts)()->lateMinutes)->toBe(45);
});

it('restores the deduction on undo, and keeps the whole story on record', function () {
    ($this->absentDay)();

    waiverOwner()->post(route('hrm.attendance.waivers.store'), [
        'employee_id' => $this->employee->id,
        'attendance_date' => '2026-08-03',
        'reason' => 'Forgiven in error.',
    ])->assertRedirect();

    $waiver = AttendanceWaiver::first();

    waiverOwner()->post(route('hrm.attendance.waivers.revoke', $waiver))
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(($this->facts)()->absentDays)->toBe(1.0)
        // Revoked, not deleted: forgiveness granted and withdrawn both stay.
        ->and($waiver->fresh()->revoked_at)->not->toBeNull()
        ->and($waiver->fresh()->revoked_by)->toBe($this->owner->id)
        ->and(AttendanceWaiver::count())->toBe(1);
});

it('survives a force reprocess of the day it forgives', function () {
    ($this->absentDay)();

    waiverOwner()->post(route('hrm.attendance.waivers.store'), [
        'employee_id' => $this->employee->id,
        'attendance_date' => '2026-08-03',
        'reason' => 'Device outage day.',
    ])->assertRedirect();

    // The engine rewrites the day from raw punches; the waiver keys on the
    // DAY, not the row, so a rebuild cannot orphan it.
    ($this->absentDay)();

    expect(AttendanceWaiver::activeFor($this->employee->id, '2026-08-03'))->not->toBeNull()
        ->and(($this->facts)()->absentDays)->toBe(0.0);
});

it('holds forgiving money apart from managing attendance', function () {
    ($this->absentDay)();

    waiverRecorder()->post(route('hrm.attendance.waivers.store'), [
        'employee_id' => $this->employee->id,
        'attendance_date' => '2026-08-03',
        'reason' => 'Should be refused.',
    ])->assertForbidden();

    waiverOwner()->post(route('hrm.attendance.waivers.store'), [
        'employee_id' => $this->employee->id,
        'attendance_date' => '2026-08-03',
        'reason' => 'Owner forgives.',
    ])->assertRedirect();

    waiverRecorder()->post(route('hrm.attendance.waivers.revoke', AttendanceWaiver::first()))
        ->assertForbidden();
});

it('demands a reason, refuses a costless day, and refuses forgiving twice', function () {
    ($this->absentDay)();

    waiverOwner()->post(route('hrm.attendance.waivers.store'), [
        'employee_id' => $this->employee->id,
        'attendance_date' => '2026-08-03',
    ])->assertSessionHasErrors('reason');

    // A present day has no deduction to remove.
    DailyAttendance::create([
        'business_id' => $this->business->id,
        'employee_id' => $this->employee->id,
        'attendance_date' => '2026-08-05',
        'status' => AttendanceStatus::Present,
        'consequence' => AttendanceConsequence::None,
        'worked_minutes' => 480,
        'is_provisional' => false, 'holds_payroll' => false,
    ]);
    waiverOwner()->post(route('hrm.attendance.waivers.store'), [
        'employee_id' => $this->employee->id,
        'attendance_date' => '2026-08-05',
        'reason' => 'Nothing to forgive here.',
    ])->assertRedirect()->assertSessionHas('error');

    waiverOwner()->post(route('hrm.attendance.waivers.store'), [
        'employee_id' => $this->employee->id,
        'attendance_date' => '2026-08-03',
        'reason' => 'First forgiveness.',
    ])->assertRedirect()->assertSessionHas('success');

    waiverOwner()->post(route('hrm.attendance.waivers.store'), [
        'employee_id' => $this->employee->id,
        'attendance_date' => '2026-08-03',
        'reason' => 'Second forgiveness of the same day.',
    ])->assertRedirect()->assertSessionHas('error');

    expect(AttendanceWaiver::count())->toBe(1);
});

it('tells the daily screen who may forgive and which rows are forgiven', function () {
    ($this->absentDay)();

    waiverOwner()->post(route('hrm.attendance.waivers.store'), [
        'employee_id' => $this->employee->id,
        'attendance_date' => '2026-08-03',
        'reason' => 'Device outage day.',
    ])->assertRedirect();

    waiverOwner()->get(route('hrm.attendance.daily.index', ['on' => '2026-08-03']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('hrm/attendance/daily')
            ->where('can_waive', true)
            ->where('days.0.costs_money', true)
            ->where('days.0.waiver.reason', 'Device outage day.'));

    waiverRecorder()->get(route('hrm.attendance.daily.index', ['on' => '2026-08-03']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('can_waive', false));
});

it('drops a forgiven day from the lateness ladder so it no longer pushes later days toward a deduction', function () {
    $band = 'slightly_late';
    foreach (['2026-08-04' => 1, '2026-08-05' => 2, '2026-08-06' => 3] as $day => $occ) {
        DailyAttendance::create([
            'business_id' => $this->business->id,
            'employee_id' => $this->employee->id,
            'attendance_date' => $day,
            'status' => AttendanceStatus::Late,
            'consequence' => AttendanceConsequence::None,
            'worked_minutes' => 480, 'late_minutes' => 10,
            'grace_window_key' => $band, 'occurrence_number' => $occ,
            'is_provisional' => false, 'holds_payroll' => false,
        ]);
    }

    $on = Carbon::parse('2026-08-07');
    expect(DailyAttendance::priorOccurrences($this->employee->id, $on))->toBe([$band => 3]);

    // Forgiving 2026-08-04 takes it out of the ladder entirely (Joy, 27 Sep 2026).
    $waiver = AttendanceWaiver::create([
        'business_id' => $this->business->id,
        'employee_id' => $this->employee->id,
        'attendance_date' => '2026-08-04',
        'waived_status' => 'late', 'waived_consequence' => 'none',
        'reason' => 'Heavy rain, forgiven for everyone.', 'waived_by' => $this->owner->id,
    ]);

    expect(DailyAttendance::priorOccurrences($this->employee->id, $on))->toBe([$band => 2]);

    // Undoing forgiveness makes the day count again.
    $waiver->update(['revoked_at' => now(), 'revoked_by' => $this->owner->id]);
    expect(DailyAttendance::priorOccurrences($this->employee->id, $on))->toBe([$band => 3]);
});
