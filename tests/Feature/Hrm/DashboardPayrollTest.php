<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Dashboard\DashboardFilters;
use App\Modules\Hrm\Dashboard\DashboardRegistry;
use App\Modules\Hrm\Dashboard\Widgets\PendingApprovalsWidget;
use App\Modules\Hrm\Dashboard\Widgets\SalaryTotalWidget;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Reporting\ReportScope;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * RPT-04c — the payroll total, its variance, and pending approvals.
 *
 * The rule worth pinning here is C11c's, which is C10c's reapplied at tile
 * grain: a figure with no predecessor shows its change and **no percentage**.
 */
beforeEach(function () {
    // ⚠ Frozen mid-cycle, as in DashboardTest: from the 24th the live 24-23
    // payroll cycle wears next month's label, and these fixtures key runs by
    // now()'s calendar month.
    \Illuminate\Support\Carbon::setTestNow('2026-08-10 10:00:00');

    $this->business = Business::create(['name' => 'Pay Co', 'slug' => 'pay-co']);
    app(BusinessContext::class)->set($this->business->id);

    foreach (['hrm.attendance.manage', 'hrm.payroll.manage'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);
    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()
        ->givePermissionTo(['hrm.attendance.manage', 'hrm.payroll.manage']);

    $this->hr = User::factory()->create();
    $this->hr->businesses()->attach($this->business->id);
    $this->hr->assignRole('Owner');

    $this->makeRun = function (int $year, int $month, float $net, array $attributes = []) {
        $period = PayrollPeriod::firstOrCreate(
            ['business_id' => $this->business->id, 'year' => $year, 'month' => $month],
            [
                'start_date' => sprintf('%04d-%02d-24', $year, max(1, $month - 1)),
                'end_date' => sprintf('%04d-%02d-23', $year, $month),
                'divisor_days' => 30,
            ],
        );

        return PayrollRun::create([
            'business_id' => $this->business->id,
            'payroll_period_id' => $period->id,
            'version' => 1, 'status' => 'disbursed',
            'employee_count' => 2, 'total_net' => $net,
            ...$attributes,
        ]);
    };

    $this->month = now()->startOfMonth();

    $this->filtersFor = fn (User $user, array $values = []) => DashboardFilters::make($values, ReportScope::for($user));
});

afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/*
|--------------------------------------------------------------------------
| #6 — Payroll total and variance (C11c)
|--------------------------------------------------------------------------
*/

it('shows the delta against the previous period, with both periods named', function () {
    $previous = $this->month->copy()->subMonth();

    ($this->makeRun)($previous->year, $previous->month, 100000);
    $current = ($this->makeRun)($this->month->year, $this->month->month, 110000);

    $value = app(SalaryTotalWidget::class)->value(($this->filtersFor)($this->hr));

    expect($value->value)->toBe(110000.0)
        ->and($value->delta)->toBe(10000.0)
        ->and($value->deltaPercent)->toBe('+10.0%')
        ->and($value->deltaLabel)->toContain($previous->format('F Y'))
        // The drill-down is the variance report, comparing the same two runs —
        // so "why did it move" is one click and cannot disagree with the tile.
        ->and($value->drilldownReport)->toBe('payroll_variance')
        ->and($value->drilldownParameters['run_id'])->toBe($current->id);
});

it('never shows a percentage for a first payroll run', function () {
    // ⚠⚠ C10c reapplied by C11c. (current − 0) / 0 rendered as "+100%" is a
    // real-looking number describing a non-event, and on a dashboard it would
    // sit at the top of the page every month until a second run existed.
    ($this->makeRun)($this->month->year, $this->month->month, 90000);

    $value = app(SalaryTotalWidget::class)->value(($this->filtersFor)($this->hr));

    expect($value->value)->toBe(90000.0)
        ->and($value->delta)->toBeNull()
        ->and($value->deltaPercent)->toBeNull()
        // "No comparison" is information; an unexplained blank is not.
        ->and($value->note)->toContain('to compare against')
        // With nothing to compare, the drill-down is the sheet rather than a
        // variance report that would have only one side.
        ->and($value->drilldownReport)->toBe('payroll_salary_sheet');
});

it('shows no percentage when the previous run paid nothing', function () {
    // The other divide-by-zero, and it is reachable: a run can legitimately
    // total zero if every payslip was blocked.
    $previous = $this->month->copy()->subMonth();

    ($this->makeRun)($previous->year, $previous->month, 0);
    ($this->makeRun)($this->month->year, $this->month->month, 50000);

    $value = app(SalaryTotalWidget::class)->value(($this->filtersFor)($this->hr));

    expect($value->delta)->toBe(50000.0)
        ->and($value->deltaPercent)->toBeNull();
});

it('compares against the live version rather than a cancelled one', function () {
    // WFL-06: reopening produces v2 and cancels v1. Comparing against the
    // cancelled v1 would report a difference nobody was ever paid.
    $previous = $this->month->copy()->subMonth();
    $period = PayrollPeriod::create([
        'business_id' => $this->business->id, 'year' => $previous->year, 'month' => $previous->month,
        'start_date' => $previous->copy()->subMonth()->day(24)->toDateString(),
        'end_date' => $previous->copy()->day(23)->toDateString(),
        'divisor_days' => 30,
    ]);

    PayrollRun::create([
        'business_id' => $this->business->id, 'payroll_period_id' => $period->id,
        'version' => 1, 'status' => 'cancelled', 'employee_count' => 2, 'total_net' => 999999,
    ]);

    PayrollRun::create([
        'business_id' => $this->business->id, 'payroll_period_id' => $period->id,
        'version' => 2, 'status' => 'disbursed', 'employee_count' => 2, 'total_net' => 100000,
    ]);

    ($this->makeRun)($this->month->year, $this->month->month, 110000);

    $value = app(SalaryTotalWidget::class)->value(($this->filtersFor)($this->hr));

    expect($value->delta)->toBe(10000.0);
});

it('reads the run total rather than re-summing payslips', function () {
    // The tile and the payroll screen must show the same figure by construction.
    $run = ($this->makeRun)($this->month->year, $this->month->month, 101200);

    $value = app(SalaryTotalWidget::class)->value(($this->filtersFor)($this->hr));

    expect($value->value)->toBe(round((float) $run->total_net, 2));
});

/*
|--------------------------------------------------------------------------
| #3 — Pending approvals
|--------------------------------------------------------------------------
*/

it('hides the approvals tile from someone with nothing to action', function () {
    // The sidebar's own rule, reused rather than restated: legacy approvers keep
    // it at zero, everyone else earns it once something is waiting.
    $registry = app(DashboardRegistry::class);

    expect($registry->availableTo($this->hr)->keys()->all())
        ->not->toContain('approvals_pending');
});

it('shows the approvals tile to a legacy approver even at zero', function () {
    Permission::firstOrCreate(['name' => 'inventory.stock.approve', 'guard_name' => 'web']);
    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()
        ->givePermissionTo('inventory.stock.approve');

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $registry = app(DashboardRegistry::class);
    $this->hr->unsetRelation('roles')->unsetRelation('permissions');

    expect($registry->availableTo($this->hr->fresh())->keys()->all())
        ->toContain('approvals_pending');
});

it('counts exactly what the sidebar badge counts', function () {
    // ⚠ Both read ApprovalInbox::countFor. A tile and a nav badge disagreeing
    // about "pending approvals" is the drift the reporting layer exists to
    // prevent, arriving in a different corner of the application.
    $inbox = app(\App\Support\ApprovalInbox::class);

    $value = app(PendingApprovalsWidget::class)->value(($this->filtersFor)($this->hr));

    expect($value->value)->toBe($inbox->countFor($this->hr))
        ->and($value->note)->toContain('Nothing is waiting on you');
});

it('computes the approvals count live rather than from the cache', function () {
    // C11e let each widget declare its tolerance so this one could decline: a
    // minute-old count sends somebody to an inbox that has already changed.
    expect(app(PendingApprovalsWidget::class)->cacheSeconds())->toBe(0);
});

it('keeps the approvals tile out of the payroll group so ACL-02 does not withhold it', function () {
    // A count of things a manager is authorised to approve is not a salary
    // figure. Filing it under Payroll would have withheld their own to-do list.
    expect(app(PendingApprovalsWidget::class)->group())->toBe('Approvals');
});
