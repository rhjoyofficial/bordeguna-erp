<?php

use App\Models\User;
use App\Modules\Assets\Actions\MoveAssets;
use App\Modules\Assets\Actions\SaveAssetItem;
use App\Modules\Assets\database\seeders\AssetCategorySeeder;
use App\Modules\Assets\database\seeders\AssetsPermissionsSeeder;
use App\Modules\Assets\Enums\AssetMovementKind;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Models\AssetCredential;
use App\Modules\Assets\Models\CompanyAccount;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Asset register review of 13 October 2026 (rulings 2 and 10): one real
 * account serves several businesses of the group, recorded once; the other
 * businesses read it and reveal nothing; the leaver check lists everything
 * one employee holds, runs, uses or guards.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-13 10:00:00');
    Storage::fake('local');
    $this->withoutVite();

    $this->bdg = Business::create(['name' => 'Bor de Guna', 'slug' => 'bor-de-guna', 'fiscal_year_start_month' => 7]);
    $this->bionic = Business::create(['name' => 'Bionic Garden', 'slug' => 'bionic-garden', 'fiscal_year_start_month' => 7]);
    $this->elsewhere = Business::create(['name' => 'Elsewhere Ltd', 'slug' => 'elsewhere']);

    foreach ([$this->bdg, $this->bionic, $this->elsewhere] as $business) {
        foreach (['Owner', 'Manager', 'Staff'] as $name) {
            Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $business->id]);
        }
    }

    TenantScope::enter($this->bdg->id);
    (new AssetsPermissionsSeeder)->run();
    (new AssetCategorySeeder)->run();
    TenantScope::enter($this->bdg->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->bdg->id);

    // The Owner belongs to Bor de Guna AND Bionic; the custodian to Bor de Guna only.
    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach([$this->bdg->id, $this->bionic->id]);
    setPermissionsTeamId($this->bdg->id);
    $this->owner->assignRole('Owner');
    setPermissionsTeamId($this->bionic->id);
    $this->owner->assignRole('Owner');
    setPermissionsTeamId($this->bdg->id);

    $this->custodian = User::factory()->create();
    $this->custodian->businesses()->attach($this->bdg->id);
    $this->custodian->assignRole('IT Custodian');

    $this->manager = User::factory()->create();
    $this->manager->businesses()->attach($this->bdg->id);
    $this->manager->assignRole('Manager');

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->bdg->id);
    $this->staff->assignRole('Staff');

    $this->office = Office::create(['business_id' => $this->bdg->id, 'name' => 'Corporate Office - Adabor 2']);
    $this->bionicOffice = Office::create(['business_id' => $this->bionic->id, 'name' => 'Bionic office']);

    $this->employee = function (string $first, string $code, Office $office, ?User $user = null): Employee {
        $employee = Employee::create([
            'employee_code' => $code, 'first_name' => $first, 'last_name' => 'Test', 'employment_type' => 'permanent', 'status' => 'active',
            'joining_date' => '2026-01-01', 'user_id' => $user?->id,
        ]);
        EmployeeAssignment::create([
            'business_id' => $office->business_id, 'employee_id' => $employee->id, 'office_id' => $office->id,
            'effective_from' => '2026-01-01', 'is_primary' => true,
        ]);

        return $employee;
    };

    $this->joy = ($this->employee)('Joy', 'BDG-000016', $this->office, $this->custodian);
    $this->rakib = ($this->employee)('Rakib', 'BDG-000002', $this->office, $this->staff);
    $this->moin = ($this->employee)('Moin', 'BDG-000001', $this->office);

    $this->payload = fn (array $overrides = []) => array_replace([
        'kind' => 'hosting', 'name' => 'Hostinger plan', 'provider' => 'Hostinger', 'identifier' => 'hostinger-1', 'url' => 'https://hpanel.hostinger.com',
        'registered_as' => null, 'responsible_employee_id' => $this->joy->id, 'two_factor_method' => 'authenticator', 'two_factor_employee_id' => $this->joy->id,
        'renews_on' => '2027-03-01', 'renewal_cycle' => 'yearly', 'renewal_cost' => '12000', 'renewal_money_account_id' => null,
        'recovery_email' => null, 'recovery_phone' => null, 'status' => 'active', 'note' => null, 'shared_with' => [$this->bionic->id],
    ], $overrides);
    // ⚠ The current business is the session's `current_business_id`, which ResolveCurrentBusiness reads.
    $this->props = fn (User $as, string $url, ?int $business = null) => $this->actingAs($as)
        ->withSession(['current_business_id' => $business ?? $this->bdg->id])
        ->get($url)->assertOk()->original->getData()['page']['props'];
});

