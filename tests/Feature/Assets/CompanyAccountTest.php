<?php

use App\Models\User;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Assets\database\seeders\AssetsPermissionsSeeder;
use App\Modules\Assets\Models\AssetCredential;
use App\Modules\Assets\Models\CompanyAccount;
use App\Modules\Assets\Models\CompanyAccountAccess;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Asset register slice 4 (13 October 2026): the accounts hub.
 *
 * ⭐ Owner, Manager and IT Custodian read it; Owner and IT Custodian change
 * it; a keeper and a plain employee see none of it. Access is granted and
 * revoked by date, never deleted. Marking renewed moves the date and is
 * logged. The export never holds a login.
 *
 * ⚠ Review of 13 October (ruling 6): the Manager reveals no password and
 * grants no access; the IT Custodian sees no renewal cost. Sharing across
 * the group, costs and the leaver check: CompanyAccountSharingTest.
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
    $this->manager = ($this->user)('Manager');
    $this->custodian = ($this->user)('IT Custodian');
    $this->keeper = ($this->user)('Asset Keeper');
    $this->staff = ($this->user)('Staff');

    $this->office = Office::create(['business_id' => $this->business->id, 'name' => 'Corporate Office - Adabor 2']);

    $this->employee = function (string $first, string $code, ?User $user = null): Employee {
        $employee = Employee::create([
            'employee_code' => $code, 'first_name' => $first, 'last_name' => 'Test', 'employment_type' => 'permanent', 'status' => 'active',
            'joining_date' => '2026-01-01', 'user_id' => $user?->id,
        ]);
        EmployeeAssignment::create([
            'business_id' => $this->business->id, 'employee_id' => $employee->id, 'office_id' => $this->office->id,
            'effective_from' => '2026-01-01', 'is_primary' => true,
        ]);

        return $employee;
    };

    $this->moin = ($this->employee)('Moin', 'BDG-000001');
    $this->joy = ($this->employee)('Joy', 'BDG-000016', $this->custodian);
    $this->rakib = ($this->employee)('Rakib', 'BDG-000002', $this->staff);

    $ledger = Account::create(['business_id' => $this->business->id, 'code' => '1020', 'name' => 'Bank', 'type' => AccountType::Asset, 'is_postable' => true, 'is_active' => true]);
    $this->bank = MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'BRAC current', 'type' => 'bank', 'account_id' => $ledger->id, 'is_active' => true]);

    $this->payload = fn (array $overrides = []) => array_replace([
        'kind' => 'domain', 'name' => 'laladia.com', 'provider' => 'Nikolahost', 'identifier' => 'laladia.com', 'url' => 'https://asiacp2000.nikolahost.com',
        'registered_as' => null, 'responsible_employee_id' => $this->joy->id, 'two_factor_method' => 'none', 'two_factor_employee_id' => null,
        'renews_on' => '2027-02-01', 'renewal_cycle' => 'yearly', 'renewal_cost' => '1800',
        'renewal_money_account_id' => $this->bank->id, 'recovery_email' => null, 'recovery_phone' => null, 'status' => 'active', 'note' => 'Renews yearly',
    ], $overrides);
    // The IT Custodian may not set money: the same payload without the money fields.
    $this->custodianPayload = fn (array $overrides = []) => array_diff_key(($this->payload)($overrides), ['renewal_cost' => 1, 'renewal_money_account_id' => 1]);
    $this->props = fn (User $as, string $url) => $this->actingAs($as)->get($url)->assertOk()->original->getData()['page']['props'];
});

afterEach(fn () => Carbon::setTestNow());

