<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->business = Business::create(['name' => 'Emp Co', 'slug' => 'emp-co']);
    $this->otherBusiness = Business::create(['name' => 'Other Emp', 'slug' => 'other-emp']);
    app(BusinessContext::class)->set($this->business->id);

    Permission::firstOrCreate(['name' => 'hrm.employees.manage', 'guard_name' => 'web']);
    foreach ([$this->business, $this->otherBusiness] as $biz) {
        foreach (['Owner', 'Staff'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'business_id' => $biz->id, 'guard_name' => 'web']);
        }
        Role::where('business_id', $biz->id)->where('name', 'Owner')->first()->givePermissionTo('hrm.employees.manage');
    }
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');
});

function empOwner()
{
    return test()->actingAs(test()->owner)->withSession(['current_business_id' => test()->business->id]);
}

it('creates an employee with a code and a primary assignment to the business', function () {
    empOwner()->post(route('hrm.employees.store'), [
        'first_name' => 'Rahim',
        'last_name' => 'Uddin',
        'employment_type' => 'permanent',
        'joining_date' => '2026-01-01',
    ])->assertRedirect();

    $employee = Employee::sole();
    expect($employee->employee_code)->toBe('BDG-000001');
    expect($employee->fullName())->toBe('Rahim Uddin');

    $assignment = $employee->assignments()->first();
    expect($assignment->business_id)->toBe($this->business->id);
    expect($assignment->is_primary)->toBeTrue();
    expect($assignment->effective_from->toDateString())->toBe('2026-01-01');
});

it('assigns sequential global employee codes', function () {
    empOwner()->post(route('hrm.employees.store'), ['first_name' => 'A', 'last_name' => 'One', 'employment_type' => 'permanent'])->assertRedirect();
    empOwner()->post(route('hrm.employees.store'), ['first_name' => 'B', 'last_name' => 'Two', 'employment_type' => 'contract'])->assertRedirect();

    expect(Employee::orderBy('id')->pluck('employee_code')->all())->toBe(['BDG-000001', 'BDG-000002']);
});

it('continues the sequence past gaps left by departed employees', function () {
    // ⚠⚠ THE REGRESSION THAT REAL DATA EXPOSED. The generator was
    // `Employee::count() + 1`. Bor De Guna's nine real employees are numbered
    // 1, 2, 10, 12, 16, 19, 20, 21, 22 — twelve gaps left by people who have
    // left. A count of 9 would have issued BDG-000010 to the next hire, which
    // already belongs to somebody: a unique-constraint failure that blocks
    // hiring at best, two people sharing a payroll identity at worst.
    //
    // EMP-02 makes a code an immutable identity, so the gaps are meaningful and
    // must never be refilled — a departed employee's number stays theirs,
    // because it is stamped on payslips and reports that must remain readable.
    foreach (['BDG-000001', 'BDG-000002', 'BDG-000010', 'BDG-000022'] as $code) {
        Employee::create([
            'employee_code' => $code, 'first_name' => 'Existing', 'last_name' => $code,
            'employment_type' => 'permanent',
        ]);
    }

    empOwner()->post(route('hrm.employees.store'), [
        'first_name' => 'New', 'last_name' => 'Hire', 'employment_type' => 'permanent',
    ])->assertRedirect();

    // Highest + 1, never count + 1 — which would have produced BDG-000005.
    expect(Employee::orderByDesc('id')->value('employee_code'))->toBe('BDG-000023');
});

it('runs in Bangladesh time so device punches and web clock-ins agree', function () {
    // Correction C12. Under UTC the ZKTeco import stored the device's Dhaka
    // wall-clock while ESS clock-in stored now() — six hours apart in the same
    // column, so shift comparison and lateness were wrong for one of them.
    expect(config('app.timezone'))->toBe('Asia/Dhaka');
});

it('lists an employee only in the business they are assigned to', function () {
    empOwner()->post(route('hrm.employees.store'), ['first_name' => 'Scoped', 'last_name' => 'Person', 'employment_type' => 'permanent'])->assertRedirect();

    empOwner()->get(route('hrm.employees.index'))->assertOk();

    // The controller's "who works here" query, exercised directly per business.
    $activeInBusiness = fn () => Employee::whereHas('assignments', fn ($query) => $query->active()->where('is_primary', true))->count();

    app(BusinessContext::class)->set($this->business->id);
    expect($activeInBusiness())->toBe(1);

    app(BusinessContext::class)->set($this->otherBusiness->id);
    expect($activeInBusiness())->toBe(0);

    app(BusinessContext::class)->set($this->business->id);
});

