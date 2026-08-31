<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Role-based landing pages (C-LAND): what each person sees first after login.
 * The resolver never lands anybody on a page they cannot open, and the chain
 * ends at a page with no permission at all, so it cannot fall through to
 * nothing.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Landing Co', 'slug' => 'landing-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach ([
        'foundation.users.manage', 'foundation.organization.manage', 'accounting.view',
        'hrm.employees.manage', 'hrm.attendance.manage',
        'sales.sell', 'sales.reports.view', 'inventory.reports.view',
    ] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $this->userWith = function (array $permissions) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);

        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        return $user;
    };

    $this->landingFor = fn (User $user) => $this
        ->actingAs($user)
        ->withHeader('X-Business-Id', (string) $this->business->id)
        ->get('/landing');
});

it('sends each capability to its own first screen', function () {
    // The personal corner for somebody with nothing else (Joy: "normal user
    // like the Munna or others see the personal dashboard").
    ($this->landingFor)(($this->userWith)([]))->assertRedirect('/hrm/my-profile');

    // The powerful see the current dashboard.
    ($this->landingFor)(($this->userWith)(['foundation.organization.manage']))->assertRedirect('/dashboard');

    // HR sees the HR dashboard.
    ($this->landingFor)(($this->userWith)(['hrm.attendance.manage']))->assertRedirect('/hrm/dashboard');

    // A seller lands on the counter.
    ($this->landingFor)(($this->userWith)(['sales.sell']))->assertRedirect('/sales/counter');
});

it('honours a role landing assignment the holder can actually open', function () {
    $role = Role::create(['name' => 'Warehouse', 'business_id' => $this->business->id, 'guard_name' => 'web', 'landing_page' => 'clock']);

    $user = ($this->userWith)(['hrm.attendance.manage']);
    $user->assignRole($role);

    // The assignment beats the capability default (clock, not HR dashboard).
    ($this->landingFor)($user)->assertRedirect('/hrm/clock');
});

it('skips an assignment its holder cannot open, instead of landing on a 403', function () {
    $role = Role::create(['name' => 'Counter', 'business_id' => $this->business->id, 'guard_name' => 'web', 'landing_page' => 'pos_counter']);

    // No sales.sell, so the counter would refuse - the chain decides instead.
    $user = ($this->userWith)(['hrm.attendance.manage']);
    $user->assignRole($role);

    ($this->landingFor)($user)->assertRedirect('/hrm/dashboard');
});

it('saves a landing choice on the roles screen and refuses an unknown key', function () {
    $admin = ($this->userWith)(['foundation.users.manage']);
    $role = Role::create(['name' => 'Sellers', 'business_id' => $this->business->id, 'guard_name' => 'web']);

    $this->actingAs($admin)
        ->withHeader('X-Business-Id', (string) $this->business->id)
        ->put("/settings/roles/{$role->id}", ['name' => 'Sellers', 'permissions' => [], 'landing_page' => 'pos_counter'])
        ->assertSessionHasNoErrors();

    expect($role->fresh()->landing_page)->toBe('pos_counter');

    $this->actingAs($admin)
        ->withHeader('X-Business-Id', (string) $this->business->id)
        ->put("/settings/roles/{$role->id}", ['name' => 'Sellers', 'permissions' => [], 'landing_page' => 'not-a-page'])
        ->assertSessionHasErrors('landing_page');

    // Clearing works: back to the capability defaults.
    $this->actingAs($admin)
        ->withHeader('X-Business-Id', (string) $this->business->id)
        ->put("/settings/roles/{$role->id}", ['name' => 'Sellers', 'permissions' => [], 'landing_page' => null])
        ->assertSessionHasNoErrors();

    expect($role->fresh()->landing_page)->toBeNull();
});

it('routes login through the landing decision', function () {
    $user = ($this->userWith)([]);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/landing');
});