it('opens the hub to Owner, Manager and IT Custodian, and lets only Owner and IT Custodian change it', function () {
    foreach ([$this->owner, $this->manager, $this->custodian] as $reader) {
        $this->actingAs($reader)->get('/assets/accounts')->assertOk();
    }

    foreach ([$this->keeper, $this->staff] as $outsider) {
        $this->actingAs($outsider)->get('/assets/accounts')->assertForbidden();
        $this->actingAs($outsider)->get('/assets/accounts/create')->assertForbidden();
        $this->actingAs($outsider)->post('/assets/accounts', ($this->payload)())->assertForbidden();
        $this->actingAs($outsider)->get('/assets/accounts/export')->assertForbidden();
        $this->actingAs($outsider)->get('/assets/accounts/import')->assertForbidden();
    }

    $this->actingAs($this->manager)->get('/assets/accounts/create')->assertForbidden();
    $this->actingAs($this->manager)->post('/assets/accounts', ($this->payload)())->assertForbidden();

    // The IT Custodian has no costs.view: a cost in the form is refused, the rest records.
    $this->actingAs($this->custodian)->post('/assets/accounts', ($this->payload)())->assertSessionHasErrors('renewal_cost');
    $this->actingAs($this->custodian)->post('/assets/accounts', ($this->custodianPayload)())->assertSessionHasNoErrors()->assertRedirect();
    $account = CompanyAccount::query()->sole();

    expect($account->name)->toBe('laladia.com')
        ->and($account->responsible_employee_id)->toBe($this->joy->id)
        ->and($account->renewal_cycle->value)->toBe('yearly')
        ->and($account->renewal_cost)->toBeNull()
        ->and($account->created_by)->toBe($this->custodian->id);

    // The Owner fills the cost in; the custodian's later edit without the fields leaves it alone.
    $this->actingAs($this->owner)->put("/assets/accounts/{$account->id}", ($this->payload)())->assertSessionHasNoErrors();
    expect((string) $account->fresh()->renewal_cost)->toBe('1800.0000');
    $this->actingAs($this->custodian)->put("/assets/accounts/{$account->id}", ($this->custodianPayload)(['note' => 'edited']))->assertSessionHasNoErrors();
    expect((string) $account->fresh()->renewal_cost)->toBe('1800.0000')->and($account->fresh()->note)->toBe('edited');
    // The custodian's page and edit form carry no cost at all; the Manager's do.
    expect(($this->props)($this->custodian, "/assets/accounts/{$account->id}")['account'])->not->toHaveKey('renewal_cost')
        ->and(($this->props)($this->custodian, "/assets/accounts/{$account->id}/edit")['account'])->not->toHaveKey('renewal_cost')
        ->and(($this->props)($this->custodian, "/assets/accounts/{$account->id}/edit")['can_view_costs'])->toBeFalse()
        ->and(($this->props)($this->manager, "/assets/accounts/{$account->id}")['account']['renewal_cost'])->toBe('1800.00');

    foreach ([$this->keeper, $this->staff] as $outsider) {
        $this->actingAs($outsider)->get("/assets/accounts/{$account->id}")->assertForbidden();
        $this->actingAs($outsider)->put("/assets/accounts/{$account->id}", ($this->payload)())->assertForbidden();
        $this->actingAs($outsider)->post("/assets/accounts/{$account->id}/access", ['employee_id' => $this->rakib->id, 'role' => 'user', 'granted_on' => '2026-10-13'])->assertForbidden();
        $this->actingAs($outsider)->post("/assets/accounts/{$account->id}/renewed", ['next_renews_on' => '2028-02-01'])->assertForbidden();
    }

    // A Manager reads the page and the index, and cannot write.
    $page = ($this->props)($this->manager, "/assets/accounts/{$account->id}");
    expect($page['can_manage'])->toBeFalse()->and($page['can_manage_access'])->toBeFalse()->and($page['account']['responsible'])->toBe('Joy Test (BDG-000016)');
    $this->actingAs($this->manager)->get("/assets/accounts/{$account->id}/edit")->assertForbidden();
    $this->actingAs($this->manager)->put("/assets/accounts/{$account->id}", ($this->payload)(['name' => 'x']))->assertForbidden();
    $this->actingAs($this->manager)->post("/assets/accounts/{$account->id}/access", ['employee_id' => $this->rakib->id, 'role' => 'user', 'granted_on' => '2026-10-13'])->assertForbidden();
    $this->actingAs($this->manager)->post("/assets/accounts/{$account->id}/renewed", ['next_renews_on' => '2028-02-01'])->assertForbidden();

    // The Owner edits; an employee of another business is refused as responsible.
    $this->actingAs($this->owner)->put("/assets/accounts/{$account->id}", ($this->payload)(['name' => 'laladia.com (main)', 'responsible_employee_id' => $this->moin->id, 'registered_as' => 'joy@gmail.com', 'two_factor_method' => 'sms', 'two_factor_employee_id' => $this->joy->id]))->assertSessionHasNoErrors();
    expect($account->fresh()->name)->toBe('laladia.com (main)')
        ->and($account->fresh()->responsible_employee_id)->toBe($this->moin->id)
        ->and($account->fresh()->registered_as)->toBe('joy@gmail.com')
        ->and($account->fresh()->two_factor_method->value)->toBe('sms');

    $stranger = Employee::create(['employee_code' => 'OTH-1', 'first_name' => 'Else', 'last_name' => 'Where', 'employment_type' => 'permanent', 'status' => 'active', 'joining_date' => '2026-01-01']);
    $this->actingAs($this->owner)->put("/assets/accounts/{$account->id}", ($this->payload)(['responsible_employee_id' => $stranger->id]))->assertSessionHasErrors('responsible_employee_id');
    $this->actingAs($this->owner)->post('/assets/accounts', ($this->payload)(['kind' => 'fax']))->assertSessionHasErrors('kind');
    $this->actingAs($this->owner)->post('/assets/accounts', ($this->payload)(['status' => 'dead']))->assertSessionHasErrors('status');
    $this->actingAs($this->owner)->post('/assets/accounts', ($this->payload)(['two_factor_method' => 'pigeon']))->assertSessionHasErrors('two_factor_method');
    $this->actingAs($this->owner)->post('/assets/accounts', ($this->payload)(['renewal_cycle' => 'weekly']))->assertSessionHasErrors('renewal_cycle');

    // The sidebar flag follows the permission.
    expect(($this->props)($this->manager, '/assets/accounts')['canViewAccounts'])->toBeTrue()
        ->and($this->actingAs($this->keeper)->get('/assets/items')->assertOk()->original->getData()['page']['props']['canViewAccounts'])->toBeFalse();
});