afterEach(fn () => Carbon::setTestNow());

it('records a group-wide account once, lists it read-only in the other business, and reveals its logins only at home', function () {
    // Only businesses the person belongs to may be ticked.
    $this->actingAs($this->owner)->post('/assets/accounts', ($this->payload)(['shared_with' => [$this->elsewhere->id]]))->assertSessionHasErrors('shared_with');
    // The custodian belongs to Bor de Guna alone: no sharing for them.
    $this->actingAs($this->custodian)->post('/assets/accounts', ($this->payload)(['renewal_cost' => null, 'shared_with' => [$this->bionic->id]]))->assertSessionHasErrors('shared_with');
    expect(($this->props)($this->custodian, '/assets/accounts/create')['businesses'])->toBe([]);

    $this->actingAs($this->owner)->post('/assets/accounts', ($this->payload)())->assertSessionHasNoErrors();
    $account = CompanyAccount::query()->sole();
    expect($account->business_id)->toBe($this->bdg->id)
        ->and($account->sharedBusinesses()->pluck('businesses.id')->all())->toBe([$this->bionic->id])
        ->and(Activity::query()->where('subject_id', $account->id)->where('description', 'Also serves: Bionic Garden')->exists())->toBeTrue();

    $this->actingAs($this->custodian)->post("/assets/accounts/{$account->id}/credentials", ['label' => 'hPanel', 'kind' => 'user_login', 'secret' => 'hpanel-pass-7777'])->assertSessionHasNoErrors();
    $credential = AssetCredential::query()->sole();

    // At home: the page, the card, the edit.
    $home = ($this->props)($this->owner, "/assets/accounts/{$account->id}");
    expect($home['is_home'])->toBeTrue()
        ->and($home['can_manage'])->toBeTrue()
        ->and($home['account']['serves'])->toBe(['Bionic Garden'])
        ->and($home['credentials']['rows'][0]['label'])->toBe('hPanel')
        ->and(collect(($this->props)($this->owner, '/assets/accounts')['accounts']['data'])->first()['shared_from'])->toBeNull();

    // In Bionic: listed with its home named, opened read-only, no logins card, no edit, no grant, no write.
    $list = ($this->props)($this->owner, '/assets/accounts', $this->bionic->id);
    expect($list['accounts']['data'])->toHaveCount(1)
        ->and($list['accounts']['data'][0]['shared_from'])->toBe('Bor de Guna')
        ->and($list['counts']['open'])->toBe(1);

    $away = ($this->props)($this->owner, "/assets/accounts/{$account->id}", $this->bionic->id);
    expect($away['is_home'])->toBeFalse()
        ->and($away['can_manage'])->toBeFalse()
        ->and($away['can_manage_access'])->toBeFalse()
        ->and($away['credentials'])->toBeNull()
        ->and($away['account']['home_business'])->toBe('Bor de Guna')
        ->and(json_encode($away))->not->toContain('hpanel-pass-7777');

    $bionic = fn () => $this->actingAs($this->owner)->withSession(['current_business_id' => $this->bionic->id]);
    $bionic()->get("/assets/accounts/{$account->id}/edit")->assertNotFound();
    $bionic()->put("/assets/accounts/{$account->id}", ($this->payload)(['name' => 'hijacked']))->assertNotFound();
    $bionic()->post("/assets/accounts/{$account->id}/access", ['employee_id' => $this->rakib->id, 'role' => 'user', 'granted_on' => '2026-10-13'])->assertNotFound();
    $bionic()->post("/assets/accounts/{$account->id}/credentials", ['label' => 'x', 'kind' => 'user_login', 'secret' => 'y'])->assertNotFound();
    $bionic()->withSession(['auth.password_confirmed_at' => now()->timestamp])->postJson("/assets/credentials/{$credential->id}/reveal")->assertNotFound();
    expect($account->fresh()->name)->toBe('Hostinger plan');

    // Bionic's own export does not carry it; Bor de Guna's does, with the slug.
    expect($bionic()->get('/assets/accounts/export')->assertOk()->streamedContent())->not->toContain('Hostinger plan');
    $csv = $this->actingAs($this->owner)->withSession(['current_business_id' => $this->bdg->id])->get('/assets/accounts/export')->assertOk()->streamedContent();
    expect($csv)->toContain('hosting,"Hostinger plan",Hostinger,hostinger-1,https://hpanel.hostinger.com,,BDG-000016,,BDG-000016,authenticator,2027-03-01,yearly,12000,active,,,bionic-garden,');

    // Unticking is logged; the import sets it from slugs and refuses an unknown one.
    $this->flushSession();
    $this->actingAs($this->owner)->put("/assets/accounts/{$account->id}", ($this->payload)(['shared_with' => []]))->assertSessionHasNoErrors();
    expect($account->fresh()->sharedBusinesses()->count())->toBe(0)
        ->and(Activity::query()->where('subject_id', $account->id)->where('description', 'No longer shared with another business')->exists())->toBeTrue();

    $header = 'kind,name,provider,identifier,url,registered_as,responsible,users,two_factor_holder,two_factor_method,renews_on,renewal_cycle,renewal_cost,status,recovery_email,recovery_phone,shared_with,note';
    $file = UploadedFile::fake()->createWithContent('accounts.csv', $header."\nhosting,Hostinger plan,Hostinger,hostinger-1,https://hpanel.hostinger.com,,BDG-000016,,BDG-000016,authenticator,2027-03-01,yearly,12000,active,,,BIONIC-GARDEN,\n");
    $preview = $this->actingAs($this->owner)->post('/assets/accounts/import/preview', ['file' => $file])->assertOk()->original->getData()['page']['props']['preview'];
    expect($preview['errors'])->toBe([])
        ->and($preview['updates'][0]['changes'])->toBe([['field' => 'shared_with', 'from' => '', 'to' => 'bionic-garden']]);
    $this->actingAs($this->owner)->post('/assets/accounts/import', ['token' => $preview['token'], 'hash' => $preview['hash']])->assertSessionHasNoErrors();
    expect($account->fresh()->sharedBusinesses()->pluck('businesses.id')->all())->toBe([$this->bionic->id]);

    // The custodian cannot import a share they do not belong to.
    $preview = $this->actingAs($this->custodian)->post('/assets/accounts/import/preview', ['file' => UploadedFile::fake()->createWithContent('accounts.csv', $header."\nhosting,Hostinger plan,Hostinger,hostinger-1,,,BDG-000016,,,,,,,active,,,bionic-garden,\n")])
        ->assertOk()->original->getData()['page']['props']['preview'];
    expect($preview['errors'][0]['message'])->toContain('shared_with "bionic-garden" is not another business of the group you belong to');
});

