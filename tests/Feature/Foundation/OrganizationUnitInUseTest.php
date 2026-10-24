<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Designation;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Models\Section;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\AttendancePolicy;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * FND-002 (audit 14 Oct 2026). Deleting a department, section, designation or
 * office that HR records still point at used to null those records out through
 * the foreign keys: a department's attendance policy became the WHOLE
 * business's policy, and assignment history lost its unit. A unit in use is now
 * refused with a sentence that points to Deactivate; an unused one still goes.
 */

beforeEach(function () {
    $this->business = Business::create(['name' => 'Org Co', 'slug' => 'org-co']);
    app(BusinessContext::class)->set($this->business->id);

    Permission::firstOrCreate(['name' => 'foundation.organization.manage', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo('foundation.organization.manage');
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->office = Office::create(['business_id' => $this->business->id, 'name' => 'HQ']);
    $this->department = Department::create(['business_id' => $this->business->id, 'office_id' => $this->office->id, 'name' => 'Warehouse']);
    $this->section = Section::create(['business_id' => $this->business->id, 'department_id' => $this->department->id, 'name' => 'Night']);
    $this->designation = Designation::create(['business_id' => $this->business->id, 'name' => 'Picker']);

    $this->employee = Employee::create(['employee_code' => 'BDG-000001', 'first_name' => 'Karim', 'last_name' => 'Ahmed',
        'employment_type' => 'permanent', 'status' => 'active']);
});

function unitOwner()
{
    return test()->actingAs(test()->owner)->withSession(['current_business_id' => test()->business->id]);
}

function assignEverything(): EmployeeAssignment
{
    return EmployeeAssignment::create([
        'employee_id' => test()->employee->id,
        'office_id' => test()->office->id,
        'department_id' => test()->department->id,
        'section_id' => test()->section->id,
        'designation_id' => test()->designation->id,
        'is_primary' => true,
        'effective_from' => '2026-01-01',
    ]);
}

it('refuses to delete a department that has its own attendance policy, and the policy keeps its scope', function () {
    $section = $this->section;
    $section->delete(); // the sections guard is not what this test is about

    $policy = AttendancePolicy::create([
        'name' => 'Warehouse night', 'version' => 1, 'department_id' => $this->department->id,
        'config' => [], 'effective_from' => '2026-09-01',
    ]);

    unitOwner()->delete(route('organization.departments.destroy', $this->department))
        ->assertSessionHas('error', fn (string $m) => str_contains($m, 'attendance policy') && str_contains($m, 'Deactivate'));

    expect(Department::find($this->department->id))->not->toBeNull()
        ->and($policy->fresh()->department_id)->toBe($this->department->id);
});

it('refuses to delete a department, section, designation or office an employee assignment points at', function () {
    $assignment = assignEverything();

    unitOwner()->delete(route('organization.sections.destroy', $this->section))->assertSessionHas('error');
    unitOwner()->delete(route('organization.designations.destroy', $this->designation))->assertSessionHas('error');

    // A department with no sections left, still on the assignment.
    DB::table('employee_assignments')->where('id', $assignment->id)->update(['section_id' => null]);
    $this->section->delete();
    unitOwner()->delete(route('organization.departments.destroy', $this->department))->assertSessionHas('error');

    // An office with no departments left, still on the assignment.
    DB::table('employee_assignments')->where('id', $assignment->id)->update(['department_id' => null]);
    $this->department->delete();
    unitOwner()->delete(route('organization.offices.destroy', $this->office))->assertSessionHas('error');

    expect(Office::find($this->office->id))->not->toBeNull()
        ->and(Designation::find($this->designation->id))->not->toBeNull()
        ->and($assignment->fresh()->designation_id)->toBe($this->designation->id)
        ->and($assignment->fresh()->office_id)->toBe($this->office->id);
});

it('refuses to delete a designation a job opening is recruiting for', function () {
    DB::table('job_openings')->insert([
        'business_id' => $this->business->id, 'title' => 'Picker', 'designation_id' => $this->designation->id,
        'openings' => 1, 'status' => 'open', 'opened_on' => '2026-10-01', 'created_at' => now(), 'updated_at' => now(),
    ]);

    unitOwner()->delete(route('organization.designations.destroy', $this->designation))->assertSessionHas('error');

    expect(Designation::find($this->designation->id))->not->toBeNull();
});

it('still deletes units nothing points at', function () {
    unitOwner()->delete(route('organization.sections.destroy', $this->section), ['reason_text' => 'Never used it'])->assertSessionHas('success');
    unitOwner()->delete(route('organization.departments.destroy', $this->department), ['reason_text' => 'Never used it'])->assertSessionHas('success');
    unitOwner()->delete(route('organization.designations.destroy', $this->designation), ['reason_text' => 'Never used it'])->assertSessionHas('success');
    unitOwner()->delete(route('organization.offices.destroy', $this->office), ['reason_text' => 'Never used it'])->assertSessionHas('success');

    expect(Office::find($this->office->id))->toBeNull()
        ->and(Designation::find($this->designation->id))->toBeNull();
});