it('lists with filters and renewal chips', function () {
    $this->actingAs($this->owner)->post('/assets/accounts', ($this->payload)());
    $this->actingAs($this->owner)->post('/assets/accounts', ($this->payload)(['kind' => 'email', 'name' => 'Support mailbox', 'identifier' => 'support@laladia.com', 'renews_on' => '2026-10-20', 'responsible_employee_id' => $this->moin->id]));
    $this->actingAs($this->owner)->post('/assets/accounts', ($this->payload)(['kind' => 'saas', 'name' => 'Old tool', 'identifier' => 'old', 'renews_on' => '2026-10-01', 'status' => 'closed']));
    $this->actingAs($this->owner)->post('/assets/accounts', ($this->payload)(['kind' => 'hosting', 'name' => 'Late host', 'identifier' => 'host', 'renews_on' => '2026-10-10']));

    $all = ($this->props)($this->owner, '/assets/accounts');
    $rows = collect($all['accounts']['data'])->keyBy('name');

    // Closed accounts are out of the working list; chips follow the date.
    expect($rows->keys()->sort()->values()->all())->toBe(['Late host', 'Support mailbox', 'laladia.com'])
        ->and($rows['laladia.com']['renewal_tone'])->toBeNull()
        ->and($rows['Support mailbox']['renewal_tone'])->toBe('soon')
        ->and($rows['Late host']['renewal_tone'])->toBe('overdue')
        ->and($all['counts'])->toBe(['open' => 3, 'renewing' => 2, 'overdue' => 1]);

    expect(collect(($this->props)($this->owner, '/assets/accounts?kind=email')['accounts']['data'])->pluck('name')->all())->toBe(['Support mailbox'])
        ->and(collect(($this->props)($this->owner, '/assets/accounts?status=closed')['accounts']['data'])->pluck('name')->all())->toBe(['Old tool'])
        ->and(collect(($this->props)($this->owner, "/assets/accounts?responsible={$this->moin->id}")['accounts']['data'])->pluck('name')->all())->toBe(['Support mailbox'])
        ->and(collect(($this->props)($this->owner, '/assets/accounts?renewing=1')['accounts']['data'])->pluck('name')->sort()->values()->all())->toBe(['Late host', 'Support mailbox'])
        ->and(collect(($this->props)($this->owner, '/assets/accounts?q=nikola')['accounts']['data'])->count())->toBe(3);
});

