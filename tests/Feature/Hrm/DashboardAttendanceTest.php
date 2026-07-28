<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Dashboard\DashboardFilters;
use App\Modules\Hrm\Dashboard\Widgets\AbsentTodayWidget;
use App\Modules\Hrm\Dashboard\Widgets\EmployeesOnLeaveWidget;
use App\Modules\Hrm\Dashboard\Widgets\LateTodayWidget;
use App\Modules\Hrm\Dashboard\Widgets\PresentTodayWidget;
use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Models\AttendancePunch;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Reporting\Definitions\DailyAttendanceReport;
use App\Modules\Hrm\Reporting\ReportParameters;
use App\Modules\Hrm\Reporting\ReportScope;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * RPT-04b — the attendance tiles, and Correction C11b's freshness state.
 *
 * The headline finding is that `ProcessAttendance` is never scheduled, so the
 * ordinary state of every morning is punches without processed days. These tests
 * pin that the dashboard says so rather than reporting a confident zero.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Fresh Co', 'slug' => 'fresh-co']);
    app(BusinessContext::class)->set($this->business->id);

    foreach (['hrm.attendance.manage', 'hrm.payroll.manage'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    foreach (['Owner', 'Manager'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()
        ->givePermissionTo(['hrm.attendance.manage', 'hrm.payroll.manage']);
    Role::where('business_id', $this->business->id)->where('name', 'Manager')->first()
        ->givePermissionTo(['hrm.attendance.manage']);

    $this->hr = User::factory()->create();
    $this->hr->businesses()->attach($this->business->id);
    $this->hr->assignRole('Owner');

    $this->manager = User::factory()->create();
    $this->manager->businesses()->attach($this->business->id);
    $this->manager->assignRole('Manager');

    $this->office = Office::create(['name' => 'Head Office', 'is_active' => true]);
    $this->production = Department::create([
        'name' => 'Production', 'office_id' => $this->office->id, 'is_active' => true,
    ]);
    $this->packaging = Department::create([
        'name' => 'Packaging', 'office_id' => $this->office->id, 'is_active' => true,
    ]);

    $this->makeEmployee = function (string $code, string $name, Department $department, ?User $user = null) {
        $employee = Employee::create([
            'employee_code' => $code,
            'first_name' => $name, 'last_name' => 'Ahmed',
            'employment_type' => 'permanent', 'status' => 'active',
            'user_id' => $user?->id,
        ]);

        EmployeeAssignment::create([
            'employee_id' => $employee->id,
            'office_id' => $this->office->id,
            'department_id' => $department->id,
            'is_primary' => true, 'effective_from' => '2025-01-01',
        ]);

        return $employee;
    };

    ($this->makeEmployee)('BDG-000009', 'Nasrin', $this->production, $this->manager);

    $this->punch = function (Employee $employee, string $key) {
        return AttendancePunch::create([
            'business_id' => $this->business->id,
            'employee_id' => $employee->id,
            'punched_at' => now()->startOfDay()->addHours(9),
            'source' => 'biometric',
            'dedup_key' => $key,
        ]);
    };

    $this->day = function (Employee $employee, AttendanceStatus $status, array $attributes = []) {
        return DailyAttendance::create([
            'business_id' => $this->business->id,
            'employee_id' => $employee->id,
            'attendance_date' => now()->toDateString(),
            'status' => $status,
            'worked_minutes' => $status === AttendanceStatus::Absent ? 0 : 480,
            'is_provisional' => false,
            'holds_payroll' => false,
            'processed_at' => now(),
            ...$attributes,
        ]);
    };

    $this->filtersFor = fn (User $user, array $values = []) => DashboardFilters::make($values, ReportScope::for($user));
});

it('says nothing has been processed rather than reporting a confident zero', function () {
    // ⚠ THE HEADLINE FINDING, pinned. ProcessAttendance is never scheduled, so
    // this is the ordinary state of every morning: punches exist, no processed
    // day exists, and a bare "0 present" would read as "nobody came in".
    $karim = ($this->makeEmployee)('BDG-000001', 'Karim', $this->production);
    ($this->punch)($karim, 'fresh-1');

    $value = app(PresentTodayWidget::class)->value(($this->filtersFor)($this->hr));

    expect($value->value)->toBe(0)
        ->and($value->stale)->toBeTrue()
        ->and($value->note)->toContain('Not processed yet')
        // ⚠ Agreement, not just the "s". The browser check caught "1 employee
        // HAVE punched today" passing a toContain('1 employee') assertion while
        // reading as broken English on the most-looked-at line of the page.
        ->and($value->note)->toContain('1 employee has punched today and is not counted');
});

it('stamps the figure with when it was processed and flags punches recorded since', function () {
    $karim = ($this->makeEmployee)('BDG-000001', 'Karim', $this->production);
    $rahim = ($this->makeEmployee)('BDG-000002', 'Rahim', $this->production);

    ($this->day)($karim, AttendanceStatus::Present, [
        'processed_at' => now()->startOfDay()->addHours(6)->addMinutes(14),
    ]);

    // Rahim punched but has not been processed — the gap the note must state.
    ($this->punch)($rahim, 'fresh-2');

    $value = app(PresentTodayWidget::class)->value(($this->filtersFor)($this->hr));

    expect($value->value)->toBe(1)
        ->and($value->stale)->toBeTrue()
        ->and($value->note)->toContain('06:14 AM')
        ->and($value->note)->toContain('1 employee has punched since and is not counted yet');
});

it('declares a fully processed day settled rather than warning about nothing', function () {
    // A marker that is always on is a marker nobody reads.
    $karim = ($this->makeEmployee)('BDG-000001', 'Karim', $this->production);
    ($this->day)($karim, AttendanceStatus::Present);

    $value = app(PresentTodayWidget::class)->value(($this->filtersFor)($this->hr));

    expect($value->stale)->toBeFalse()
        ->and($value->note)->toContain('As at')
        ->and($value->note)->not->toContain('punched since');
});

it('says how many absences are still only proposals', function () {
    // POL-06: the engine proposes Absent and a human finalises it. Counting a
    // provisional absence silently would assert what the system does not — on
    // the tile most likely to be acted on disciplinarily.
    $karim = ($this->makeEmployee)('BDG-000001', 'Karim', $this->production);
    ($this->day)($karim, AttendanceStatus::Absent, ['is_provisional' => true]);

    $value = app(AbsentTodayWidget::class)->value(($this->filtersFor)($this->hr));

    expect($value->value)->toBe(1)
        ->and($value->note)->toContain('1 provisional day awaiting review');
});

it('counts a late arrival that became a half day, and drills into exactly those rows', function () {
    // ⚠ The 2b ApplyOccurrencePolicy bug, third appearance. A status filter
    // would drop the person who turned up at eleven from the count of who was
    // late — so the report's status=late option filters on minutes, and the tile
    // inherits that by being backed by it.
    $karim = ($this->makeEmployee)('BDG-000001', 'Karim', $this->production);
    ($this->day)($karim, AttendanceStatus::HalfDay, ['late_minutes' => 95, 'worked_minutes' => 240]);

    $value = app(LateTodayWidget::class)->value(($this->filtersFor)($this->hr));

    expect($value->value)->toBe(1);

    $rows = iterator_to_array(
        app(DailyAttendanceReport::class)->rows(new ReportParameters($value->drilldownParameters)),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['status'])->toBe('Half day');
});

it('counts a worked day as present whatever status it resolved to', function () {
    // POL-02 again: Late, Half day and Field duty are all days somebody worked.
    // If they were not counted, present + absent + leave would stop adding up to
    // the headcount — the first arithmetic anybody does on a dashboard.
    $statuses = [
        'BDG-000001' => AttendanceStatus::Present,
        'BDG-000002' => AttendanceStatus::Late,
        'BDG-000003' => AttendanceStatus::HalfDay,
        'BDG-000004' => AttendanceStatus::Absent,
    ];

    $n = 0;

    foreach ($statuses as $code => $status) {
        $employee = ($this->makeEmployee)($code, 'Person'.$n++, $this->production);

        ($this->day)($employee, $status, [
            'late_minutes' => $status === AttendanceStatus::Late ? 20 : 0,
        ]);
    }

    $filters = ($this->filtersFor)($this->hr);

    expect(app(PresentTodayWidget::class)->value($filters)->value)->toBe(3)
        ->and(app(AbsentTodayWidget::class)->value($filters)->value)->toBe(1)
        ->and(app(LateTodayWidget::class)->value($filters)->value)->toBe(1);
});

it('counts employees on leave from the leave register, so it needs no processing at all', function () {
    // ⚠ The one attendance-shaped tile carrying no freshness caveat: an applied
    // leave request is an authorisation and is true whether or not
    // ProcessAttendance has run. On a morning when nothing has been processed,
    // this tile still answers.
    $type = LeaveType::create([
        'business_id' => $this->business->id, 'name' => 'Annual leave',
        'code' => 'AL', 'annual_quota' => 20, 'is_paid' => true,
    ]);

    $onLeave = ($this->makeEmployee)('BDG-000001', 'Karim', $this->production);
    $pending = ($this->makeEmployee)('BDG-000002', 'Rahim', $this->production);

    // Applied — counts. The range starts and ends today, which is the date-cast
    // trap's favourite shape: compared as strings, both bounds would miss.
    LeaveRequest::create([
        'business_id' => $this->business->id, 'employee_id' => $onLeave->id,
        'leave_type_id' => $type->id,
        'start_date' => now()->toDateString(), 'end_date' => now()->toDateString(),
        'days' => 1, 'reason' => 'Wedding', 'applied_at' => now(),
    ]);

    // Never applied — must not count, or a manager is told somebody is away who
    // is expected at work.
    LeaveRequest::create([
        'business_id' => $this->business->id, 'employee_id' => $pending->id,
        'leave_type_id' => $type->id,
        'start_date' => now()->toDateString(), 'end_date' => now()->toDateString(),
        'days' => 1, 'reason' => 'Asked late', 'applied_at' => null,
    ]);

    $value = app(EmployeesOnLeaveWidget::class)->value(($this->filtersFor)($this->hr));

    expect($value->value)->toBe(1)
        ->and($value->stale)->toBeFalse()
        ->and($value->note)->toContain('Does not depend on attendance processing');
});

it('scopes the freshness gap to the viewer own department', function () {
    // A department manager told that 38 people are unprocessed, when 37 of them
    // are in another department, has been handed somebody else's problem.
    $mine = ($this->makeEmployee)('BDG-000001', 'Karim', $this->production);
    $theirs = ($this->makeEmployee)('BDG-000050', 'Jahid', $this->packaging);

    ($this->punch)($mine, 'scope-1');
    ($this->punch)($theirs, 'scope-2');

    $ownerNote = app(PresentTodayWidget::class)->value(($this->filtersFor)($this->hr))->note;
    $managerNote = app(PresentTodayWidget::class)->value(($this->filtersFor)($this->manager))->note;

    expect($ownerNote)->toContain('2 employees have punched')
        ->and($managerNote)->toContain('1 employee has punched');
});
