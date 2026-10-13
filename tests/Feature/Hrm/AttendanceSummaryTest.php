<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Enums\AttendanceConsequence;
use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
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

/**
 * The clock page's own filters. The range already worked; these cover the two
 * that did not exist, and the line between them and the totals.
 *
 * ⚠ A status filter that also moved today's punch panel, or that quietly
 * narrowed the period totals, would be worse than no filter at all: the first
 * records attendance on the wrong day, the second turns "late 3 of 14" into
 * "late 3 of 3" and reads as a perfect fortnight.
 */
function clockUser(): User
{
    $user = User::factory()->create();
    $user->businesses()->attach(test()->business->id);
    $user->assignRole('Staff');
    test()->employee->update(['user_id' => $user->id]);

    return $user;
}

it('narrows the clock history to one status without touching the totals or today', function () {
    $user = clockUser();

    day($this->employee->id, '2026-08-03', AttendanceStatus::Present);
    day($this->employee->id, '2026-08-04', AttendanceStatus::Late, ['late_minutes' => 25]);
    day($this->employee->id, '2026-08-05', AttendanceStatus::Late, ['late_minutes' => 35]);
    day($this->employee->id, '2026-08-06', AttendanceStatus::Absent, ['worked_minutes' => 0, 'payable_minutes' => 0]);

    $this->actingAs($user)->get('/hrm/clock?from=2026-08-01&to=2026-08-31&status=late')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // The list is the only thing that narrows.
            ->has('recent_days', 2)
            ->where('recent_days.0.status', 'Late')
            ->where('recent_days.1.status', 'Late')
            // The totals still describe the whole period.
            ->where('summary.days_recorded', 4)
            ->where('summary.present', 1)
            ->where('summary.absent', 1)
            ->where('summary.late', 2)
            // And today is still today.
            ->where('today', Carbon::today()->toDateString())
            ->where('filters.status', 'late')
        );
});

it('shows every status when none is chosen', function () {
    $user = clockUser();

    day($this->employee->id, '2026-08-03', AttendanceStatus::Present);
    day($this->employee->id, '2026-08-04', AttendanceStatus::Late, ['late_minutes' => 25]);
    day($this->employee->id, '2026-08-05', AttendanceStatus::WeeklyOff, ['worked_minutes' => 0]);

    $this->actingAs($user)->get('/hrm/clock?from=2026-08-01&to=2026-08-31')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('recent_days', 3)
            ->where('filters.status', '')
        );

    // An explicitly empty status is the same thing, because that is what the
    // "All statuses" option sends.
    $this->actingAs($user)->get('/hrm/clock?from=2026-08-01&to=2026-08-31&status=')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('recent_days', 3));
});

it('treats an unrecognised status as all statuses rather than an error', function () {
    $user = clockUser();

    day($this->employee->id, '2026-08-03', AttendanceStatus::Present);
    day($this->employee->id, '2026-08-04', AttendanceStatus::Late, ['late_minutes' => 25]);

    // A stale or hand-edited URL should still show the employee their days.
    $this->actingAs($user)->get('/hrm/clock?from=2026-08-01&to=2026-08-31&status=not_a_status')
        ->assertOk()
        ->assertSessionHasNoErrors()
        ->assertInertia(fn ($page) => $page
            ->has('recent_days', 2)
            ->where('filters.status', '')
        );
});

it('offers every status the enum has as a filter option', function () {
    $user = clockUser();

    $this->actingAs($user)->get('/hrm/clock')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('statuses', count(AttendanceStatus::cases())));
});