it('grants and revokes access by date, never deleting a row', function () {
    $this->actingAs($this->owner)->post('/assets/accounts', ($this->payload)());
    $account = CompanyAccount::query()->sole();

    // Granting access is its own permission: Owner and IT Custodian, never the Manager.
    $this->actingAs($this->manager)->post("/assets/accounts/{$account->id}/access", ['employee_id' => $this->rakib->id, 'role' => 'admin', 'granted_on' => '2026-10-01'])->assertForbidden();
    $this->actingAs($this->custodian)->post("/assets/accounts/{$account->id}/access", ['employee_id' => $this->rakib->id, 'role' => 'admin', 'granted_on' => '2026-10-01', 'note' => 'Runs the panel'])
        ->assertSessionHasNoErrors();
    $access = CompanyAccountAccess::query()->sole();
    expect($access->role->value)->toBe('admin')->and($access->granted_by)->toBe($this->custodian->id)->and($access->revoked_on)->toBeNull();

    // Twice is refused; an outsider employee is refused.
    $this->actingAs($this->custodian)->post("/assets/accounts/{$account->id}/access", ['employee_id' => $this->rakib->id, 'role' => 'user', 'granted_on' => '2026-10-02'])->assertSessionHasErrors('employee_id');
    $stranger = Employee::create(['employee_code' => 'OTH-1', 'first_name' => 'Else', 'last_name' => 'Where', 'employment_type' => 'permanent', 'status' => 'active', 'joining_date' => '2026-01-01']);
    $this->actingAs($this->custodian)->post("/assets/accounts/{$account->id}/access", ['employee_id' => $stranger->id, 'role' => 'user', 'granted_on' => '2026-10-02'])->assertSessionHasErrors('employee_id');

    $page = ($this->props)($this->owner, "/assets/accounts/{$account->id}");
    expect($page['access'])->toHaveCount(1)
        ->and($page['access'][0])->toMatchArray(['employee' => 'Rakib Test', 'role_label' => 'Admin', 'granted_on' => '2026-10-01', 'revoked_on' => null])
        ->and(collect($page['grantable'])->pluck('id')->all())->not->toContain($this->rakib->id);

    // Revoked before granted is refused; then revoked; then it can be granted again.
    $this->actingAs($this->manager)->post("/assets/accounts/{$account->id}/access/{$access->id}/revoke", ['revoked_on' => '2026-10-13'])->assertForbidden();
    $this->actingAs($this->owner)->post("/assets/accounts/{$account->id}/access/{$access->id}/revoke", ['revoked_on' => '2026-09-30'])->assertSessionHasErrors('revoked_on');
    $this->actingAs($this->owner)->post("/assets/accounts/{$account->id}/access/{$access->id}/revoke", ['revoked_on' => '2026-10-13'])->assertSessionHasNoErrors();
    expect($access->fresh()->revoked_on->toDateString())->toBe('2026-10-13')
        ->and(CompanyAccountAccess::query()->count())->toBe(1)
        ->and(Activity::query()->where('log_name', 'assets.accounts')->where('description', 'Access granted to Rakib Test as Admin')->exists())->toBeTrue()
        ->and(Activity::query()->where('log_name', 'assets.accounts')->where('description', 'Access revoked from Rakib Test')->exists())->toBeTrue();
    $this->actingAs($this->owner)->post("/assets/accounts/{$account->id}/access/{$access->id}/revoke", ['revoked_on' => '2026-10-14'])->assertSessionHasErrors('access');
    $this->actingAs($this->owner)->post("/assets/accounts/{$account->id}/access", ['employee_id' => $this->rakib->id, 'role' => 'user', 'granted_on' => '2026-10-14'])->assertSessionHasNoErrors();
    expect(CompanyAccountAccess::query()->count())->toBe(2);

    // A row of another account cannot be revoked through this one.
    $this->actingAs($this->owner)->post('/assets/accounts', ($this->payload)(['kind' => 'email', 'name' => 'Mail', 'identifier' => 'm@x']));
    $other = CompanyAccount::query()->where('name', 'Mail')->sole();
    $this->actingAs($this->owner)->post("/assets/accounts/{$other->id}/access/{$access->id}/revoke", ['revoked_on' => '2026-10-14'])->assertNotFound();
});

it('marks an account renewed: the date moves on, the cost may change, and it is logged once', function () {
    $this->actingAs($this->owner)->post('/assets/accounts', ($this->payload)());
    $account = CompanyAccount::query()->sole();
    CompanyAccount::query()->whereKey($account->id)->update(['renewal_reminded_stage' => '30', 'renewal_reminded_for' => '2027-02-01']);

    $this->actingAs($this->owner)->post("/assets/accounts/{$account->id}/renewed", ['next_renews_on' => '2027-01-01'])->assertSessionHasErrors('next_renews_on');
    $this->actingAs($this->owner)->post("/assets/accounts/{$account->id}/renewed", ['next_renews_on' => ''])->assertSessionHasErrors('next_renews_on');
    // The custodian may mark it renewed, and may not say what it cost.
    $this->actingAs($this->custodian)->post("/assets/accounts/{$account->id}/renewed", ['next_renews_on' => '2028-02-01', 'renewal_cost' => '1950'])->assertSessionHasErrors('renewal_cost');
    $this->actingAs($this->owner)->post("/assets/accounts/{$account->id}/renewed", ['next_renews_on' => '2028-02-01', 'renewal_cost' => '1950'])->assertSessionHasNoErrors();

    $fresh = $account->fresh();
    expect($fresh->renews_on->toDateString())->toBe('2028-02-01')
        ->and((string) $fresh->renewal_cost)->toBe('1950.0000')
        ->and($fresh->renewal_reminded_stage)->toBeNull()
        ->and($fresh->renewal_reminded_for)->toBeNull()
        ->and(Activity::query()->where('subject_id', $account->id)->where('description', 'Marked renewed: next renewal 2028-02-01 (was 2027-02-01)')->count())->toBe(1)
        ->and(Activity::query()->where('subject_id', $account->id)->where('description', 'updated')->count())->toBe(0);

    $page = ($this->props)($this->owner, "/assets/accounts/{$account->id}");
    expect($page['history'][0]['what'])->toBe('Marked renewed: next renewal 2028-02-01 (was 2027-02-01)')
        ->and($page['account']['renewal_cost'])->toBe('1950.00');
});

