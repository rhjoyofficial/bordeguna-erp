<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\CreateEmployee;
use App\Modules\Hrm\Support\SelfScope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    Storage::fake('local');

    $this->business = Business::create(['name' => 'ESS Co', 'slug' => 'ess-co']);
    app(BusinessContext::class)->set($this->business->id);

    Permission::firstOrCreate(['name' => 'hrm.employees.manage', 'guard_name' => 'web']);
    foreach (['Owner', 'Staff'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }
    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()->givePermissionTo('hrm.employees.manage');
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->office = Office::create(['business_id' => $this->business->id, 'name' => 'Head Office']);

    // A plain business member with no HR permission, linked to their own employee.
    $this->selfUser = User::factory()->create();
    $this->selfUser->businesses()->attach($this->business->id);
    $this->selfUser->assignRole('Staff');

    $this->employee = app(CreateEmployee::class)->handle(
        ['first_name' => 'Karim', 'last_name' => 'Ahmed', 'employment_type' => 'permanent', 'joining_date' => '2025-01-01'],
        ['office_id' => $this->office->id],
    );
    $this->employee->update(['user_id' => $this->selfUser->id]);
});

function essActingAsSelf()
{
    return test()->actingAs(test()->selfUser)->withSession(['current_business_id' => test()->business->id]);
}

// --- P3 primitive ---------------------------------------------------------

it('lets the owner or a permitted user through and denies a stranger (SelfScope)', function () {
    $hr = User::factory()->create();
    $hr->businesses()->attach($this->business->id);
    $hr->assignRole('Owner');

    $stranger = User::factory()->create();
    $stranger->businesses()->attach($this->business->id);
    $stranger->assignRole('Staff');

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    // HR (has the manage permission) — allowed even though not the owner.
    expect(SelfScope::allows($hr, $this->employee, 'hrm.employees.manage'))->toBeTrue();
    // The owner — allowed by self, without the permission.
    expect(SelfScope::allows($this->selfUser, $this->employee, 'hrm.employees.manage'))->toBeTrue();
    // A business colleague who is neither HR nor the owner — denied.
    expect(SelfScope::allows($stranger, $this->employee, 'hrm.employees.manage'))->toBeFalse();
    // No user at all — denied.
    expect(SelfScope::allows(null, $this->employee, 'hrm.employees.manage'))->toBeFalse();
});

it('does not treat an unlinked employee as owned by anyone', function () {
    $this->employee->update(['user_id' => null]);

    expect(SelfScope::allows($this->selfUser, $this->employee, 'hrm.employees.manage'))->toBeFalse();
});

// --- My profile page ------------------------------------------------------

it('shows a linked user their own profile', function () {
    essActingAsSelf()->get(route('hrm.my-profile'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hrm/my-profile/show')
            ->where('employee.employee_code', $this->employee->employee_code)
            ->where('employee.name', 'Karim Ahmed')
            ->where('employee.placement.office', 'Head Office'));
});

it('redirects a user with no linked employee to the dashboard', function () {
    $unlinked = User::factory()->create();
    $unlinked->businesses()->attach($this->business->id);
    $unlinked->assignRole('Staff');

    $this->actingAs($unlinked)->withSession(['current_business_id' => $this->business->id])
        ->get(route('hrm.my-profile'))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('error');
});

it('only ever resolves the acting user\'s own record', function () {
    // A second employee linked to a different user in the same business.
    $otherUser = User::factory()->create();
    $otherUser->businesses()->attach($this->business->id);
    $otherUser->assignRole('Staff');
    $otherEmployee = app(CreateEmployee::class)->handle(
        ['first_name' => 'Rahim', 'last_name' => 'Uddin', 'employment_type' => 'contract'],
        ['office_id' => $this->office->id],
    );
    $otherEmployee->update(['user_id' => $otherUser->id]);

    $this->actingAs($otherUser)->withSession(['current_business_id' => $this->business->id])
        ->get(route('hrm.my-profile'))
        ->assertInertia(fn (Assert $page) => $page->where('employee.employee_code', $otherEmployee->employee_code));
});

// --- shared prop + attachment refactor ------------------------------------

it('exposes hasEmployeeProfile true for a linked user and false otherwise', function () {
    essActingAsSelf()->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('hasEmployeeProfile', true));

    $unlinked = User::factory()->create();
    $unlinked->businesses()->attach($this->business->id);
    $unlinked->assignRole('Staff');
    $this->actingAs($unlinked)->withSession(['current_business_id' => $this->business->id])
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('hasEmployeeProfile', false));
});

it('still lets an employee download their own document after the SelfScope refactor', function () {
    app(\App\Modules\Foundation\Actions\StoreAttachment::class)->handle(
        attachable: $this->employee,
        file: UploadedFile::fake()->create('nid.pdf', 100, 'application/pdf'),
        collection: 'national_id',
        uploadedBy: $this->selfUser,
    );
    $doc = $this->employee->attachments()->first();

    // The owner (no HR permission) can open it.
    essActingAsSelf()->get($doc->url())->assertOk();

    // A colleague who is neither HR nor the owner cannot.
    $colleague = User::factory()->create();
    $colleague->businesses()->attach($this->business->id);
    $colleague->assignRole('Staff');
    $this->actingAs($colleague)->withSession(['current_business_id' => $this->business->id])
        ->get($doc->url())->assertForbidden();
});
