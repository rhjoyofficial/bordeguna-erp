<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Notifications\SetPasswordNotification;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

function setTeam(int $businessId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($businessId);
}

beforeEach(function () {
    $this->business = Business::create(['name' => 'Team Co', 'slug' => 'team-co']);
    $this->otherBusiness = Business::create(['name' => 'Other Co', 'slug' => 'other-co']);
    app(BusinessContext::class)->set($this->business->id);

    Permission::firstOrCreate(['name' => 'foundation.users.manage', 'guard_name' => 'web']);

    foreach ([$this->business, $this->otherBusiness] as $biz) {
        foreach (['Owner', 'Manager', 'Staff'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'business_id' => $biz->id, 'guard_name' => 'web']);
        }
        Role::where('business_id', $biz->id)->where('name', 'Owner')->first()->givePermissionTo('foundation.users.manage');
    }

    setTeam($this->business->id);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');
});

it('lets an Owner add a team member with a role', function () {
    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('users.store'), [
            'name' => 'New Person',
            'email' => 'new@example.com',
            'password' => 'password123',
            'roles' => ['Manager'],
        ])->assertRedirect();

    $user = User::where('email', 'new@example.com')->sole();
    expect($user->businesses()->where('businesses.id', $this->business->id)->exists())->toBeTrue();

    setTeam($this->business->id);
    expect($user->fresh()->hasRole('Manager'))->toBeTrue();
});

it('does not leak a new member or their role into another business', function () {
    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('users.store'), [
            'name' => 'Scoped Person',
            'email' => 'scoped@example.com',
            'password' => 'password123',
            'roles' => ['Manager'],
        ])->assertRedirect();

    $user = User::where('email', 'scoped@example.com')->sole();

    expect($user->businesses()->where('businesses.id', $this->otherBusiness->id)->exists())->toBeFalse();

    setTeam($this->otherBusiness->id);
    expect($user->fresh()->hasRole('Manager'))->toBeFalse();

    setTeam($this->business->id);
    expect($user->fresh()->hasRole('Manager'))->toBeTrue();
});

it('blocks a user without foundation.users.manage from team management', function () {
    $this->actingAs($this->staff)
        ->withSession(['current_business_id' => $this->business->id])
        ->get(route('users.index'))->assertForbidden();

    $this->actingAs($this->staff)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('users.store'), [
            'name' => 'Blocked', 'email' => 'blocked@example.com', 'password' => 'password123', 'roles' => ['Staff'],
        ])->assertForbidden();

    expect(User::where('email', 'blocked@example.com')->exists())->toBeFalse();
});

it('lets a Super Admin manage the team without an explicit role', function () {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);

    $this->actingAs($superAdmin)
        ->withSession(['current_business_id' => $this->business->id])
        ->get(route('users.index'))->assertOk();
});

it('will not remove the Owner role from the last Owner', function () {
    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->put(route('users.update', $this->owner), ['roles' => ['Staff']])
        ->assertSessionHas('error');

    setTeam($this->business->id);
    expect($this->owner->fresh()->hasRole('Owner'))->toBeTrue();
});

it('will not let a user remove or deactivate themselves', function () {
    // A second Owner exists, so the last-Owner guard is not what's under test here.
    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('users.store'), [
            'name' => 'Second Owner', 'email' => 'owner2@example.com', 'password' => 'password123', 'roles' => ['Owner'],
        ])->assertRedirect();

    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->delete(route('users.destroy', $this->owner))
        ->assertSessionHas('error');
    expect($this->owner->businesses()->where('businesses.id', $this->business->id)->exists())->toBeTrue();

    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->patch(route('users.toggle-active', $this->owner))
        ->assertSessionHas('error');
    expect($this->owner->fresh()->is_active)->toBeTrue();
});

it('removes a member from the business without deleting their account', function () {
    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->delete(route('users.destroy', $this->staff))
        ->assertRedirect();

    expect(User::find($this->staff->id))->not->toBeNull();
    expect($this->staff->businesses()->where('businesses.id', $this->business->id)->exists())->toBeFalse();

    setTeam($this->business->id);
    expect($this->staff->fresh()->hasRole('Staff'))->toBeFalse();
});

it('blocks a deactivated user from logging in', function () {
    $user = User::factory()->create(['is_active' => false]);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('rejects a duplicate email when adding a member', function () {
    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('users.store'), [
            'name' => 'Dupe', 'email' => $this->staff->email, 'password' => 'password123', 'roles' => ['Staff'],
        ])->assertSessionHasErrors('email');
});

it('emails a member a link to set their own password', function () {
    Notification::fake();

    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('users.password-link', $this->staff))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    Notification::assertSentTo($this->staff, SetPasswordNotification::class);
    expect(DB::table('password_reset_tokens')->where('email', $this->staff->email)->exists())->toBeTrue();
});