it('scopes effective-dated assignments by date', function () {
    $employee = Employee::create(['employee_code' => 'BDG-000001', 'first_name' => 'X', 'last_name' => 'Y', 'employment_type' => 'permanent']);
    EmployeeAssignment::create(['business_id' => $this->business->id, 'employee_id' => $employee->id, 'is_primary' => true, 'effective_from' => '2025-01-01', 'effective_to' => '2025-06-30']);
    EmployeeAssignment::create(['business_id' => $this->business->id, 'employee_id' => $employee->id, 'is_primary' => true, 'effective_from' => '2025-07-01', 'effective_to' => null]);

    expect(EmployeeAssignment::active()->count())->toBe(1);
    expect(EmployeeAssignment::activeOn('2025-03-01')->count())->toBe(1);
    expect(EmployeeAssignment::activeOn('2024-01-01')->count())->toBe(0);
});

it('404s an employee not assigned to the current business', function () {
    app(BusinessContext::class)->set($this->otherBusiness->id);
    $employee = Employee::create(['employee_code' => 'BDG-000009', 'first_name' => 'Their', 'last_name' => 'Emp', 'employment_type' => 'permanent']);
    EmployeeAssignment::create(['business_id' => $this->otherBusiness->id, 'employee_id' => $employee->id, 'is_primary' => true, 'effective_from' => now()->toDateString()]);
    app(BusinessContext::class)->set($this->business->id);

    empOwner()->get(route('hrm.employees.show', $employee))->assertNotFound();
});

it('blocks a non-admin from employee management', function () {
    $this->actingAs($this->staff)->withSession(['current_business_id' => $this->business->id])
        ->get(route('hrm.employees.index'))->assertForbidden();

    $this->actingAs($this->staff)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.employees.store'), ['first_name' => 'X', 'last_name' => 'Y', 'employment_type' => 'permanent'])->assertForbidden();
});

it('rejects an invalid employment type', function () {
    empOwner()->post(route('hrm.employees.store'), ['first_name' => 'X', 'last_name' => 'Y', 'employment_type' => 'wizard'])
        ->assertSessionHasErrors('employment_type');
});

// =============================================================================
// A date of birth is the key to the employee's own payslip (SAL-07 / C9b), so a
// plausible-looking wrong one locks them out with no error anywhere.
// =============================================================================

it('refuses a date of birth that would make an employee four months old', function () {
    // ⚠⚠ The real input, 2026-08-16: "11 April 2026" was offered for a serving
    // employee. The rule in force was `before:today`, and that date IS before
    // today, so nothing would have stopped it being saved and believed.
    empOwner()->post(route('hrm.employees.store'), [
        'first_name' => 'Test', 'last_name' => 'Person',
        'employment_type' => 'permanent',
        'date_of_birth' => '2026-04-11',
    ])->assertSessionHasErrors('date_of_birth');
});

it('refuses a date of birth older than any working life', function () {
    empOwner()->post(route('hrm.employees.store'), [
        'first_name' => 'Test', 'last_name' => 'Person',
        'employment_type' => 'permanent',
        'date_of_birth' => '1890-01-01',
    ])->assertSessionHasErrors('date_of_birth');
});

it('accepts an ordinary date of birth', function () {
    empOwner()->post(route('hrm.employees.store'), [
        'first_name' => 'Test', 'last_name' => 'Person',
        'employment_type' => 'permanent',
        'date_of_birth' => '1992-12-27',
    ])->assertSessionHasNoErrors();
});

// ⚠ Joy, 24 Aug 2026: "employee lists must order by employee_code, nothing
// else". Every listing and picker follows it, so the same person sits in the
// same place on every screen. Asserted on the index because that is the list
// people read most, and because name order is the tempting default a future
// change would drift back to - the codes here are deliberately in the opposite
// order to the names.
it('lists employees in employee code order, never by name', function () {
    $names = [['BDG-000003', 'Ayesha'], ['BDG-000001', 'Zahir'], ['BDG-000002', 'Mahmud']];

    foreach ($names as [$code, $first]) {
        $employee = Employee::create([
            'employee_code' => $code,
            'first_name' => $first,
            'last_name' => 'Khan',
            'employment_type' => 'permanent',
            'status' => 'active',
        ]);
        EmployeeAssignment::create([
            'employee_id' => $employee->id,
            'business_id' => $this->business->id,
            'is_primary' => true,
            'effective_from' => '2026-01-01',
        ]);
    }

    empOwner()->get(route('hrm.employees.index'))
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('hrm/employees/index')
            ->where('employees.0.employee_code', 'BDG-000001')
            ->where('employees.1.employee_code', 'BDG-000002')
            ->where('employees.2.employee_code', 'BDG-000003'));
});
