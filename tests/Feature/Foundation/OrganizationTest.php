<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Designation;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Models\Section;
use App\Modules\Foundation\Support\BusinessContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->business = Business::create(['name' => 'Org Co', 'slug' => 'org-co']);
    $this->otherBusiness = Business::create(['name' => 'Other Org', 'slug' => 'other-org']);
    app(BusinessContext::class)->set($this->business->id);

    Permission::firstOrCreate(['name' => 'foundation.organization.manage', 'guard_name' => 'web']);
    foreach ([$this->business, $this->otherBusiness] as $biz) {
        foreach (['Owner', 'Staff'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'business_id' => $biz->id, 'guard_name' => 'web']);
        }
        Role::where('business_id', $biz->id)->where('name', 'Owner')->first()->givePermissionTo('foundation.organization.manage');
    }
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');
});

function orgOwner()
{
    return test()->actingAs(test()->owner)->withSession(['current_business_id' => test()->business->id]);
}

it('lets an Owner create an office', function () {
    orgOwner()->post(route('organization.offices.store'), ['name' => 'Head Office', 'code' => 'HO'])->assertRedirect();

    expect(Office::where('business_id', $this->business->id)->where('name', 'Head Office')->exists())->toBeTrue();
});

it('builds an office → department → section hierarchy scoped to the business', function () {
    orgOwner()->post(route('organization.offices.store'), ['name' => 'HQ'])->assertRedirect();
    $office = Office::where('business_id', $this->business->id)->where('name', 'HQ')->sole();

    orgOwner()->post(route('organization.departments.store'), ['office_id' => $office->id, 'name' => 'Finance'])->assertRedirect();
    $department = Department::where('office_id', $office->id)->where('name', 'Finance')->sole();
    expect($department->business_id)->toBe($this->business->id);

    orgOwner()->post(route('organization.sections.store'), ['department_id' => $department->id, 'name' => 'Payroll'])->assertRedirect();
    $section = Section::where('department_id', $department->id)->where('name', 'Payroll')->sole();
    expect($section->business_id)->toBe($this->business->id);
});

it('creates a designation', function () {
    orgOwner()->post(route('organization.designations.store'), ['name' => 'Senior Officer'])->assertRedirect();

    expect(Designation::where('business_id', $this->business->id)->where('name', 'Senior Officer')->exists())->toBeTrue();
});

it('will not delete an office that has departments', function () {
    $office = Office::create(['business_id' => $this->business->id, 'name' => 'HQ']);
    Department::create(['business_id' => $this->business->id, 'office_id' => $office->id, 'name' => 'Ops']);

    orgOwner()->delete(route('organization.offices.destroy', $office))->assertSessionHas('error');
    expect(Office::find($office->id))->not->toBeNull();
});

it('will not delete a department that has sections', function () {
    $office = Office::create(['business_id' => $this->business->id, 'name' => 'HQ']);
    $department = Department::create(['business_id' => $this->business->id, 'office_id' => $office->id, 'name' => 'Ops']);
    Section::create(['business_id' => $this->business->id, 'department_id' => $department->id, 'name' => 'Team A']);

    orgOwner()->delete(route('organization.departments.destroy', $department))->assertSessionHas('error');
    expect(Department::find($department->id))->not->toBeNull();
});

it('deletes an empty office', function () {
    $office = Office::create(['business_id' => $this->business->id, 'name' => 'Temp']);

    orgOwner()->delete(route('organization.offices.destroy', $office), ['reason_text' => 'Never used it'])->assertRedirect();
    expect(Office::find($office->id))->toBeNull();
});

it('deactivates and reactivates an office', function () {
    $office = Office::create(['business_id' => $this->business->id, 'name' => 'HQ']);

    orgOwner()->patch(route('organization.offices.toggle', $office))->assertRedirect();
    expect($office->fresh()->is_active)->toBeFalse();

    orgOwner()->patch(route('organization.offices.toggle', $office))->assertRedirect();
    expect($office->fresh()->is_active)->toBeTrue();
});

it('cannot touch an office from another business', function () {
    app(BusinessContext::class)->set($this->otherBusiness->id);
    $otherOffice = Office::create(['business_id' => $this->otherBusiness->id, 'name' => 'Theirs']);
    app(BusinessContext::class)->set($this->business->id);

    orgOwner()->patch(route('organization.offices.toggle', $otherOffice))->assertNotFound();
});

