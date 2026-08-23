<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Enums\AttendanceConsequence;
use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Support\AttendanceSummariser;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Per-employee totals: "how many days was he late this month" answered without
 * a human counting rows.
 *
 * ⚠ The boundary tests are the point. `attendance_date` is date-cast but stored
 * with a time, so a range that quietly drops its first or last day is this
 * project's most repeated defect. A summary that is wrong by one day at each
 * end is worse than no summary, because it looks right.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Sum Co', 'slug' => 'sum-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Permission::firstOrCreate(['name' => 'hrm.attendance.manage', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo('hrm.attendance.manage');
    Role::firstOrCreate(['name' => 'Staff', 'business_id' => $this->business->id, 'guard_name' => 'web']);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->employee = Employee::create([
        'employee_code' => 'SUM-1',
        'first_name' => 'Rafi',
        'last_name' => 'Ahmed',
        'employment_type' => 'permanent',
        'status' => 'active',
        'joining_date' => '2026-01-01',
    ]);
});

function day(int $employeeId, string $date, AttendanceStatus $status, array $extra = []): DailyAttendance
{
    return DailyAttendance::create(array_merge([
        'employee_id' => $employeeId,
        'attendance_date' => $date,
        'status' => $status,
        'consequence' => AttendanceConsequence::None,
        'worked_minutes' => 480,
        'payable_minutes' => 480,
        'late_minutes' => 0,
        'early_leave_minutes' => 0,
        'break_minutes' => 0,
        'is_provisional' => false,
        'holds_payroll' => false,
    ], $extra));
}

it('counts each status separately over the range', function () {
    day($this->employee->id, '2026-08-03', AttendanceStatus::Present);
    day($this->employee->id, '2026-08-04', AttendanceStatus::Late, ['late_minutes' => 25]);
    day($this->employee->id, '2026-08-05', AttendanceStatus::Late, ['late_minutes' => 35]);
    day($this->employee->id, '2026-08-06', AttendanceStatus::Absent, ['worked_minutes' => 0, 'payable_minutes' => 0]);
    day($this->employee->id, '2026-08-07', AttendanceStatus::OnLeave, ['worked_minutes' => 0]);
    day($this->employee->id, '2026-08-08', AttendanceStatus::WeeklyOff, ['worked_minutes' => 0]);

    $s = app(AttendanceSummariser::class)
        ->forEmployee(Carbon::parse('2026-08-03'), Carbon::parse('2026-08-08'), $this->employee->id);

    expect($s['days_recorded'])->toBe(6)
        ->and($s['present'])->toBe(1)
        ->and($s['late'])->toBe(2)
        ->and($s['absent'])->toBe(1)
        ->and($s['on_leave'])->toBe(1)
        ->and($s['weekly_off'])->toBe(1)
        ->and($s['late_minutes'])->toBe(60)
        // Present + Late counts as worked. Late is not absence.
        ->and($s['attended'])->toBe(3);
});

it('includes both boundary days of the range', function () {
    day($this->employee->id, '2026-08-01', AttendanceStatus::Late, ['late_minutes' => 10]);
    day($this->employee->id, '2026-08-15', AttendanceStatus::Late, ['late_minutes' => 10]);
    day($this->employee->id, '2026-08-08', AttendanceStatus::Present);

    $s = app(AttendanceSummariser::class)
        ->forEmployee(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-15'), $this->employee->id);

    expect($s['days_recorded'])->toBe(3)
        ->and($s['late'])->toBe(2);
});

it('excludes days outside the range', function () {
    day($this->employee->id, '2026-07-31', AttendanceStatus::Absent, ['worked_minutes' => 0]);
    day($this->employee->id, '2026-08-05', AttendanceStatus::Present);
    day($this->employee->id, '2026-09-01', AttendanceStatus::Absent, ['worked_minutes' => 0]);

    $s = app(AttendanceSummariser::class)
        ->forEmployee(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'), $this->employee->id);

    expect($s['days_recorded'])->toBe(1)
        ->and($s['absent'])->toBe(0)
        ->and($s['present'])->toBe(1);
});

it('reports a status that scored nothing as a zero rather than omitting it', function () {
    day($this->employee->id, '2026-08-05', AttendanceStatus::Present);

    $s = app(AttendanceSummariser::class)
        ->forEmployee(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'), $this->employee->id);

    // Every case present as a key, so the columns do not move between months.
    foreach (AttendanceStatus::cases() as $case) {
        expect($s['by_status'])->toHaveKey($case->value);
    }

    expect($s['by_status'][AttendanceStatus::Absent->value])->toBe(0);
});

it('counts the days that hold payroll', function () {
    day($this->employee->id, '2026-08-05', AttendanceStatus::MissingPunch, [
        'holds_payroll' => true, 'is_provisional' => true,
    ]);
    day($this->employee->id, '2026-08-06', AttendanceStatus::Present);

    $s = app(AttendanceSummariser::class)
        ->forEmployee(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'), $this->employee->id);

    expect($s['holds_payroll'])->toBe(1)
        ->and($s['provisional'])->toBe(1)
        ->and($s['missing_punch'])->toBe(1);
});

it('keeps one employee out of another employee totals', function () {
    $other = Employee::create([
        'employee_code' => 'SUM-2', 'first_name' => 'Nadia', 'last_name' => 'Karim',
        'employment_type' => 'permanent', 'status' => 'active', 'joining_date' => '2026-01-01',
    ]);

    day($this->employee->id, '2026-08-05', AttendanceStatus::Late, ['late_minutes' => 15]);
    day($other->id, '2026-08-05', AttendanceStatus::Absent, ['worked_minutes' => 0]);

    $rows = app(AttendanceSummariser::class)
        ->forRange(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));

    expect($rows)->toHaveCount(2);

    $mine = collect($rows)->firstWhere('employee_id', $this->employee->id);
    $theirs = collect($rows)->firstWhere('employee_id', $other->id);

    expect($mine['late'])->toBe(1)->and($mine['absent'])->toBe(0)
        ->and($theirs['absent'])->toBe(1)->and($theirs['late'])->toBe(0);
});

it('shows the summary screen to a manager and refuses a staff member', function () {
    day($this->employee->id, '2026-08-05', AttendanceStatus::Late, ['late_minutes' => 12]);

    $this->actingAs($this->owner)
        ->get('/hrm/attendance/summary?from=2026-08-01&to=2026-08-31')
        ->assertOk();

    $staff = User::factory()->create();
    $staff->businesses()->attach($this->business->id);
    $staff->assignRole('Staff');

    $this->actingAs($staff)->get('/hrm/attendance/summary')->assertForbidden();
});

it('lets an employee filter their own clock history to any range', function () {
    $user = User::factory()->create();
    $user->businesses()->attach($this->business->id);
    $user->assignRole('Staff');
    $this->employee->update(['user_id' => $user->id]);

    day($this->employee->id, '2026-03-10', AttendanceStatus::Late, ['late_minutes' => 40]);
    day($this->employee->id, '2026-08-20', AttendanceStatus::Present);

    // The default window is recent, so March is not in it.
    $this->actingAs($user)->get('/hrm/clock')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('summary.late', 0));

    // Asking for March finds it, without touching today's punch panel.
    $this->actingAs($user)->get('/hrm/clock?from=2026-03-01&to=2026-03-31')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('summary.late', 1)
            ->where('summary.late_minutes', 40)
            ->where('today', Carbon::today()->toDateString())
        );
});
