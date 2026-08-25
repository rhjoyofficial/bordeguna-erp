<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Support\PayrollCycle;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The 24-to-23 employee month, as a setting (Joy's final decision, 23 Aug
 * 2026). The boundary tests are the substance: a cycle wrong by one day at
 * either end pays or docks somebody for a day that belongs to the other month.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Cycle Co', 'slug' => 'cycle-co']);
    app(BusinessContext::class)->set($this->business->id);
});

it('defaults every business to the 24th, which is the behaviour C7b hardcoded', function () {
    expect($this->business->fresh()->payroll_cycle_start_day)->toBe(24)
        ->and(PayrollCycle::startDay())->toBe(24);
});

it('runs August from 24 July to 23 August', function () {
    [$start, $end] = PayrollCycle::forMonth(2026, 8);

    expect($start->toDateString())->toBe('2026-07-24')
        ->and($end->toDateString())->toBe('2026-08-23');
});

it('survives February, where the cycle holds days no calendar February has', function () {
    // 24 Jan to 23 Feb: contains 29, 30, 31 January. A grid keyed by
    // day-of-month dies here; the bounds must not.
    [$start, $end] = PayrollCycle::forMonth(2026, 2);

    expect($start->toDateString())->toBe('2026-01-24')
        ->and($end->toDateString())->toBe('2026-02-23')
        ->and((int) $start->diffInDays($end) + 1)->toBe(31);
});

it('treats start day 1 as the plain calendar month, not day zero', function () {
    [$start, $end] = PayrollCycle::forMonth(2026, 8, 1);

    expect($start->toDateString())->toBe('2026-08-01')
        ->and($end->toDateString())->toBe('2026-08-31');
});

it('places a date in the cycle it belongs to, exactly at the boundary', function () {
    // 23 August is the LAST day of "August"; 24 August is the FIRST of
    // "September". One day apart, a whole payslip apart.
    $august = PayrollCycle::containing(Carbon::parse('2026-08-23'), 24);
    $september = PayrollCycle::containing(Carbon::parse('2026-08-24'), 24);

    expect($august['month'])->toBe(8)
        ->and($august['start']->toDateString())->toBe('2026-07-24')
        ->and($august['end']->toDateString())->toBe('2026-08-23')
        ->and($september['month'])->toBe(9)
        ->and($september['start']->toDateString())->toBe('2026-08-24')
        ->and($september['end']->toDateString())->toBe('2026-09-23');
});

it('rolls the label across a year end', function () {
    $january = PayrollCycle::containing(Carbon::parse('2026-12-28'), 24);

    expect($january['year'])->toBe(2027)
        ->and($january['month'])->toBe(1)
        ->and($january['start']->toDateString())->toBe('2026-12-24')
        ->and($january['end']->toDateString())->toBe('2027-01-23');
});

it('reads the day from the business setting', function () {
    $this->business->update(['payroll_cycle_start_day' => 16]);

    expect(PayrollCycle::startDay())->toBe(16);

    [$start, $end] = PayrollCycle::forMonth(2026, 8);

    expect($start->toDateString())->toBe('2026-07-16')
        ->and($end->toDateString())->toBe('2026-08-15');
});

it('opens a payroll period on the configured cycle', function () {
    $period = PayrollPeriod::forMonth(2026, 9);

    expect($period->start_date->toDateString())->toBe('2026-08-24')
        ->and($period->end_date->toDateString())->toBe('2026-09-23')
        ->and($period->divisor_days)->toBe(30);
});

it('keeps an already opened period on its original dates when the setting changes', function () {
    $period = PayrollPeriod::forMonth(2026, 9);
    expect($period->start_date->toDateString())->toBe('2026-08-24');

    // The business moves its cycle. A payroll already calculated on 24-to-23
    // must not silently claim it covered different days.
    $this->business->update(['payroll_cycle_start_day' => 1]);

    $again = PayrollPeriod::forMonth(2026, 9);

    expect($again->id)->toBe($period->id)
        ->and($again->start_date->toDateString())->toBe('2026-08-24')
        ->and($again->end_date->toDateString())->toBe('2026-09-23');

    // A period NOT yet opened uses the new setting.
    $october = PayrollPeriod::forMonth(2026, 10);

    expect($october->start_date->toDateString())->toBe('2026-10-01')
        ->and($october->end_date->toDateString())->toBe('2026-10-31');
});

it('lets an Owner change the day in Settings and refuses a day past the 28th', function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);
    Permission::firstOrCreate(['name' => 'foundation.organization.manage', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo('foundation.organization.manage');

    $owner = User::factory()->create();
    $owner->businesses()->attach($this->business->id);
    $owner->assignRole('Owner');

    $payload = [
        'name' => $this->business->name,
        'currency' => 'BDT',
        'timezone' => 'Asia/Dhaka',
        'fiscal_year_start_month' => 7,
        // The whole profile form posts together, so a new field on it belongs
        // in every payload that stands in for the form.
        'wfh_monthly_cap' => 5,
    ];

    // The 29th exists in some months only, so a cycle anchored there would
    // skip or double days three times a year. Refused at the edge.
    $this->actingAs($owner)
        ->patch('/settings/business', $payload + ['payroll_cycle_start_day' => 29])
        ->assertSessionHasErrors('payroll_cycle_start_day');

    $this->actingAs($owner)
        ->patch('/settings/business', $payload + ['payroll_cycle_start_day' => 24])
        ->assertSessionHasNoErrors();

    expect($this->business->fresh()->payroll_cycle_start_day)->toBe(24);
});