it('carries logins on the account page under the same policy, and exports without them', function () {
    $this->actingAs($this->owner)->post('/assets/accounts', ($this->payload)());
    $account = CompanyAccount::query()->sole();

    $this->actingAs($this->custodian)->post("/assets/accounts/{$account->id}/credentials", ['label' => 'cPanel', 'kind' => 'user_login', 'username' => 'sangramn', 'secret' => 'cpanel-secret-7777'])
        ->assertSessionHasNoErrors();
    $credential = AssetCredential::query()->sole();
    expect($credential->credentialable_type)->toBe(CompanyAccount::class)->and($credential->credentialable_id)->toBe($account->id);

    // The Manager reads the account and gets no logins card at all (review ruling 6); the Owner does.
    $page = ($this->props)($this->manager, "/assets/accounts/{$account->id}");
    expect($page['credentials'])->toBeNull()
        ->and(json_encode($page))->not->toContain('cpanel-secret-7777');
    $page = ($this->props)($this->owner, "/assets/accounts/{$account->id}");
    expect($page['credentials']['rows'][0])->toMatchArray(['label' => 'cPanel', 'hint' => '7777', 'kind' => 'user_login'])
        ->and(json_encode($page))->not->toContain('cpanel-secret-7777');

    // Rakib has no access yet: refused. Granted access: the holder reveals.
    $confirmed = fn (User $as) => $this->actingAs($as)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    $confirmed($this->staff)->postJson("/assets/credentials/{$credential->id}/reveal")->assertForbidden();
    $this->actingAs($this->owner)->post("/assets/accounts/{$account->id}/access", ['employee_id' => $this->rakib->id, 'role' => 'user', 'granted_on' => '2026-10-13']);
    $confirmed($this->staff)->postJson("/assets/credentials/{$credential->id}/reveal")->assertOk()->assertExactJson(['secret' => 'cpanel-secret-7777']);
    expect(Activity::query()->where('description', $this->staff->name.' revealed cPanel of laladia.com')->exists())->toBeTrue();

    // Revoked: refused again.
    $access = CompanyAccountAccess::query()->sole();
    $this->actingAs($this->owner)->post("/assets/accounts/{$account->id}/access/{$access->id}/revoke", ['revoked_on' => '2026-10-13']);
    $confirmed($this->staff)->postJson("/assets/credentials/{$credential->id}/reveal")->assertForbidden();

    // The export: the template's columns, employee codes, and no secret anywhere.
    $this->actingAs($this->owner)->post("/assets/accounts/{$account->id}/access", ['employee_id' => $this->rakib->id, 'role' => 'user', 'granted_on' => '2026-10-14']);
    $csv = $this->actingAs($this->manager)->get('/assets/accounts/export')->assertOk()->streamedContent();
    $lines = array_values(array_filter(explode("\n", trim($csv))));
    expect(str_replace("\xEF\xBB\xBF", '', $lines[0]))->toBe('kind,name,provider,identifier,url,registered_as,responsible,users,two_factor_holder,two_factor_method,renews_on,renewal_cycle,renewal_cost,status,recovery_email,recovery_phone,shared_with,note')
        ->and($lines[1])->toBe('domain,laladia.com,Nikolahost,laladia.com,https://asiacp2000.nikolahost.com,,BDG-000016,BDG-000002,,none,2027-02-01,yearly,1800,active,,,,"Renews yearly"')
        ->and($csv)->not->toContain('cpanel-secret-7777')
        ->and($csv)->not->toContain('7777')
        ->and($csv)->not->toContain('cPanel');

    // The custodian's export carries no cost.
    $blank = $this->actingAs($this->custodian)->get('/assets/accounts/export')->assertOk()->streamedContent();
    expect(array_values(array_filter(explode("\n", trim($blank))))[1])->toBe('domain,laladia.com,Nikolahost,laladia.com,https://asiacp2000.nikolahost.com,,BDG-000016,BDG-000002,,none,2027-02-01,yearly,,active,,,,"Renews yearly"');
});
