<?php

use App\Models\User;
use App\Modules\Assets\database\seeders\AssetsPermissionsSeeder;
use App\Modules\Assets\Models\AssetCredential;
use App\Modules\Assets\Models\CompanyAccount;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Asset register slice 4: My accounts. The signed-in employee sees only the
 * accounts they have ACTIVE access to, and reveals their logins under the
 * policy; nothing else of the hub.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-13 10:00:00');
    // ⚠ New pages are not in the committed build manifest; the screens are proved by props, not by Vite.
    $this->withoutVite();

    $this->business = Business::create(['name' => 'Bor de Guna', 'slug' => 'bor-de-guna', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new AssetsPermissionsSeeder)->run();
    TenantScope::enter($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->user = function (string $role): User {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $user;
    };

    $this->owner = ($this->user)('Owner');
    $this->staff = ($this->user)('Staff');
    $this->nobody = ($this->user)('Staff');

    $this->office = Office::create(['business_id' => $this->business->id, 'name' => 'Corporate Office - Adabor 2']);
    $this->rakib = Employee::create(['employee_code' => 'BDG-000002', 'first_name' => 'Rakib', 'last_name' => 'Test', 'employment_type' => 'permanent', 'status' => 'active', 'joining_date' => '2026-01-01', 'user_id' => $this->staff->id]);
    EmployeeAssignment::create(['business_id' => $this->business->id, 'employee_id' => $this->rakib->id, 'office_id' => $this->office->id, 'effective_from' => '2026-01-01', 'is_primary' => true]);

    $this->account = fn (string $name, string $status = 'active') => CompanyAccount::create(['kind' => 'email', 'name' => $name, 'identifier' => $name, 'status' => $status, 'created_by' => $this->owner->id]);
    $this->grant = fn (CompanyAccount $account, ?string $revokedOn = null) => $account->access()->create([
        'employee_id' => $this->rakib->id, 'role' => 'user', 'granted_on' => '2026-10-01', 'revoked_on' => $revokedOn, 'granted_by' => $this->owner->id,
    ]);
    $this->login = fn (CompanyAccount $account, string $label, string $secret) => AssetCredential::create([
        'credentialable_type' => $account->getMorphClass(), 'credentialable_id' => $account->id, 'label' => $label, 'username' => 'u',
        'secret' => $secret, 'hint' => AssetCredential::hintFor($secret), 'created_by' => $this->owner->id,
    ]);
});

afterEach(fn () => Carbon::setTestNow());

it('shows only the accounts I have active access to, with their logins, and reveals under the policy', function () {
    $mine = ($this->account)('support@laladia.com');
    $revoked = ($this->account)('old@laladia.com');
    $closed = ($this->account)('gone@laladia.com', 'closed');
    $theirs = ($this->account)('boss@laladia.com');
    ($this->grant)($mine);
    ($this->grant)($revoked, '2026-10-05');
    ($this->grant)($closed);
    $secret = ($this->login)($mine, 'Webmail', 'mail-pass-2468');
    $bossSecret = ($this->login)($theirs, 'Webmail', 'boss-pass-1357');

    // No employee record: sent away. No access: an empty page.
    $this->actingAs($this->owner)->get('/hrm/my-accounts')->assertRedirect('/dashboard');
    Employee::create(['employee_code' => 'BDG-000009', 'first_name' => 'No', 'last_name' => 'Body', 'employment_type' => 'permanent', 'status' => 'active', 'joining_date' => '2026-01-01', 'user_id' => $this->nobody->id]);
    expect($this->actingAs($this->nobody)->get('/hrm/my-accounts')->assertOk()->original->getData()['page']['props']['accounts'])->toBe([]);

    $props = $this->actingAs($this->staff)->get('/hrm/my-accounts')->assertOk()->original->getData()['page']['props'];
    $accounts = $props['accounts'];

    expect(collect($accounts)->pluck('name')->all())->toBe(['support@laladia.com'])
        ->and($accounts[0]['role_label'])->toBe('User')
        ->and($accounts[0]['credentials']['rows'][0])->toMatchArray(['label' => 'Webmail', 'hint' => '2468'])
        ->and($accounts[0]['credentials']['can_reveal'])->toBeTrue()
        ->and($accounts[0]['credentials']['can_manage'])->toBeFalse()
        ->and(json_encode($props))->not->toContain('mail-pass-2468');

    // The hub itself stays shut to a plain employee.
    $this->actingAs($this->staff)->get('/assets/accounts')->assertForbidden();
    $this->actingAs($this->staff)->get("/assets/accounts/{$mine->id}")->assertForbidden();

    // Reveal: mine after confirming, never the boss's, and not without confirming.
    $this->actingAs($this->staff)->postJson("/assets/credentials/{$secret->id}/reveal")->assertStatus(423);
    $this->actingAs($this->staff)->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson("/assets/credentials/{$secret->id}/reveal")->assertOk()->assertExactJson(['secret' => 'mail-pass-2468']);
    $this->actingAs($this->staff)->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson("/assets/credentials/{$bossSecret->id}/reveal")->assertForbidden();
    // Managing a login is not the holder's to do.
    $this->actingAs($this->staff)->post("/assets/accounts/{$mine->id}/credentials", ['label' => 'x', 'secret' => 'y'])->assertForbidden();
    $this->actingAs($this->staff)->put("/assets/credentials/{$secret->id}", ['label' => 'x'])->assertForbidden();
    $this->actingAs($this->staff)->delete("/assets/credentials/{$secret->id}")->assertForbidden();
});

it('offers no Reveal for a login of another business, and names the business to switch to (FEA-005)', function () {
    $mine = ($this->account)('support@laladia.com');
    ($this->grant)($mine);
    $secret = ($this->login)($mine, 'Webmail', 'mail-pass-2468');

    // Rakib also works in a second business, as Staff there too.
    $bionic = Business::create(['name' => 'Bionic Garden', 'slug' => 'bionic-garden', 'fiscal_year_start_month' => 7]);
    Role::create(['name' => 'Staff', 'guard_name' => 'web', 'business_id' => $bionic->id]);
    (new AssetsPermissionsSeeder)->run();
    $this->staff->businesses()->attach($bionic->id);
    setPermissionsTeamId($bionic->id);
    $this->staff->assignRole(Role::where('name', 'Staff')->where('business_id', $bionic->id)->firstOrFail());
    setPermissionsTeamId($this->business->id);

    // Standing in Bionic: the row is listed, not revealable, and says where it is.
    $row = $this->actingAs($this->staff)->withSession(['current_business_id' => $bionic->id])
        ->get('/hrm/my-accounts')->assertOk()->original->getData()['page']['props']['accounts'][0]['credentials']['rows'][0];

    expect($row['can_reveal'])->toBeFalse()
        ->and($row['reveal_in_business'])->toBe('Bor de Guna');

    // Standing at home, the same login reveals.
    $row = $this->actingAs($this->staff)->withSession(['current_business_id' => $this->business->id])
        ->get('/hrm/my-accounts')->assertOk()->original->getData()['page']['props']['accounts'][0]['credentials']['rows'][0];

    expect($row['can_reveal'])->toBeTrue()->and($row['reveal_in_business'])->toBeNull();
    $this->actingAs($this->staff)->withSession(['current_business_id' => $this->business->id, 'auth.password_confirmed_at' => now()->timestamp])
        ->postJson("/assets/credentials/{$secret->id}/reveal")->assertOk();
});
