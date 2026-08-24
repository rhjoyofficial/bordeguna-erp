<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\GrantAnnualLeaveQuota;
use App\Modules\Hrm\database\seeders\LeaveTypeSeeder;
use App\Modules\Hrm\Enums\EmploymentType;
use App\Modules\Hrm\Enums\LeaveLedgerEntryType;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Support\LeaveBalance;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Probation-aware leave (Joy's decisions, 24 Aug 2026): a probationer's whole
 * annual entitlement is sick leave, flat 3 days; confirmation flips them to
 * permanent AND grants the pro-rated permanent entitlements in one action,
 * with sick as a top-up so the year has ONE sick balance.
 *
 * Seeder quotas apply in here (casual 10 / sick 14 / earned 20) - these tests
 * pin the RULES; Joy's live numbers (9/6) are data on the live types.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Probation Co', 'slug' => 'probation-co']);
    app(BusinessContext::class)->set($this->business->id);

    foreach (['hrm.employees.manage', 'hrm.leave.manage'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    foreach (['Owner', 'Staff'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }
    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()
        ->givePermissionTo(['hrm.employees.manage', 'hrm.leave.manage']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->hr = User::factory()->create();
    $this->hr->businesses()->attach($this->business->id);
    $this->hr->assignRole('Owner');

    $this->staffUser = User::factory()->create();
    $this->staffUser->businesses()->attach($this->business->id);
    $this->staffUser->assignRole('Staff');

    (new LeaveTypeSeeder)->run();

    $this->office = Office::create(['name' => 'Head Office', 'is_active' => true]);

    $this->makeEmployee = function (string $code, EmploymentType $type, string $joined, ?int $probationMonths = null) {
        $employee = Employee::create([
            'employee_code' => $code,
            'first_name' => 'Test', 'last_name' => $code,
            'employment_type' => $type->value,
            'probation_months' => $probationMonths,
            'status' => 'active',
            'joining_date' => $joined,
        ]);
        EmployeeAssignment::create([
            'employee_id' => $employee->id,
            'office_id' => $this->office->id,
            'is_primary' => true,
            'effective_from' => $joined,
        ]);

        return $employee;
    };

    $this->sick = LeaveType::where('code', 'sick')->sole();
    $this->casual = LeaveType::where('code', 'casual')->sole();
    $this->earned = LeaveType::where('code', 'earned')->sole();

    $this->balance = fn (Employee $employee, LeaveType $type, int $year = 2026) => app(LeaveBalance::class)->for($employee->id, $type->id, $year);
});

function probationHr()
{
    return test()->actingAs(test()->hr)->withSession(['current_business_id' => test()->business->id]);
}

it('grants a probationer sick leave only, flat 3 days, never pro-rated', function () {
    // Joined mid-year: a pro-rated grant would be ~1.4 days. Flat means 3.
    $probationer = ($this->makeEmployee)('PRB-001', EmploymentType::Probation, '2026-07-09');

    app(GrantAnnualLeaveQuota::class)->handle(2026);

    expect(($this->balance)($probationer, $this->sick))->toBe(3.0)
        ->and(($this->balance)($probationer, $this->casual))->toBe(0.0)
        ->and(($this->balance)($probationer, $this->earned))->toBe(0.0)
        ->and(LeaveLedgerEntry::where('employee_id', $probationer->id)->count())->toBe(1);
});

it('keeps the permanent grant exactly as it was', function () {
    $permanent = ($this->makeEmployee)('PRM-001', EmploymentType::Permanent, '2025-03-01');

    app(GrantAnnualLeaveQuota::class)->handle(2026);

    expect(($this->balance)($permanent, $this->casual))->toBe(10.0)
        ->and(($this->balance)($permanent, $this->sick))->toBe(14.0)
        ->and(($this->balance)($permanent, $this->earned))->toBe(20.0);
});

it('confirms from the screen: flips the type, records the date, grants pro-rata with sick as a top-up', function () {
    $probationer = ($this->makeEmployee)('PRB-002', EmploymentType::Probation, '2025-09-01');
    app(GrantAnnualLeaveQuota::class)->handle(2026);

    probationHr()->post(route('hrm.employees.confirm', $probationer), [
        'confirmation_date' => '2026-03-01',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $probationer->refresh();

    // 306 employed days of 365 remain from 1 Mar: casual 10 -> 8.0,
    // earned 20 -> 16.5, sick 14 -> 11.5 of which 3 already granted.
    expect($probationer->employment_type)->toBe(EmploymentType::Permanent)
        ->and($probationer->confirmation_date->toDateString())->toBe('2026-03-01')
        ->and(($this->balance)($probationer, $this->casual))->toBe(8.0)
        ->and(($this->balance)($probationer, $this->earned))->toBe(16.5)
        ->and(($this->balance)($probationer, $this->sick))->toBe(11.5);
});

it('never tops sick up below what the probationer already holds', function () {
    // Confirmed with 12 days of the year left: every pro-rated figure floors
    // to 0 or 0.5, so the top-up must be zero, not negative.
    $probationer = ($this->makeEmployee)('PRB-003', EmploymentType::Probation, '2026-01-05');
    app(GrantAnnualLeaveQuota::class)->handle(2026);

    probationHr()->post(route('hrm.employees.confirm', $probationer), [
        'confirmation_date' => '2026-12-20',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(($this->balance)($probationer, $this->sick))->toBe(3.0)
        ->and(($this->balance)($probationer, $this->casual))->toBe(0.0);
});

it('keeps one sick balance across the year: probation days taken stay deducted', function () {
    $probationer = ($this->makeEmployee)('PRB-004', EmploymentType::Probation, '2025-11-01');
    app(GrantAnnualLeaveQuota::class)->handle(2026);

    LeaveLedgerEntry::create([
        'employee_id' => $probationer->id,
        'leave_type_id' => $this->sick->id,
        'year' => 2026,
        'entry_type' => LeaveLedgerEntryType::Taken,
        'days' => -2,
        'occurred_on' => '2026-02-02',
    ]);

    probationHr()->post(route('hrm.employees.confirm', $probationer), [
        'confirmation_date' => '2026-03-01',
    ])->assertRedirect()->assertSessionHasNoErrors();

    // Granted 3, took 2, topped up to the pro-rated 11.5 granted-total:
    // balance is 11.5 - 2, never a fresh untouched 11.5.
    expect(($this->balance)($probationer, $this->sick))->toBe(9.5);
});

it('does not double-grant when the annual grant reruns after a confirmation', function () {
    $probationer = ($this->makeEmployee)('PRB-005', EmploymentType::Probation, '2025-09-01');
    app(GrantAnnualLeaveQuota::class)->handle(2026);

    probationHr()->post(route('hrm.employees.confirm', $probationer), [
        'confirmation_date' => '2026-03-01',
    ])->assertRedirect();

    app(GrantAnnualLeaveQuota::class)->handle(2026);

    expect(($this->balance)($probationer->fresh(), $this->casual))->toBe(8.0)
        ->and(($this->balance)($probationer->fresh(), $this->sick))->toBe(11.5);
});

it('still grants an employee whose confirmation date is historical master data', function () {
    // Musa's shape: confirmed years-to-months before the confirm action ever
    // existed, the date typed in as master data. No confirm ledger entries -
    // the annual grant must not mistake him for an already-granted case.
    $employee = ($this->makeEmployee)('PRM-002', EmploymentType::Permanent, '2025-09-01');
    $employee->update(['confirmation_date' => '2026-03-01']);

    app(GrantAnnualLeaveQuota::class)->handle(2026);

    expect(($this->balance)($employee, $this->casual))->toBe(10.0)
        ->and(($this->balance)($employee, $this->sick))->toBe(14.0);
});

it('refuses to confirm an employee who is not on probation', function () {
    $permanent = ($this->makeEmployee)('PRM-003', EmploymentType::Permanent, '2025-03-01');

    probationHr()->post(route('hrm.employees.confirm', $permanent), [
        'confirmation_date' => '2026-03-01',
    ])->assertRedirect()->assertSessionHas('error');

    expect(LeaveLedgerEntry::where('employee_id', $permanent->id)->count())->toBe(0);
});

it('blocks a user without the employees permission from confirming', function () {
    $probationer = ($this->makeEmployee)('PRB-006', EmploymentType::Probation, '2026-05-09');

    test()->actingAs(test()->staffUser)->withSession(['current_business_id' => test()->business->id])
        ->post(route('hrm.employees.confirm', $probationer), ['confirmation_date' => '2026-11-09'])
        ->assertForbidden();
});

it('shows the confirm control and the due date on the employee record', function () {
    $probationer = ($this->makeEmployee)('PRB-007', EmploymentType::Probation, '2026-05-09');

    probationHr()->get(route('hrm.employees.show', $probationer))
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('hrm/employees/show')
            ->where('can_confirm', true)
            // 9 May + the 6-month default.
            ->where('probation_due', '2026-11-09'));
});

it('computes the due date from a per-employee probation length', function () {
    $short = ($this->makeEmployee)('PRB-008', EmploymentType::Probation, '2026-05-09', 3);

    expect($short->probationDueOn()->toDateString())->toBe('2026-08-09');
});