it('sorts the clock history newest first by default and oldest first on request', function () {
    $user = clockUser();

    day($this->employee->id, '2026-08-03', AttendanceStatus::Present);
    day($this->employee->id, '2026-08-04', AttendanceStatus::Late, ['late_minutes' => 25]);
    day($this->employee->id, '2026-08-05', AttendanceStatus::Present);

    $this->actingAs($user)->get('/hrm/clock?from=2026-08-01&to=2026-08-31')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('recent_days.0.date', '2026-08-05')
            ->where('recent_days.2.date', '2026-08-03')
            ->where('filters.sort', 'newest')
        );

    $this->actingAs($user)->get('/hrm/clock?from=2026-08-01&to=2026-08-31&sort=oldest')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('recent_days.0.date', '2026-08-03')
            ->where('recent_days.2.date', '2026-08-05')
            ->where('filters.sort', 'oldest')
        );
});

it('keeps both boundary days of the range when a status is chosen', function () {
    $user = clockUser();

    // ⚠ attendance_date is date-cast but stored with a time. A status filter
    // added on top of a range is exactly where a bare >= creeps back in and
    // drops the first or last day.
    day($this->employee->id, '2026-08-01', AttendanceStatus::Late, ['late_minutes' => 10]);
    day($this->employee->id, '2026-08-08', AttendanceStatus::Present);
    day($this->employee->id, '2026-08-15', AttendanceStatus::Late, ['late_minutes' => 20]);

    $this->actingAs($user)->get('/hrm/clock?from=2026-08-01&to=2026-08-15&status=late&sort=oldest')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('recent_days', 2)
            ->where('recent_days.0.date', '2026-08-01')
            ->where('recent_days.1.date', '2026-08-15')
        );
});

it('shows one employee on their own attendance page, defaulting to the payroll month so far', function () {
    Carbon::setTestNow('2026-08-23 10:00:00');

    // Inside the current cycle (24 Jul onward) and before it.
    day($this->employee->id, '2026-07-25', AttendanceStatus::Late, ['late_minutes' => 30]);
    day($this->employee->id, '2026-08-20', AttendanceStatus::Present);
    day($this->employee->id, '2026-07-20', AttendanceStatus::Absent, ['worked_minutes' => 0]);

    // Confine like the employee pages do: an assignment in this business.
    EmployeeAssignment::create([
        'employee_id' => $this->employee->id,
        'business_id' => $this->business->id,
        'office_id' => Office::create([
            'business_id' => $this->business->id, 'name' => 'HQ',
        ])->id,
        'is_primary' => true,
        'effective_from' => '2025-01-01',
    ]);

    $this->actingAs($this->owner)
        ->get("/hrm/attendance/employees/{$this->employee->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // "Default last month 24 to today" - Joy's own phrase. The absent
            // day on 20 July sits before the cycle and must not appear.
            ->where('filters.from', '2026-07-24')
            ->where('filters.to', '2026-08-23')
            ->where('summary.late', 1)
            ->where('summary.present', 1)
            ->where('summary.absent', 0)
            ->has('days', 2));

    // An explicit range still wins.
    $this->actingAs($this->owner)
        ->get("/hrm/attendance/employees/{$this->employee->id}?from=2026-07-01&to=2026-07-31")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('summary.absent', 1)
            ->has('days', 2));

    Carbon::setTestNow();
});

it('keeps the per-employee page away from staff', function () {
    $staff = User::factory()->create();
    $staff->businesses()->attach($this->business->id);
    $staff->assignRole('Staff');

    $this->actingAs($staff)
        ->get("/hrm/attendance/employees/{$this->employee->id}")
        ->assertForbidden();
});

it('counts a late half day as late, the way the dashboard trend does', function () {
    // ⚠ Audit 13 Oct 2026: only status Late was counted.
    day($this->employee->id, '2026-08-03', AttendanceStatus::Late, ['late_minutes' => 20]);
    day($this->employee->id, '2026-08-04', AttendanceStatus::HalfDay, ['late_minutes' => 40]);
    day($this->employee->id, '2026-08-05', AttendanceStatus::Present);

    $s = app(AttendanceSummariser::class)
        ->forEmployee(Carbon::parse('2026-08-03'), Carbon::parse('2026-08-05'), $this->employee->id);

    expect($s['late'])->toBe(2)
        ->and($s['by_status']['late'])->toBe(1);
});
