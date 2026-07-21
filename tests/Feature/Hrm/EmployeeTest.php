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
