<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\Payslip;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Joy, 1 Sep 2026: "each month how much salary we are paying, this must have
 * in record, so after 1 year or 10 years i can see."
 *
 * The register reads finished runs and recomputes nothing, counts a month
 * ONCE (its surviving version), and only calls money PAID when it actually
 * left the business.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Register Co', 'slug' => 'register-co']);
    app(BusinessContext::class)->set($this->business->id);

    Permission::firstOrCreate(['name' => 'hrm.payroll.manage', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo('hrm.payroll.manage');
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);
    $this->user->assignRole('Owner');

    $office = Office::create(['business_id' => $this->business->id, 'name' => 'Head office', 'is_active' => true]);

    $this->employee = Employee::create([
        'business_id' => $this->business->id, 'employee_code' => 'BDG-000001',
        'first_name' => 'Rana', 'last_name' => 'Mia',
        'employment_type' => 'permanent', 'status' => 'active',
        'joining_date' => '2025-01-01',
    ]);
    EmployeeAssignment::create([
        'business_id' => $this->business->id, 'employee_id' => $this->employee->id,
        'office_id' => $office->id, 'is_primary' => true, 'effective_from' => '2025-01-01',
    ]);

    $this->period = function (int $year, int $month) {
        return PayrollPeriod::firstOrCreate(
            ['business_id' => $this->business->id, 'year' => $year, 'month' => $month],
            [
                'start_date' => sprintf('%04d-%02d-24', $month === 1 ? $year - 1 : $year, $month === 1 ? 12 : $month - 1),
                'end_date' => sprintf('%04d-%02d-23', $year, $month),
                'divisor_days' => 30,
            ],
        );
    };

    $this->run = fn (PayrollPeriod $period, string $status, float $net, int $version = 1, array $extra = []) => PayrollRun::create([
        'business_id' => $this->business->id,
        'payroll_period_id' => $period->id,
        'version' => $version,
        'status' => $status,
        'employee_count' => 1,
        'total_earnings' => $net + 1000,
        'total_deductions' => 1000,
        'total_net' => $net,
        ...$extra,
    ]);
});

it('lists every payroll month the business has run, newest first', function () {
    ($this->run)(($this->period)(2026, 6), 'locked', 100000, 1, ['disbursed_at' => '2026-06-28']);
    ($this->run)(($this->period)(2026, 7), 'disbursed', 110000, 1, ['disbursed_at' => '2026-07-28']);
    ($this->run)(($this->period)(2026, 8), 'calculated', 239048.66);

    $this->actingAs($this->user)->get(route('hrm.payroll.history'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('hrm/payroll/history')
            ->has('years', 1)
            ->where('years.0.year', 2026)
            ->has('years.0.months', 3)
            ->where('years.0.months.0.label', 'August 2026')
            ->where('years.0.months.2.label', 'June 2026')
            // Only what actually left the business counts as paid.
            ->where('years.0.net_paid', fn ($v) => (float) $v === 210000.0)
            ->where('years.0.net_unpaid', fn ($v) => (float) $v === 239048.66)
            ->where('grand_total_paid', fn ($v) => (float) $v === 210000.0)
            ->where('months_paid', 2));
});

it('counts a reopened month once, at its surviving version', function () {
    $period = ($this->period)(2026, 7);
    ($this->run)($period, 'cancelled', 500000, 1);
    ($this->run)($period, 'disbursed', 110000, 2, ['disbursed_at' => '2026-07-28']);

    $this->actingAs($this->user)->get(route('hrm.payroll.history'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('years.0.months', 1)
            ->where('years.0.months.0.version', 2)
            ->where('years.0.months.0.net', fn ($v) => (float) $v === 110000.0)
            ->where('grand_total_paid', fn ($v) => (float) $v === 110000.0));
});

it('separates the years so a ten-year record stays readable', function () {
    ($this->run)(($this->period)(2025, 12), 'locked', 90000, 1, ['disbursed_at' => '2025-12-28']);
    ($this->run)(($this->period)(2026, 1), 'locked', 95000, 1, ['disbursed_at' => '2026-01-28']);

    $this->actingAs($this->user)->get(route('hrm.payroll.history'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('years', 2)
            ->where('years.0.year', 2026)
            ->where('years.0.net_paid', fn ($v) => (float) $v === 95000.0)
            ->where('years.1.year', 2025)
            ->where('years.1.net_paid', fn ($v) => (float) $v === 90000.0)
            ->where('grand_total_paid', fn ($v) => (float) $v === 185000.0));
});

it('gives one person their own wage record, from the payslips as paid', function () {
    $run = ($this->run)(($this->period)(2026, 7), 'disbursed', 110000, 1, ['disbursed_at' => '2026-07-28']);

    Payslip::create([
        'business_id' => $this->business->id,
        'payroll_run_id' => $run->id,
        'employee_id' => $this->employee->id,
        'gross_salary' => 30000, 'total_earnings' => 32000, 'total_deductions' => 2000, 'net' => 30000,
    ]);

    $this->actingAs($this->user)->get(route('hrm.payroll.history', ['employee_id' => $this->employee->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('employee.employee_code', 'BDG-000001')
            ->has('years.0.months', 1)
            ->where('years.0.months.0.gross', fn ($v) => (float) $v === 30000.0)
            ->where('years.0.months.0.net', fn ($v) => (float) $v === 30000.0)
            ->where('grand_total_paid', fn ($v) => (float) $v === 30000.0));
});

it('keeps the register behind the payroll permission', function () {
    $outsider = User::factory()->create();
    $outsider->businesses()->attach($this->business->id);

    $this->actingAs($outsider)->get(route('hrm.payroll.history'))->assertForbidden();
});

it('is reachable from the sidebar, not just by URL', function () {
    // ⚠⚠ Nine engines in this system have shipped complete and unreachable.
    expect(file_get_contents(resource_path('js/components/app-sidebar.tsx')))
        ->toContain("url: '/hrm/payroll/history'");
});