it('refuses to send a password link to a deactivated member', function () {
    Notification::fake();
    $this->staff->update(['is_active' => false]);

    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('users.password-link', $this->staff))
        ->assertRedirect()
        ->assertSessionHas('error');

    Notification::assertNothingSent();
});

it('does not let a member without user management send password links', function () {
    Notification::fake();

    $this->actingAs($this->staff)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('users.password-link', $this->owner))
        ->assertForbidden();

    Notification::assertNothingSent();
});

it('does not send a password link to someone outside this business', function () {
    Notification::fake();
    $outsider = User::factory()->create();
    $outsider->businesses()->attach($this->otherBusiness->id);

    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('users.password-link', $outsider))
        ->assertNotFound();

    Notification::assertNothingSent();
});

it('shows the team screen who still has an unused password link', function () {
    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('users.password-link', $this->staff));

    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->get(route('users.index'))
        ->assertInertia(fn ($page) => $page
            ->where('members', fn ($members) => collect($members)
                ->firstWhere('id', $this->staff->id)['password_link_sent_at'] !== null)
        );
});

// Audit #27, 14 Oct 2026: one business's user admin cannot lock a shared
// person out of every business.
it('lets only an Owner deactivate a user who also works for another business', function () {
    Role::where('business_id', $this->business->id)->where('name', 'Manager')->first()->givePermissionTo('foundation.users.manage');
    setTeam($this->business->id);
    $admin = User::factory()->create();
    $admin->businesses()->attach($this->business->id);
    $admin->assignRole('Manager');

    $shared = User::factory()->create();
    $shared->businesses()->attach([$this->business->id, $this->otherBusiness->id]);
    $shared->assignRole('Staff');

    $this->actingAs($admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->patch(route('users.toggle-active', $shared))
        ->assertSessionHas('error', fn (string $message) => str_contains($message, 'Only an Owner can do that'));
    expect($shared->fresh()->is_active)->toBeTrue();

    // A user of this business only: the same admin may still deactivate.
    $this->actingAs($admin)
        ->withSession(['current_business_id' => $this->business->id])
        ->patch(route('users.toggle-active', $this->staff))
        ->assertSessionHas('success');
    expect($this->staff->fresh()->is_active)->toBeFalse();

    // And the Owner may deactivate the shared one.
    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->patch(route('users.toggle-active', $shared))
        ->assertSessionHas('success');
    expect($shared->fresh()->is_active)->toBeFalse();
});

// FND-003 (audit 14 Oct 2026). The last-Owner guard on edit and remove counted
// deactivated Owners, so the only ACTIVE Owner could be demoted or removed
// while a switched-off Owner kept the count at two.
it('will not demote or remove the only active Owner while another Owner is deactivated', function () {
    $sleeping = User::factory()->create(['is_active' => false]);
    $sleeping->businesses()->attach($this->business->id);
    setTeam($this->business->id);
    $sleeping->assignRole('Owner');

    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->put(route('users.update', $this->owner), ['roles' => ['Staff']])
        ->assertSessionHas('error');

    setTeam($this->business->id);
    expect($this->owner->fresh()->hasRole('Owner'))->toBeTrue();

    // A Manager with user management tries to remove the only active Owner.
    Role::where('business_id', $this->business->id)->where('name', 'Manager')->first()->givePermissionTo('foundation.users.manage');
    $manager = User::factory()->create();
    $manager->businesses()->attach($this->business->id);
    setTeam($this->business->id);
    $manager->assignRole('Manager');

    $this->actingAs($manager)
        ->withSession(['current_business_id' => $this->business->id])
        ->delete(route('users.destroy', $this->owner))
        ->assertSessionHas('error');

    expect($this->owner->businesses()->where('businesses.id', $this->business->id)->exists())->toBeTrue();
});

// FND-004 (audit 14 Oct 2026). A person who already has a login (in another
// business, say) was refused with Laravel's bare "The email has already been
// taken". The refusal now says what to do instead.
it('explains how to add a person whose email already has a login', function () {
    $elsewhere = User::factory()->create(['email' => 'shared@example.com']);
    $elsewhere->businesses()->attach($this->otherBusiness->id);

    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('users.store'), [
            'name' => 'Shared', 'email' => 'shared@example.com', 'password' => 'password123', 'roles' => ['Staff'],
        ])->assertSessionHasErrors(['email' => 'This email already has a login, perhaps in another business. To give that person access here, open their employee record in HR and create the login from there (it links the existing login to this business), or ask a Super Admin.']);

    expect($elsewhere->businesses()->where('businesses.id', $this->business->id)->exists())->toBeFalse();
});

// Add a member and Edit roles are popups since 14 Oct 2026. The team page
// carries what both popups draw: the roles that may be given and each member's
// current roles.
it('carries what the add-member and edit-roles popups need', function () {
    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->get(route('users.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/team')
            ->where('assignable_roles', fn ($roles) => collect($roles)->contains('Manager'))
            ->where('members', fn ($members) => is_array(collect($members)->firstWhere('id', $this->staff->id)['roles']))
        );
});
