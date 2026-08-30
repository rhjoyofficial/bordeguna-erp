<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The settings navigation, and the permissions behind it.
 *
 * Joy, 30 August 2026: settings moves into the sidebar and what somebody sees
 * depends on what they may do.
 *
 * ⚠⚠ THE BUG THIS PINS. The old in-page settings nav showed "Reasons" to
 * EVERYBODY, and ReasonController authorizes `inventory.catalog.manage`. So a
 * user without that permission was offered a link by the interface and walked
 * into a 403. A link nobody may open is worse than a missing one: it reads as a
 * broken system rather than as a permission they do not hold.
 *
 * ⚠ The flags are asserted here, on the server, rather than by reading the
 * rendered sidebar. The sidebar builds itself from exactly these props, and a
 * test that scraped markup would pass while the gate underneath was wrong.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Nav Co', 'slug' => 'nav-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['inventory.catalog.manage', 'foundation.users.manage', 'foundation.organization.manage'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    Role::firstOrCreate(['name' => 'Catalogue', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo('inventory.catalog.manage');
    Role::firstOrCreate(['name' => 'Nobody', 'business_id' => $this->business->id, 'guard_name' => 'web']);

    $this->plain = User::factory()->create();
    $this->plain->businesses()->attach($this->business->id);
    $this->plain->assignRole('Nobody');

    $this->catalogue = User::factory()->create();
    $this->catalogue->businesses()->attach($this->business->id);
    $this->catalogue->assignRole('Catalogue');
});

it('does not offer the reasons screen to somebody who would be refused it', function () {
    $this->actingAs($this->plain)->get(route('profile.edit'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canManageReasons', false));

    // And the refusal it was walking into is real, so the flag is not academic.
    $this->actingAs($this->plain)->get(route('reasons.index'))->assertForbidden();
});

it('offers it to somebody who may actually use it', function () {
    $this->actingAs($this->catalogue)->get(route('profile.edit'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canManageReasons', true));

    $this->actingAs($this->catalogue)->get(route('reasons.index'))->assertOk();
});

it('keeps the admin settings away from someone with no admin permission', function () {
    $this->actingAs($this->plain)->get(route('profile.edit'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canManageUsers', false)
            ->where('canManageOrganization', false)
        );
});

it('has no audit trail screen any more', function () {
    // Joy asked for it removed on 30 August 2026. Pinned so it does not come
    // back by accident with a half-wired route.
    expect(Route::has('audit.index'))->toBeFalse();

    // ⚠ NOT a 404. `Route::fallback` in routes/web.php sends every unknown URL
    // to the dashboard, so an old bookmark lands somewhere useful instead of on
    // an error page. Asserting 404 here would be asserting the wrong system.
    $this->actingAs($this->plain)->get('/settings/audit')->assertRedirect();
});
