<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\database\seeders\HrmPermissionsSeeder;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Module 5, Slice 3: probation tracking.
 *
 * No new tables and no new rule: probation_months has existed since August
 * and ConfirmEmployee since before that. What was missing is the SURFACE -
 * nobody is told a probation ended, so confirmations simply do not happen.
 * The employees screen now says who is overdue, computed fresh every load.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Probation Co', 'slug' => 'probation-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }
    (new HrmPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Carbon::setTestNow('2026-09-03 12:00:00');

    $this->hr = User::factory()->create();
    $this->hr->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->hr->assignRole('Owner');

    $this->makeEmployee = function (string $code, string $joined, ?int $months, string $type = 'probation', ?string $confirmed = null) {
        $employee = Employee::create([
            'business_id' => $this->business->id,
            'employee_code' => $code,
            'first_name' => 'Emp', 'last_name' => $code,
            'joining_date' => $joined,
            'status' => 'active',
            'employment_type' => $type,
            'probation_months' => $months,
            'confirmation_date' => $confirmed,
        ]);
        EmployeeAssignment::create([
            'business_id' => $this->business->id,
            'employee_id' => $employee->id,
            'effective_from' => $joined,
            'is_primary' => true,
        ]);

        return $employee;
    };

    $this->asHr = fn () => $this->actingAs($this->hr)
        ->withSession(['current_business_id' => $this->business->id]);
});

afterEach(fn () => Carbon::setTestNow());

it('surfaces exactly the probations that have run out unconfirmed', function () {
    // 3 months from 1 May = 1 Aug: overdue by a month.
    ($this->makeEmployee)('BDG-000031', '2026-05-01', 3);
    // 3 months from 1 August = 1 November: still running.
    ($this->makeEmployee)('BDG-000032', '2026-08-01', 3);
    // Ran out but already confirmed: not a problem.
    ($this->makeEmployee)('BDG-000033', '2026-04-01', 3, 'permanent', '2026-07-01');
    // Permanent from day one: never on this list.
    ($this->makeEmployee)('BDG-000034', '2026-05-01', null, 'permanent');

    ($this->asHr)()->get(route('hrm.employees.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('probationOverdue', 1)
            ->where('probationOverdue.0.employee_code', 'BDG-000031')
            ->where('probationOverdue.0.probation_ended_on', '2026-08-01'));
});

it('stays quiet when nothing is overdue', function () {
    ($this->makeEmployee)('BDG-000035', '2026-08-15', 6);

    ($this->asHr)()->get(route('hrm.employees.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('probationOverdue', 0));
});
