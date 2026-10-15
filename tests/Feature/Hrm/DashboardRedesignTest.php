<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The HR dashboard redesign (15 Oct 2026): the props the new page needs on
 * top of the widgets, and the proof that the old gates still hold on them.
 *
 * Three new props: `ranges` (the chips, pay cycle included), `payroll` (the
 * card: latest run + the cycle still to come) and `staff` (headcount
 * movements). The tests that matter are the gated ones: a Department Manager
 * gets `payroll: null` as surely as they get no payroll tile, and nobody
 * without the employee register sees its counts.
 */
beforeEach(function () {
    // ⚠ Frozen mid-cycle, as the other dashboard suites are: on the 24th the
    // live 24-23 cycle wears next month's label. 10 Aug 2026 is a Monday.
    Carbon::setTestNow('2026-08-10 10:00:00');

    $this->business = Business::create(['name' => 'Redesign Co', 'slug' => 'redesign-co']);
    app(BusinessContext::class)->set($this->business->id);

    foreach (['hrm.attendance.manage', 'hrm.payroll.manage', 'hrm.employees.manage'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    foreach (['Owner', 'Manager', 'Clerk'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()
        ->givePermissionTo(['hrm.attendance.manage', 'hrm.payroll.manage', 'hrm.employees.manage']);
    // A Manager holding the payroll permission anyway (ACL-02's test case).
    Role::where('business_id', $this->business->id)->where('name', 'Manager')->first()
        ->givePermissionTo(['hrm.attendance.manage', 'hrm.payroll.manage']);
    // Attendance only: no register, no payroll.
    Role::where('business_id', $this->business->id)->where('name', 'Clerk')->first()
        ->givePermissionTo(['hrm.attendance.manage']);

    $this->hr = User::factory()->create();
    $this->hr->businesses()->attach($this->business->id);
    $this->hr->assignRole('Owner');

    $this->manager = User::factory()->create();
    $this->manager->businesses()->attach($this->business->id);
    $this->manager->assignRole('Manager');

    $this->clerk = User::factory()->create();
    $this->clerk->businesses()->attach($this->business->id);
    $this->clerk->assignRole('Clerk');

    $this->office = Office::create(['name' => 'Head Office', 'is_active' => true]);
    $this->production = Department::create([
        'name' => 'Production', 'office_id' => $this->office->id, 'is_active' => true,
    ]);

    $this->makeEmployee = function (string $code, array $attributes = [], ?User $user = null, ?string $effectiveTo = null) {
        $employee = Employee::create([
            'employee_code' => $code,
            'first_name' => $code, 'last_name' => 'Ahmed',
            'employment_type' => 'permanent', 'status' => 'active',
            'user_id' => $user?->id,
            ...$attributes,
        ]);

        EmployeeAssignment::create([
            'employee_id' => $employee->id,
            'office_id' => $this->office->id,
            'department_id' => $this->production->id,
            'is_primary' => true, 'effective_from' => '2025-01-01', 'effective_to' => $effectiveTo,
        ]);

        return $employee;
    };

    // The manager is an employee in Production, so C10d can scope them.
    ($this->makeEmployee)('BDG-000009', [], $this->manager);

    $this->makeRun = function (int $year, int $month, float $net, string $status = 'disbursed', array $attributes = []) {
        $period = PayrollPeriod::firstOrCreate(
            ['business_id' => $this->business->id, 'year' => $year, 'month' => $month],
            [
                'start_date' => sprintf('%04d-%02d-24', $month === 1 ? $year - 1 : $year, $month === 1 ? 12 : $month - 1),
                'end_date' => sprintf('%04d-%02d-23', $year, $month),
                'divisor_days' => 30,
            ],
        );

        return PayrollRun::create([
            'business_id' => $this->business->id,
            'payroll_period_id' => $period->id,
            'version' => 1, 'status' => $status,
            'employee_count' => 2, 'total_net' => $net,
            ...$attributes,
        ]);
    };
});

afterEach(fn () => Carbon::setTestNow());

/*
|--------------------------------------------------------------------------
| The range chips
|--------------------------------------------------------------------------
*/

it('sends the three preset ranges, with the pay cycle read from the business setting', function () {
    $this->actingAs($this->hr)
        ->get(route('hrm.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('hrm/dashboard')
            ->where('ranges.today.from', '2026-08-10')
            ->where('ranges.today.to', '2026-08-10')
            // Saturday to Friday: Bor de Guna's week, Friday off.
            ->where('ranges.week.from', '2026-08-08')
            ->where('ranges.week.to', '2026-08-14')
            // The 24-23 cycle that 10 Aug sits inside, labelled August.
            ->where('ranges.cycle.from', '2026-07-24')
            ->where('ranges.cycle.to', '2026-08-23')
            ->where('ranges.cycle.label', 'August 2026')
            // And the default range IS that cycle, so the chip lights by itself.
            ->where('filters.range_from', '2026-07-24')
            ->where('filters.range_to', '2026-08-23')
            ->where('filters.as_at', '2026-08-10'));
});

/*
|--------------------------------------------------------------------------
| The payroll card
|--------------------------------------------------------------------------
*/

it('shows the last run that exists when the selected cycle has none yet, and counts down to the cycle end', function () {
    // The ordinary state for three weeks of every month: July was paid, the
    // August cycle is still open. The two payroll tiles say "Not started";
    // the card must still show July's figures and August's countdown.
    ($this->makeRun)(2026, 6, 100000);
    ($this->makeRun)(2026, 7, 101500, 'disbursed', ['disbursed_at' => '2026-07-30 15:00:00']);

    $this->actingAs($this->hr)
        ->get(route('hrm.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('payroll.latest.period', 'July 2026')
            ->where('payroll.latest.status', 'disbursed')
            ->where('payroll.latest.net', 101500)
            ->where('payroll.latest.net_display', '101,500.00')
            ->where('payroll.latest.delta', 1500)
            ->where('payroll.latest.delta_display', '+1,500.00')
            ->where('payroll.latest.delta_label', 'vs June 2026')
            ->where('payroll.latest.paid_on', '30 Jul 2026')
            ->where('payroll.next.period', 'August 2026')
            ->where('payroll.next.end', '2026-08-23')
            // 10 Aug to 23 Aug.
            ->where('payroll.next.days_to_end', 13)
            ->where('payroll.next.run_status', null));
});

it('moves the countdown to the following cycle once the current one has a live run', function () {
    ($this->makeRun)(2026, 7, 100000);
    ($this->makeRun)(2026, 8, 100000, 'calculated');

    $this->actingAs($this->hr)
        ->get(route('hrm.dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('payroll.latest.period', 'August 2026')
            ->where('payroll.latest.status', 'calculated')
            // A first comparison that exists shows its movement, here zero.
            ->where('payroll.latest.delta', 0)
            ->where('payroll.next.period', 'September 2026')
            ->where('payroll.next.start', '2026-08-24')
            ->where('payroll.next.end', '2026-09-23')
            ->where('payroll.next.days_to_end', 44)
            ->where('payroll.next.run_status', null));
});

it('treats a cancelled version as no run, exactly as the tiles do', function () {
    // WFL-06: a cancelled v1 is not the state of the month. The card must not
    // show it as the latest run, nor count the cycle as started because of it.
    ($this->makeRun)(2026, 8, 999999, 'cancelled');

    $this->actingAs($this->hr)
        ->get(route('hrm.dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('payroll.latest', null)
            ->where('payroll.next.period', 'August 2026')
            ->where('payroll.next.run_status', null));
});

it('withholds the payroll card from a department manager, as it withholds the payroll tiles', function () {
    ($this->makeRun)(2026, 7, 100000);

    $this->actingAs($this->manager)
        ->get(route('hrm.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('department_locked', true)
            ->where('payroll', null)
            ->where('widgets', fn ($widgets) => collect($widgets)->pluck('key')->doesntContain('payroll_current_status')));

    // Attendance-only: the same, through the permission rather than the scope.
    $this->actingAs($this->clerk)
        ->get(route('hrm.dashboard'))
        ->assertInertia(fn ($page) => $page->where('payroll', null));
});

/*
|--------------------------------------------------------------------------
| Staff by team
|--------------------------------------------------------------------------
*/

it('counts probation, joiners and leavers for a viewer who may open the employee register', function () {
    ($this->makeEmployee)('BDG-000001', ['employment_type' => 'probation', 'joining_date' => '2026-05-01']);
    ($this->makeEmployee)('BDG-000002', ['joining_date' => '2026-02-15']);
    // Joined last year: not a joiner this year.
    ($this->makeEmployee)('BDG-000003', ['joining_date' => '2025-11-01']);
    // Left this year. Their assignment has ended, which must not hide them.
    ($this->makeEmployee)('BDG-000004', [
        'status' => 'separated', 'separation_type' => 'resignation',
        'separation_date' => '2026-03-31', 'joining_date' => '2024-01-01',
    ], null, '2026-03-31');
    // On probation but gone: not on probation any more.
    ($this->makeEmployee)('BDG-000005', [
        'employment_type' => 'probation', 'status' => 'separated', 'separation_type' => 'resignation',
        'separation_date' => '2025-12-31', 'joining_date' => '2025-09-01',
    ], null, '2025-12-31');

    $this->actingAs($this->hr)
        ->get(route('hrm.dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('staff.on_probation', 1)
            ->where('staff.joined_this_year', 2)
            ->where('staff.left_this_year', 1));
});

it('sends no staff counts to a viewer without the employee register', function () {
    ($this->makeEmployee)('BDG-000001', ['employment_type' => 'probation']);

    $this->actingAs($this->clerk)
        ->get(route('hrm.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('staff', null));
});
