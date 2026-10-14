<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\AssignSchedule;
use App\Modules\Hrm\Actions\ProcessAttendance;
use App\Modules\Hrm\database\seeders\AttendancePolicySeeder;
use App\Modules\Hrm\Enums\ScheduleScope;
use App\Modules\Hrm\Models\AttendancePolicy;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\HolidayCalendar;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\Shift;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Audit #20, 14 Oct 2026: a holiday added after the day has passed now
 * re-judges that day (it used to stay Absent), except where payroll has
 * already used it, which is said rather than done.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-07-29 10:00:00');

    $this->business = Business::create(['name' => 'Holiday Co', 'slug' => 'holiday-co']);
    app(BusinessContext::class)->set($this->business->id);

    Permission::firstOrCreate(['name' => 'hrm.scheduling.manage', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo('hrm.scheduling.manage');
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $office = Office::create(['name' => 'Head Office', 'is_active' => true]);
    $shift = Shift::create([
        'name' => 'General', 'start_time' => '09:00', 'end_time' => '18:00',
        'grace_minutes' => 15, 'break_minutes' => 60, 'is_overnight' => false,
    ]);
    $this->calendar = HolidayCalendar::create(['name' => 'Bangladesh', 'year' => 2026, 'weekly_off_days' => [5], 'is_default' => true]);
    AttendancePolicy::create(['name' => 'Standard', 'version' => 1, 'config' => AttendancePolicySeeder::defaultConfig(), 'effective_from' => '2026-01-01']);
    app(AssignSchedule::class)->handle(ScheduleScope::Business, null, [
        'schedule_type' => 'fixed', 'shift_id' => $shift->id,
        'holiday_calendar_id' => $this->calendar->id, 'effective_from' => '2025-01-01',
    ]);

    $this->employee = Employee::create([
        'employee_code' => 'BDG-000001', 'first_name' => 'Karim', 'last_name' => 'Ahmed',
        'employment_type' => 'permanent', 'status' => 'active',
    ]);
    EmployeeAssignment::create(['employee_id' => $this->employee->id, 'office_id' => $office->id, 'is_primary' => true, 'effective_from' => '2025-01-01']);

    // Monday 27 Jul was judged with no punches, and finalised by the window.
    app(ProcessAttendance::class)->forDay($this->employee, '2026-07-27');
    DailyAttendance::where('employee_id', $this->employee->id)->update(['finalized_at' => now()]);

    $this->day = fn () => DailyAttendance::where('employee_id', $this->employee->id)->onDate('2026-07-27')->firstOrFail();
    $this->addHoliday = fn () => $this->actingAs($this->owner)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.calendars.holidays.store', $this->calendar), ['date' => '2026-07-27', 'name' => 'Flood closure']);
});

afterEach(fn () => Carbon::setTestNow());

it('re-judges a past day as a holiday when the holiday is added afterwards', function () {
    expect(($this->day)()->status->value)->toBe('absent');

    ($this->addHoliday)()->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Holiday added. Attendance for 2026-07-27 was recalculated for 1 person.');

    expect(($this->day)()->status->value)->toBe('holiday');
});

it('puts the absence back when the holiday is removed again', function () {
    ($this->addHoliday)();
    $holiday = $this->calendar->holidays()->firstOrFail();

    $this->actingAs($this->owner)->withSession(['current_business_id' => $this->business->id])
        ->delete(route('hrm.calendars.holidays.destroy', [$this->calendar, $holiday]))
        ->assertSessionHasNoErrors();

    expect(($this->day)()->status->value)->toBe('absent');
});

it('leaves the day alone and says so when payroll for it is already approved', function () {
    $period = PayrollPeriod::create(['business_id' => $this->business->id, 'year' => 2026, 'month' => 7, 'start_date' => '2026-07-01', 'end_date' => '2026-07-31']);
    PayrollRun::create(['business_id' => $this->business->id, 'payroll_period_id' => $period->id, 'status' => 'approved']);

    ($this->addHoliday)()->assertSessionHas('warning', fn (string $message) => str_contains($message, 'already approved'));

    expect(($this->day)()->status->value)->toBe('absent')
        ->and($this->calendar->holidays()->count())->toBe(1);
});

it('does nothing to attendance for a holiday added ahead of time', function () {
    $this->actingAs($this->owner)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.calendars.holidays.store', $this->calendar), ['date' => '2026-08-03', 'name' => 'Ahead'])
        ->assertSessionHas('success', 'Holiday added.');

    expect(DailyAttendance::onDate('2026-08-03')->exists())->toBeFalse();
});
