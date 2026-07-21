<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Designation;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\CreateEmployee;
use App\Modules\Hrm\Enums\EmployeeStatus;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->business = Business::create(['name' => 'Xfer Co', 'slug' => 'xfer-co']);
    app(BusinessContext::class)->set($this->business->id);

    Permission::firstOrCreate(['name' => 'hrm.employees.manage', 'guard_name' => 'web']);
    foreach (['Owner', 'Staff'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }
    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()->givePermissionTo('hrm.employees.manage');
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');

    $this->officeA = Office::create(['business_id' => $this->business->id, 'name' => 'Head Office']);
    $this->officeB = Office::create(['business_id' => $this->business->id, 'name' => 'Branch']);
    $this->deptA = Department::create(['business_id' => $this->business->id, 'office_id' => $this->officeA->id, 'name' => 'Finance']);
    $this->deptB = Department::create(['business_id' => $this->business->id, 'office_id' => $this->officeB->id, 'name' => 'Sales']);
    $this->designation = Designation::create(['business_id' => $this->business->id, 'name' => 'Officer']);

    $this->employee = app(CreateEmployee::class)->handle(
        ['first_name' => 'Karim', 'last_name' => 'A', 'employment_type' => 'permanent', 'joining_date' => '2025-01-01'],
        ['office_id' => $this->officeA->id],
    );
    $this->employee->update(['status' => EmployeeStatus::Active]);

    $this->colleague = app(CreateEmployee::class)->handle(
        ['first_name' => 'Boss', 'last_name' => 'B', 'employment_type' => 'permanent', 'joining_date' => '2024-01-01'],
    );
    $this->colleague->update(['status' => EmployeeStatus::Active]);
});

function xferOwner()
{
    return test()->actingAs(test()->owner)->withSession(['current_business_id' => test()->business->id]);
}

it('reassigns an employee, closing the old assignment and opening a new one', function () {
    $old = $this->employee->currentAssignment();

    xferOwner()->post(route('hrm.employees.transfer', $this->employee), [
        'effective_from' => '2026-06-01',
        'office_id' => $this->officeB->id,
        'department_id' => $this->deptB->id,
        'designation_id' => $this->designation->id,
        'supervisor_id' => $this->colleague->id,
    ])->assertRedirect();

    expect($old->fresh()->effective_to->toDateString())->toBe('2026-06-01');

    app(BusinessContext::class)->set($this->business->id);
    $current = $this->employee->fresh()->currentAssignment();
    expect($current->office_id)->toBe($this->officeB->id);
    expect($current->department_id)->toBe($this->deptB->id);
    expect($current->supervisor_id)->toBe($this->colleague->id);
    expect($current->effective_from->toDateString())->toBe('2026-06-01');
    expect($current->is_primary)->toBeTrue();
    expect($this->employee->assignments()->count())->toBe(2);
});

it('will not reassign a separated employee', function () {
    $this->employee->update(['status' => EmployeeStatus::Separated]);

    xferOwner()->post(route('hrm.employees.transfer', $this->employee), ['effective_from' => '2026-06-01'])
        ->assertSessionHas('error');
});

it('rejects an effective date before the current assignment started', function () {
    xferOwner()->post(route('hrm.employees.transfer', $this->employee), [
        'effective_from' => '2024-12-01',
        'office_id' => $this->officeB->id,
    ])->assertSessionHasErrors('effective_from');
});

it('rejects a department that does not belong to the selected office', function () {
    xferOwner()->post(route('hrm.employees.transfer', $this->employee), [
        'effective_from' => '2026-06-01',
        'office_id' => $this->officeB->id,
        'department_id' => $this->deptA->id,
    ])->assertSessionHasErrors('department_id');
});

it('rejects the employee as their own supervisor', function () {
    xferOwner()->post(route('hrm.employees.transfer', $this->employee), [
        'effective_from' => '2026-06-01',
        'supervisor_id' => $this->employee->id,
    ])->assertSessionHasErrors('supervisor_id');
});

it('blocks a non-admin from reassigning', function () {
    $this->actingAs($this->staff)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.employees.transfer', $this->employee), ['effective_from' => '2026-06-01'])
        ->assertForbidden();
});