it('lists on the leaver check everything one employee holds, runs, uses or guards, with the link to act', function () {
    // Gated on managing the hub.
    $this->actingAs($this->manager)->get('/assets/accounts/leaver-check')->assertForbidden();
    $this->actingAs($this->staff)->get('/assets/accounts/leaver-check')->assertForbidden();
    expect(($this->props)($this->custodian, '/assets/accounts/leaver-check')['sections'])->toBeNull();

    $laptop = app(SaveAssetItem::class)->register(
        AssetCategory::query()->where('code', 'IT')->sole(),
        ['name' => 'ThinkPad T470s', 'office_id' => $this->office->id, 'status' => 'in_store', 'condition' => 'good'],
        $this->custodian,
    )->first();
    app(MoveAssets::class)->handle(AssetMovementKind::Issue, [['item' => $laptop]], ['employee_id' => $this->rakib->id], $this->custodian, '2026-10-13');

    $login = fn ($owner, string $label, string $kind, string $secret, bool $holder = true) => AssetCredential::create([
        'business_id' => $owner->business_id, 'credentialable_type' => $owner->getMorphClass(), 'credentialable_id' => $owner->id,
        'label' => $label, 'kind' => $kind, 'holder_may_reveal' => $holder, 'secret' => $secret, 'hint' => AssetCredential::hintFor($secret), 'created_by' => $this->owner->id,
    ]);
    $login($laptop, 'Windows login', 'user_login', 'win-pass-1111');
    $login($laptop, 'BIOS admin', 'admin', 'bios-pass-2222');
    $login($laptop, 'VPN', 'other', 'vpn-pass-3333', false);

    $mail = CompanyAccount::create(['kind' => 'email', 'name' => 'support@laladia.com', 'identifier' => 'support@laladia.com', 'responsible_employee_id' => $this->moin->id, 'created_by' => $this->owner->id]);
    $mail->access()->create(['employee_id' => $this->rakib->id, 'role' => 'admin', 'granted_on' => '2026-09-01', 'granted_by' => $this->owner->id]);
    $login($mail, 'Webmail', 'user_login', 'mail-pass-4444');
    $page = CompanyAccount::create(['kind' => 'social_page', 'name' => 'LaLaDia Facebook page', 'responsible_employee_id' => $this->rakib->id, 'two_factor_method' => 'sms', 'two_factor_employee_id' => $this->rakib->id, 'created_by' => $this->owner->id]);
    $old = CompanyAccount::create(['kind' => 'saas', 'name' => 'Old tool', 'responsible_employee_id' => $this->rakib->id, 'status' => 'closed', 'created_by' => $this->owner->id]);
    $revoked = CompanyAccount::create(['kind' => 'saas', 'name' => 'Pathao', 'created_by' => $this->owner->id]);
    $revoked->access()->create(['employee_id' => $this->rakib->id, 'role' => 'user', 'granted_on' => '2026-09-01', 'revoked_on' => '2026-10-01', 'granted_by' => $this->owner->id]);

    $sections = ($this->props)($this->custodian, "/assets/accounts/leaver-check?employee={$this->rakib->id}")['sections'];

    expect(collect($sections['things'])->pluck('tag')->all())->toBe(['BDG-IT-0001'])
        ->and($sections['things'][0]['return_url'])->toBe('/assets/movements/create?kind=return&items[]='.$laptop->id)
        ->and(collect($sections['responsible'])->pluck('name')->all())->toBe(['LaLaDia Facebook page'])
        ->and($sections['responsible'][0]['edit_url'])->toBe("/assets/accounts/{$page->id}/edit")
        ->and(collect($sections['access'])->pluck('name')->all())->toBe(['support@laladia.com'])
        ->and($sections['access'][0]['role_label'])->toBe('Admin')
        ->and($sections['access'][0]['url'])->toBe("/assets/accounts/{$mail->id}")
        ->and(collect($sections['two_factor'])->pluck('name')->all())->toBe(['LaLaDia Facebook page'])
        ->and($sections['two_factor'][0]['method_label'])->toBe('SMS to a phone')
        // Only what a holder could reveal: the Windows login and the webmail; not the BIOS, not the VPN.
        ->and(collect($sections['logins'])->pluck('label')->sort()->values()->all())->toBe(['Webmail', 'Windows login'])
        ->and(json_encode($sections))->not->toContain('win-pass-1111')
        ->and(json_encode($sections))->not->toContain('1111')
        ->and(json_encode($sections))->not->toContain('mail-pass-4444');

    // After the return, the thing and its logins leave the list; the rest stay.
    app(MoveAssets::class)->handle(AssetMovementKind::Return, [['item' => $laptop->fresh()]], ['office_id' => $this->office->id], $this->custodian, '2026-10-13');
    $after = ($this->props)($this->custodian, "/assets/accounts/leaver-check?employee={$this->rakib->id}")['sections'];
    expect($after['things'])->toBe([])
        ->and(collect($after['logins'])->pluck('label')->all())->toBe(['Webmail']);

    // A Manager still cannot manage or reveal a login anywhere, and My accounts names who is responsible.
    $webmail = AssetCredential::query()->where('label', 'Webmail')->sole();
    $this->actingAs($this->manager)->post("/assets/accounts/{$mail->id}/credentials", ['label' => 'x', 'kind' => 'user_login', 'secret' => 'y'])->assertForbidden();
    $this->actingAs($this->manager)->withSession(['auth.password_confirmed_at' => now()->timestamp])->postJson("/assets/credentials/{$webmail->id}/reveal")->assertForbidden();
    $mine = ($this->props)($this->staff, '/hrm/my-accounts')['accounts'];
    expect($mine[0]['responsible'])->toBe('Moin Test');
});
