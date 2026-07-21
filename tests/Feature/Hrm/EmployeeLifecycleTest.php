<?php

use App\Models\User;
use App\Modules\Foundation\Models\ActivityLogEntry;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\CreateEmployee;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Enums\SeparationType;
use App\Modules\Hrm\Models\Employee;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->business = Business::create(['name' => 'Life Co', 'slug' => 'life-co']);
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
});

function lifeOwner()
{
    return test()->actingAs(test()->owner)->withSession(['current_business_id' => test()->business->id]);
}

function makeEmployee(array $data = []): Employee
{
    return app(CreateEmployee::class)->handle(array_merge([
        'first_name' => 'Test',
        'last_name' => 'Person',
        'employment_type' => 'permanent',
        'joining_date' => '2025-01-01',
    ], $data));
}

it('activates a draft employee', function () {
    $employee = makeEmployee();
    expect($employee->status)->toBe(EmployeeStatus::Draft);

    lifeOwner()->patch(route('hrm.employees.status', $employee), ['status' => 'active'])->assertRedirect();

    expect($employee->fresh()->status)->toBe(EmployeeStatus::Active);
});

it('moves an active employee on leave and back', function () {
    $employee = makeEmployee();
    $employee->update(['status' => EmployeeStatus::Active]);

    lifeOwner()->patch(route('hrm.employees.status', $employee), ['status' => 'on_leave'])->assertRedirect();
    expect($employee->fresh()->status)->toBe(EmployeeStatus::OnLeave);

    lifeOwner()->patch(route('hrm.employees.status', $employee), ['status' => 'active'])->assertRedirect();
    expect($employee->fresh()->status)->toBe(EmployeeStatus::Active);
});

it('rejects an invalid status transition', function () {
    $employee = makeEmployee(); // Draft — may only go Active

    lifeOwner()->patch(route('hrm.employees.status', $employee), ['status' => 'on_leave'])->assertSessionHasErrors('status');

    expect($employee->fresh()->status)->toBe(EmployeeStatus::Draft);
});

it('separates an employee, ends assignments, and disables their login', function () {
    $user = User::factory()->create(['is_active' => true]);
    $employee = makeEmployee(['user_id' => $user->id]);
    $employee->update(['status' => EmployeeStatus::Active]);

    lifeOwner()->post(route('hrm.employees.separate', $employee), [
        'separation_type' => 'resignation',
        'separation_date' => '2026-03-31',
        'separation_reason' => 'Moving on',
    ])->assertRedirect();

    $employee->refresh();
    expect($employee->status)->toBe(EmployeeStatus::Separated);
    expect($employee->separation_type)->toBe(SeparationType::Resignation);
    expect($employee->separation_date->toDateString())->toBe('2026-03-31');
    expect($user->fresh()->is_active)->toBeFalse();
    expect($employee->assignments()->first()->effective_to->toDateString())->toBe('2026-03-31');
});

it('drops a separated employee from the active list and blocks re-separation', function () {
    $employee = makeEmployee();
    $employee->update(['status' => EmployeeStatus::Active]);

    lifeOwner()->post(route('hrm.employees.separate', $employee), ['separation_type' => 'termination', 'separation_date' => '2026-02-28'])->assertRedirect();

    app(BusinessContext::class)->set($this->business->id);
    $activeCount = Employee::whereHas('assignments', fn ($query) => $query->active()->where('is_primary', true))->count();
    expect($activeCount)->toBe(0);

    lifeOwner()->post(route('hrm.employees.separate', $employee), ['separation_type' => 'resignation', 'separation_date' => '2026-03-01'])
        ->assertSessionHas('error');
});

it('records status changes in the activity log', function () {
    $employee = makeEmployee();

    lifeOwner()->patch(route('hrm.employees.status', $employee), ['status' => 'active'])->assertRedirect();

    app(BusinessContext::class)->set($this->business->id);
    expect(
        ActivityLogEntry::where('subject_type', Employee::class)->where('subject_id', $employee->id)->where('event', 'updated')->exists()
    )->toBeTrue();
});

it('blocks a non-admin from changing employee status or separating', function () {
    $employee = makeEmployee();

    $this->actingAs($this->staff)->withSession(['current_business_id' => $this->business->id])
        ->patch(route('hrm.employees.status', $employee), ['status' => 'active'])->assertForbidden();

    $this->actingAs($this->staff)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.employees.separate', $employee), ['separation_type' => 'resignation', 'separation_date' => '2026-01-01'])->assertForbidden();
});
