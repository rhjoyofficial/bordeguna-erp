<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * SECURITY REGRESSION - the finding of 2026-08-17: a deactivated user kept a
 * WORKING authenticated session on production (user 6 held 10 live session
 * rows and rendered permission-gated pages in a real browser). Login already
 * refuses inactive accounts; these tests pin the other half of the promise:
 * an existing session dies too.
 *
 * See SECURITY-INACTIVE-SESSION-DESIGN.md, C-SEC-1..3.
 *
 * ⚠ Written RED first, on Joy's instruction: every regression here failed
 * against the pre-fix code, proving it tests the hole and not the wallpaper.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Session Co', 'slug' => 'session-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Permission::findOrCreate('foundation.users.manage', 'web');
    $owner = Role::create(['name' => 'Owner', 'guard_name' => 'web', 'business_id' => $this->business->id]);
    $owner->givePermissionTo('foundation.users.manage');

    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->admin->assignRole('Owner');

    $this->target = User::factory()->create(['is_active' => true]);
    $this->target->businesses()->attach($this->business->id);

    // A real login through the real HTTP layer - the session under test is the
    // one the framework itself mints, not one a helper fakes.
    $this->loginAs = function (User $user) {
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect();
        $this->assertAuthenticatedAs($user);
    };
});

// THE regression (C-SEC-1). Before the fix this reached the dashboard.
it('rejects an already-authenticated session on the next request after deactivation', function () {
    ($this->loginAs)($this->target);

    // Deactivated out-of-band (another admin, another browser, artisan - the
    // session under test knows nothing about it).
    $this->target->forceFill(['is_active' => false])->save();

    $this->get('/dashboard')->assertRedirect('/login');
    $this->assertGuest();
});

// The permission-gated variant: the exact shape of the production finding.
it('keeps an old session out of permission-gated pages after deactivation', function () {
    ($this->loginAs)($this->admin);
    $this->get('/settings/team')->assertOk();

    $this->admin->forceFill(['is_active' => false])->save();

    $this->get('/settings/team')->assertRedirect('/login');
    $this->assertGuest();
});

// C-SEC-2 + C-SEC-3, through the Team screen's real route.
it('deletes the sessions and cycles the remember token of the user being deactivated', function () {
    // ⚠ phpunit.xml runs sessions on the array driver, where rows cannot
    // exist. The deletion is driver-guarded, so this test runs the request
    // under the driver production actually uses.
    config(['session.driver' => 'database']);

    $sessionRow = fn (User $user, string $id) => DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'payload' => base64_encode(serialize([])),
        'last_activity' => now()->getTimestamp(),
    ]);

    $sessionRow($this->target, 'target-session-1');
    $sessionRow($this->target, 'target-session-2');
    $sessionRow($this->admin, 'admin-session-1');

    $this->target->setRememberToken('token-before-deactivation');
    $this->target->save();

    $this->actingAs($this->admin)
        ->patch("/settings/team/{$this->target->id}/toggle-active")
        ->assertRedirect();

    expect($this->target->fresh()->is_active)->toBeFalse()
        // Both of the target's sessions are gone, immediately.
        ->and(DB::table('sessions')->where('user_id', $this->target->id)->count())->toBe(0)
        // ⚠ Other users' sessions are untouched - revocation is per person.
        // (The specific seeded row, not a count: the admin's own request also
        // writes a real session row under the database driver.)
        ->and(DB::table('sessions')->where('id', 'admin-session-1')->exists())->toBeTrue()
        // The remember cookie can never mint a session again.
        ->and($this->target->fresh()->getRememberToken())->not->toBe('token-before-deactivation');
});

// Control: nothing changes for a user who stays active.
it('leaves an active user\'s session completely alone', function () {
    ($this->loginAs)($this->target);

    $this->get('/dashboard')->assertOk();
    $this->get('/dashboard')->assertOk();
    $this->assertAuthenticatedAs($this->target);
});

// Control: reactivation restores the DOOR, not the old sessions.
it('lets a reactivated user log in fresh while old sessions stay dead', function () {
    ($this->loginAs)($this->target);
    $this->target->forceFill(['is_active' => false])->save();
    $this->get('/dashboard')->assertRedirect('/login');

    $this->target->forceFill(['is_active' => true])->save();

    ($this->loginAs)($this->target);
    $this->get('/dashboard')->assertOk();
});

// Control: guests and the login screen never meet this middleware.
it('leaves guests untouched', function () {
    $this->get('/login')->assertOk();
    $this->get('/dashboard')->assertRedirect('/login');
});
