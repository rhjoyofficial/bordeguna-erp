<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->business = Business::create(['name' => 'Roles Co', 'slug' => 'roles-co']);
    $this->otherBusiness = Business::create(['name' => 'Other RC', 'slug' => 'other-rc']);
    app(BusinessContext::class)->set($this->business->id);

    foreach (['foundation.users.manage', 'inventory.catalog.manage', 'purchasing.po.approve'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    foreach ([$this->business, $this->otherBusiness] as $biz) {
        foreach (['Owner', 'Manager', 'Staff'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'business_id' => $biz->id, 'guard_name' => 'web']);
        }
        Role::where('business_id', $biz->id)->where('name', 'Owner')->first()->givePermissionTo('foundation.users.manage');
    }

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');
});

function customRole(string $name, array $permissions = []): Role
{
    test()->actingAs(test()->owner)
        ->withSession(['current_business_id' => test()->business->id])
        ->post(route('roles.store'), ['name' => $name, 'permissions' => $permissions])
        ->assertRedirect();

    return Role::where('business_id', test()->business->id)->where('name', $name)->sole();
}

it('lets an Owner create a custom role with permissions', function () {
    $role = customRole('HR Admin', ['inventory.catalog.manage']);

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect($role->hasPermissionTo('inventory.catalog.manage'))->toBeTrue();
});

it('can assign a newly created role to a user', function () {
    customRole('HR Admin');

    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->put(route('users.update', $this->staff), ['roles' => ['HR Admin']])
        ->assertRedirect();

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);
    expect($this->staff->fresh()->hasRole('HR Admin'))->toBeTrue();
});

it('updates a role\'s permissions', function () {
    $role = customRole('Viewer', ['inventory.catalog.manage']);

    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->put(route('roles.update', $role), ['name' => 'Viewer', 'permissions' => ['purchasing.po.approve']])
        ->assertRedirect();

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect($role->fresh()->hasPermissionTo('purchasing.po.approve'))->toBeTrue();
    expect($role->fresh()->hasPermissionTo('inventory.catalog.manage'))->toBeFalse();
});

it('will not rename or delete a built-in role', function () {
    $owner = Role::where('business_id', $this->business->id)->where('name', 'Owner')->sole();

    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->put(route('roles.update', $owner), ['name' => 'Chief', 'permissions' => ['foundation.users.manage']])
        ->assertRedirect();
    expect($owner->fresh()->name)->toBe('Owner');

    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->delete(route('roles.destroy', $owner))
        ->assertSessionHas('error');
    expect(Role::find($owner->id))->not->toBeNull();
});

it('keeps foundation.users.manage on the Owner role even if omitted', function () {
    $owner = Role::where('business_id', $this->business->id)->where('name', 'Owner')->sole();

    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->put(route('roles.update', $owner), ['name' => 'Owner', 'permissions' => []])
        ->assertRedirect();

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect($owner->fresh()->hasPermissionTo('foundation.users.manage'))->toBeTrue();
});

it('will not delete a role that still has members', function () {
    $role = customRole('Auditor');

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);
    $this->staff->assignRole('Auditor');

    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->delete(route('roles.destroy', $role))
        ->assertSessionHas('error');
    expect(Role::find($role->id))->not->toBeNull();
});

it('deletes an empty custom role', function () {
    $role = customRole('Temp');

    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->delete(route('roles.destroy', $role))
        ->assertRedirect();
    expect(Role::find($role->id))->toBeNull();
});

it('cannot manage a role from another business', function () {
    $otherRole = Role::where('business_id', $this->otherBusiness->id)->where('name', 'Manager')->sole();

    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->put(route('roles.update', $otherRole), ['name' => 'X', 'permissions' => []])
        ->assertNotFound();
});

it('blocks a non-admin from role management', function () {
    $this->actingAs($this->staff)
        ->withSession(['current_business_id' => $this->business->id])
        ->get(route('roles.index'))->assertForbidden();

    $this->actingAs($this->staff)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('roles.store'), ['name' => 'Nope', 'permissions' => []])->assertForbidden();
});

it('rejects a duplicate role name in the same business', function () {
    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('roles.store'), ['name' => 'Manager', 'permissions' => []])
        ->assertSessionHasErrors('name');
});