it('cannot add a department to an office from another business', function () {
    app(BusinessContext::class)->set($this->otherBusiness->id);
    $otherOffice = Office::create(['business_id' => $this->otherBusiness->id, 'name' => 'Theirs']);
    app(BusinessContext::class)->set($this->business->id);

    orgOwner()->post(route('organization.departments.store'), ['office_id' => $otherOffice->id, 'name' => 'X'])
        ->assertSessionHasErrors('office_id');
});

it('allows the same department name in different offices but not within one', function () {
    $officeA = Office::create(['business_id' => $this->business->id, 'name' => 'Office A']);
    $officeB = Office::create(['business_id' => $this->business->id, 'name' => 'Office B']);

    orgOwner()->post(route('organization.departments.store'), ['office_id' => $officeA->id, 'name' => 'Admin'])->assertRedirect();
    orgOwner()->post(route('organization.departments.store'), ['office_id' => $officeB->id, 'name' => 'Admin'])->assertRedirect();
    expect(Department::where('name', 'Admin')->count())->toBe(2);

    orgOwner()->post(route('organization.departments.store'), ['office_id' => $officeA->id, 'name' => 'Admin'])
        ->assertSessionHasErrors('name');
});

it('blocks a non-admin from organization management', function () {
    $this->actingAs($this->staff)->withSession(['current_business_id' => $this->business->id])
        ->get(route('organization.index'))->assertForbidden();

    $this->actingAs($this->staff)->withSession(['current_business_id' => $this->business->id])
        ->post(route('organization.offices.store'), ['name' => 'X'])->assertForbidden();
});

it('rejects a duplicate office name in the same business', function () {
    Office::create(['business_id' => $this->business->id, 'name' => 'HQ']);

    orgOwner()->post(route('organization.offices.store'), ['name' => 'HQ'])->assertSessionHasErrors('name');
});

// Add and rename are one small popup since 14 Oct 2026 (each list had an add
// box under it, and Rename turned the row into inputs). The page carries what
// the popup draws (names, the office code, each unit's parent through the tree)
// and the popup saves through the same routes with the same fields.
it('carries what the organization popup needs on the page, and saves through the same routes', function () {
    orgOwner()->post(route('organization.offices.store'), ['name' => 'Head Office', 'code' => 'HO'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $office = Office::where('name', 'Head Office')->sole();

    orgOwner()->post(route('organization.departments.store'), ['name' => 'Finance', 'office_id' => $office->id])
        ->assertRedirect()->assertSessionHasNoErrors();
    $department = Department::where('name', 'Finance')->sole();

    orgOwner()->post(route('organization.sections.store'), ['name' => 'Payroll', 'department_id' => $department->id])
        ->assertRedirect()->assertSessionHasNoErrors();
    $section = Section::where('name', 'Payroll')->sole();

    orgOwner()->post(route('organization.designations.store'), ['name' => 'Officer'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $designation = Designation::where('name', 'Officer')->sole();

    // A rename sends the name, and for an office the code as well.
    orgOwner()->put(route('organization.offices.update', $office), ['name' => 'Main Office', 'code' => 'MO'])->assertSessionHasNoErrors();
    orgOwner()->put(route('organization.departments.update', $department), ['name' => 'Accounts'])->assertSessionHasNoErrors();
    orgOwner()->put(route('organization.sections.update', $section), ['name' => 'Salaries'])->assertSessionHasNoErrors();
    orgOwner()->put(route('organization.designations.update', $designation), ['name' => 'Senior Officer'])->assertSessionHasNoErrors();

    orgOwner()->get(route('organization.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/organization')
            ->where('offices.0.id', $office->id)
            ->where('offices.0.name', 'Main Office')
            ->where('offices.0.code', 'MO')
            ->where('offices.0.departments.0.id', $department->id)
            ->where('offices.0.departments.0.name', 'Accounts')
            ->where('offices.0.departments.0.sections.0.id', $section->id)
            ->where('offices.0.departments.0.sections.0.name', 'Salaries')
            ->where('designations.0.id', $designation->id)
            ->where('designations.0.name', 'Senior Officer'));

    // A repeated name is refused, and the popup shows it under the box.
    orgOwner()->post(route('organization.departments.store'), ['name' => 'Accounts', 'office_id' => $office->id])
        ->assertSessionHasErrors('name');
});
