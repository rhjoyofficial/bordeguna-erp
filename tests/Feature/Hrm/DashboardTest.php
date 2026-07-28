<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Dashboard\DashboardFilters;
use App\Modules\Hrm\Dashboard\DashboardRegistry;
use App\Modules\Hrm\Dashboard\Widgets\MissingPunchesWidget;
use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Reporting\Definitions\AttendanceExceptionReport;
use App\Modules\Hrm\Reporting\ReportParameters;
use App\Modules\Hrm\Reporting\ReportScope;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * RPT-04a — the dashboard widget framework and its two proof widgets.
 *
 * The tests that matter here are the three guarantees the plan's corrections
 * bought: a report-backed tile equals its report (C11a), a payroll tile is
 * absent for a Department Manager (C11d), and the cache key cannot be shared
 * across scopes (C11e).
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Dash Co', 'slug' => 'dash-co']);
    app(BusinessContext::class)->set($this->business->id);

    foreach (['hrm.attendance.manage', 'hrm.payroll.manage', 'hrm.salary.view'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    foreach (['Owner', 'Manager', 'Staff'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()
        ->givePermissionTo(['hrm.attendance.manage', 'hrm.payroll.manage', 'hrm.salary.view']);

    // ⚠ As in ReportingTest: this Manager holds hrm.payroll.manage, granted
    // through the Roles UI by someone who never read ACL-02, and must still get
    // no payroll tile. C11d extends C10d to tiles precisely so this holds.
    Role::where('business_id', $this->business->id)->where('name', 'Manager')->first()
        ->givePermissionTo(['hrm.attendance.manage', 'hrm.payroll.manage']);

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

    // The manager is an employee in Production — C10d cannot scope one who is not.
    ($this->makeEmployee)('BDG-000009', 'Nasrin', $this->production, $this->manager);

    $this->month = now()->startOfMonth();

    $this->missingPunch = function (Employee $employee, int $dayOfMonth) {
        return DailyAttendance::create([
            'business_id' => $this->business->id,
            'employee_id' => $employee->id,
            'attendance_date' => $this->month->copy()->addDays($dayOfMonth - 1)->toDateString(),
            'status' => AttendanceStatus::MissingPunch,
            'worked_minutes' => 0,
            'is_provisional' => true,
            'holds_payroll' => true,
        ]);
    };

    $this->filtersFor = fn (User $user, array $values = []) => DashboardFilters::make($values, ReportScope::for($user));
});

/*
|--------------------------------------------------------------------------
| C11a — a report-backed tile equals the report it drills into
|--------------------------------------------------------------------------
*/

it('gives a report-backed tile exactly the row count of the report it links to', function () {
    // The guarantee C11a's hybrid was chosen for, asserted rather than promised.
    $karim = ($this->makeEmployee)('BDG-000001', 'Karim', $this->production);
    $rahim = ($this->makeEmployee)('BDG-000002', 'Rahim', $this->production);

    ($this->missingPunch)($karim, 3);
    ($this->missingPunch)($karim, 4);
    ($this->missingPunch)($rahim, 5);

    // A day that is late but not missing must not be counted.
    DailyAttendance::create([
        'business_id' => $this->business->id, 'employee_id' => $karim->id,
        'attendance_date' => $this->month->copy()->addDays(5)->toDateString(),
        'status' => AttendanceStatus::Late, 'late_minutes' => 20,
        'worked_minutes' => 460, 'is_provisional' => false, 'holds_payroll' => false,
    ]);

    $widget = app(MissingPunchesWidget::class);
    $filters = ($this->filtersFor)($this->hr);
    $value = $widget->value($filters);

    expect($value->value)->toBe(3)
        ->and($value->drilldownReport)->toBe('attendance_exceptions');

    // And the drill-down really does produce those three rows — same parameters,
    // same query, so the tile and the report cannot disagree.
    $rows = iterator_to_array(
        app(AttendanceExceptionReport::class)->rows(new ReportParameters($value->drilldownParameters)),
    );

    expect($rows)->toHaveCount($value->value);
});

it('counts a grouped report exactly rather than trusting its deliberately cheap estimate', function () {
    // ⚠ OrganisationSummaryReport returns 0 from estimatedRows() on purpose.
    // A tile that delegated to the estimate would display a confident zero,
    // which is why countRows() exists at all.
    $karim = ($this->makeEmployee)('BDG-000001', 'Karim', $this->production);

    DailyAttendance::create([
        'business_id' => $this->business->id, 'employee_id' => $karim->id,
        'attendance_date' => $this->month->copy()->addDays(2)->toDateString(),
        'status' => AttendanceStatus::Present, 'worked_minutes' => 480,
        'is_provisional' => false, 'holds_payroll' => false,
    ]);

    $summary = app(\App\Modules\Hrm\Reporting\Definitions\OrganisationSummaryReport::class);
    $parameters = new ReportParameters(['month' => $this->month->format('Y-m')]);

    expect($summary->estimatedRows($parameters))->toBe(0)
        ->and($summary->countRows($parameters))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| C11d — ACL-02 reaches a tile
|--------------------------------------------------------------------------
*/

it('withholds every payroll tile from a department manager who holds the payroll permission', function () {
    $registry = app(DashboardRegistry::class);
    $available = $registry->availableTo($this->manager)->keys()->all();

    expect($this->manager->can('hrm.payroll.manage'))->toBeTrue()
        ->and($available)->toContain('attendance_missing_punches')
        ->and($available)->not->toContain('payroll_current_status');

    // The owner sees both.
    expect($registry->availableTo($this->hr)->keys()->all())
        ->toContain('attendance_missing_punches')
        ->toContain('payroll_current_status');
});

it('leaves a payroll tile out of the payload entirely rather than rendering it empty', function () {
    // A disabled tile labelled "Current payroll" still tells a manager that a
    // figure exists and roughly where to ask for it.
    $this->actingAs($this->manager)
        ->get(route('hrm.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('hrm/dashboard')
            ->where('department_locked', true)
            ->where('widgets', fn ($widgets) => collect($widgets)->pluck('key')->doesntContain('payroll_current_status')));
});

it('scopes a department manager\'s tile to their own department', function () {
    $mine = ($this->makeEmployee)('BDG-000001', 'Karim', $this->production);
    $theirs = ($this->makeEmployee)('BDG-000050', 'Jahid', $this->packaging);

    ($this->missingPunch)($mine, 3);
    ($this->missingPunch)($theirs, 3);
    ($this->missingPunch)($theirs, 4);

    $widget = app(MissingPunchesWidget::class);

    expect($widget->value(($this->filtersFor)($this->hr))->value)->toBe(3);

    // The manager asks for Packaging explicitly; the scope overwrites the choice.
    $scoped = $widget->value(($this->filtersFor)($this->manager, ['department_id' => $this->packaging->id]));

    expect($scoped->value)->toBe(1)
        ->and($scoped->drilldownParameters['department_id'])->toBe($this->production->id);
});

/*
|--------------------------------------------------------------------------
| C11e — the cache key carries the scope
|--------------------------------------------------------------------------
*/

it('never lets two differently scoped users share a cached figure', function () {
    // ⚠ The failure this prevents is silent and total: a shared key would serve
    // the owner's whole-business number to a scoped manager, undoing C10d
    // without anybody editing C10d.
    $ownerKey = ($this->filtersFor)($this->hr)->cacheKey('attendance_missing_punches', $this->business->id);
    $managerKey = ($this->filtersFor)($this->manager)->cacheKey('attendance_missing_punches', $this->business->id);

    expect($ownerKey)->not->toBe($managerKey)
        ->and($managerKey)->toContain('dept-'.$this->production->id)
        ->and($ownerKey)->toContain('all');
});

it('computes a widget live when it declares no cache tolerance', function () {
    $karim = ($this->makeEmployee)('BDG-000001', 'Karim', $this->production);
    ($this->missingPunch)($karim, 3);

    $registry = app(DashboardRegistry::class);
    $filters = ($this->filtersFor)($this->hr);

    $first = collect($registry->resolveFor($this->hr, $filters))->firstWhere('key', 'attendance_missing_punches');
    expect($first['value'])->toBe(1);

    ($this->missingPunch)($karim, 4);

    // The default TTL is 60s, so the cached figure is deliberately stale here —
    // asserting that proves the cache is actually in play rather than a no-op.
    $second = collect($registry->resolveFor($this->hr, $filters))->firstWhere('key', 'attendance_missing_punches');
    expect($second['value'])->toBe(1);

    cache()->clear();

    $third = collect($registry->resolveFor($this->hr, $filters))->firstWhere('key', 'attendance_missing_punches');
    expect($third['value'])->toBe(2);
});

/*
|--------------------------------------------------------------------------
| The payroll status tile
|--------------------------------------------------------------------------
*/

it('reports the highest non-cancelled version rather than the newest run', function () {
    // WFL-06: reopening produces v2 and cancels v1. Ordering by id would surface
    // the cancelled v1 as the state of the month.
    $period = PayrollPeriod::create([
        'business_id' => $this->business->id,
        'year' => $this->month->year, 'month' => $this->month->month,
        'start_date' => $this->month->copy()->subMonth()->day(24)->toDateString(),
        'end_date' => $this->month->copy()->day(23)->toDateString(),
        'divisor_days' => 30,
    ]);

    PayrollRun::create([
        'business_id' => $this->business->id, 'payroll_period_id' => $period->id,
        'version' => 1, 'status' => 'cancelled', 'employee_count' => 2,
    ]);

    PayrollRun::create([
        'business_id' => $this->business->id, 'payroll_period_id' => $period->id,
        'version' => 2, 'status' => 'calculated', 'employee_count' => 2, 'blocked_count' => 1,
    ]);

    $value = app(\App\Modules\Hrm\Dashboard\Widgets\PayrollStatusWidget::class)
        ->value(($this->filtersFor)($this->hr));

    expect($value->value)->toBe('Calculated')
        ->and($value->note)->toContain('run v2')
        ->and($value->note)->toContain('1 payslip blocked')
        ->and($value->note)->toContain('figures can still change');
});

it('distinguishes a month with no payroll run from one sitting in draft', function () {
    $value = app(\App\Modules\Hrm\Dashboard\Widgets\PayrollStatusWidget::class)
        ->value(($this->filtersFor)($this->hr));

    expect($value->value)->toBe('Not started')
        ->and($value->note)->toContain('No payroll run exists')
        ->and($value->drilldownReport)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The page
|--------------------------------------------------------------------------
*/

it('renders the dashboard with its widgets grouped and filters echoed back', function () {
    $karim = ($this->makeEmployee)('BDG-000001', 'Karim', $this->production);
    ($this->missingPunch)($karim, 3);

    $this->actingAs($this->hr)
        ->get(route('hrm.dashboard', ['department_id' => $this->production->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('hrm/dashboard')
            ->where('filters.department_id', $this->production->id)
            ->where('department_locked', false)
            // Six after RPT-04b: present, absent, late, on leave, missing
            // punches, payroll status.
            ->has('widgets', 6));
});

it('answers about the month it was asked for, not the one that has finished', function () {
    // ⚠ Caught in the live check, not by any test above. `asAt()` clamps a
    // future date back to today so a "present today" tile cannot claim tomorrow
    // — but applied to a MONTH it meant a dashboard filtered to August, viewed
    // in July, quietly answered about July under a tile labelled "this month".
    // An empty August is a true and useful answer; a July figure is not.
    $next = now()->addMonth()->startOfMonth();

    $filters = ($this->filtersFor)($this->hr, [
        'range_from' => $next->toDateString(),
        'range_to' => $next->copy()->endOfMonth()->toDateString(),
    ]);

    expect($filters->month()->format('Y-m'))->toBe($next->format('Y-m'))
        // asAt() still clamps, because that is right for a single-date tile.
        ->and($filters->asAt()->lessThanOrEqualTo(now()))->toBeTrue();

    $parameters = app(MissingPunchesWidget::class)->value($filters)->drilldownParameters;

    expect($parameters['month'])->toBe($next->format('Y-m'));

    expect(app(\App\Modules\Hrm\Dashboard\Widgets\PayrollStatusWidget::class)->value($filters)->note)
        ->toContain($next->format('F Y'));
});

it('refuses a range that ends before it starts instead of quietly showing nothing', function () {
    $this->actingAs($this->hr)
        ->get(route('hrm.dashboard', ['range_from' => '2026-08-01', 'range_to' => '2026-07-01']))
        ->assertSessionHasErrors('range_to');
});
