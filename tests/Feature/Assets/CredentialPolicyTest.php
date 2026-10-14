<?php

use App\Models\User;
use App\Modules\Assets\Actions\CountAssets;
use App\Modules\Assets\Actions\MoveAssets;
use App\Modules\Assets\Actions\SaveAssetItem;
use App\Modules\Assets\database\seeders\AssetCategorySeeder;
use App\Modules\Assets\database\seeders\AssetsPermissionsSeeder;
use App\Modules\Assets\Enums\AssetMovementKind;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Models\AssetCredential;
use App\Modules\Assets\Models\CompanyAccount;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Asset register review of 13 October 2026 (rulings 1, 3, 5, 11): a login
 * has a KIND and a HOLDER POLICY; the holder reveals only holder-facing
 * logins; admin and recovery logins open only to a privileged permission;
 * Replace records a new secret and that the old one went; a return or a
 * reassignment flags the thing's logins for review; every refusal is logged
 * without the secret; the secret reaches no log, no page, no export; an
 * owner that is gone takes its logins with it.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-13 10:00:00');
    Storage::fake('local');
    $this->withoutVite();

    $this->business = Business::create(['name' => 'Bor de Guna', 'slug' => 'bor-de-guna', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new AssetsPermissionsSeeder)->run();
    (new AssetCategorySeeder)->run();
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
    $this->keeper = ($this->user)('Asset Keeper');
    $this->custodian = ($this->user)('IT Custodian');
    $this->holder = ($this->user)('Staff');
    $this->other = ($this->user)('Staff');

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

    $this->rakib = ($this->employee)('Rakib', 'BDG-000001', $this->holder);
    $this->nazmul = ($this->employee)('Nazmul', 'BDG-000002', $this->other);

    $this->laptop = app(SaveAssetItem::class)->register(
        AssetCategory::query()->where('code', 'IT')->sole(),
        ['name' => 'ThinkPad T470s', 'office_id' => $this->office->id, 'status' => 'in_store', 'condition' => 'good'],
        $this->keeper,
    )->first();
    app(MoveAssets::class)->handle(AssetMovementKind::Issue, [['item' => $this->laptop]], ['employee_id' => $this->rakib->id], $this->keeper, '2026-10-13');

    $this->login = function (string $label, string $kind, string $secret, bool $holderMayReveal = true, $owner = null): AssetCredential {
        $owner ??= $this->laptop;

        return AssetCredential::create([
            'business_id' => $owner->business_id, 'credentialable_type' => $owner->getMorphClass(), 'credentialable_id' => $owner->id,
            'label' => $label, 'kind' => $kind, 'holder_may_reveal' => $holderMayReveal, 'username' => 'u',
            'secret' => $secret, 'hint' => AssetCredential::hintFor($secret), 'created_by' => $this->owner->id,
        ]);
    };
    $this->confirmed = fn (User $as) => $this->actingAs($as)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    $this->reveal = fn (User $as, AssetCredential $credential) => ($this->confirmed)($as)->postJson("/assets/credentials/{$credential->id}/reveal");
    $this->refusals = fn () => Activity::query()->where('log_name', 'assets.secrets')->where('description', 'like', '% was refused %')->pluck('description')->all();
});

afterEach(fn () => Carbon::setTestNow());

it('lets the holder reveal only a holder-facing login, never an admin or recovery one, and logs each refusal without the secret', function () {
    $windows = ($this->login)('Windows login', 'user_login', 'win-pass-1111');
    $bios = ($this->login)('BIOS admin', 'admin', 'bios-pass-2222');
    $bitlocker = ($this->login)('BitLocker key', 'recovery', 'rec-key-3333', true);
    $vpn = ($this->login)('VPN', 'other', 'vpn-pass-4444', false);

    // The holder: the Windows login yes; the admin password no, the recovery key no (even with the box ticked), the VPN no (box unticked).
    ($this->reveal)($this->holder, $windows)->assertOk()->assertExactJson(['secret' => 'win-pass-1111']);
    ($this->reveal)($this->holder, $bios)->assertForbidden();
    ($this->reveal)($this->holder, $bitlocker)->assertForbidden();
    ($this->reveal)($this->holder, $vpn)->assertForbidden();

    // The IT Custodian holds reveal_privileged: everything. The Manager: nothing.
    foreach ([$windows, $bios, $bitlocker, $vpn] as $credential) {
        ($this->reveal)($this->custodian, $credential)->assertOk();
        ($this->reveal)($this->manager, $credential)->assertForbidden();
        ($this->reveal)($this->other, $credential)->assertForbidden();
    }

    // The card says per row what this reader may do (the holder sees the thing on My assets, not in the register).
    $rows = collect($this->actingAs($this->holder)->get('/hrm/my-assets')->assertOk()->original->getData()['page']['props']['items'][0]['credentials']['rows'])->keyBy('label');
    expect($rows['Windows login']['can_reveal'])->toBeTrue()
        ->and($rows['BIOS admin']['can_reveal'])->toBeFalse()
        ->and($rows['BIOS admin']['privileged'])->toBeTrue()
        ->and($rows['BitLocker key']['can_reveal'])->toBeFalse()
        ->and($rows['VPN']['can_reveal'])->toBeFalse()
        ->and($rows['VPN']['holder_may_reveal'])->toBeFalse();

    $refusals = ($this->refusals)();
    expect($refusals)->toContain($this->holder->name.' was refused BIOS admin of BDG-IT-0001 ThinkPad T470s (privileged login, no reveal_privileged permission)')
        ->and($refusals)->toContain($this->holder->name.' was refused VPN of BDG-IT-0001 ThinkPad T470s (the holder may not reveal this login)')
        ->and($refusals)->toContain($this->other->name.' was refused Windows login of BDG-IT-0001 ThinkPad T470s (not the current holder)')
        ->and($refusals)->toContain($this->manager->name.' was refused Windows login of BDG-IT-0001 ThinkPad T470s (not the current holder)')
        ->and(count($refusals))->toBe(3 + 8);

    $all = Activity::query()->where('log_name', 'assets.secrets')->get();
    foreach (['win-pass-1111', 'bios-pass-2222', 'rec-key-3333', 'vpn-pass-4444'] as $plaintext) {
        expect($all->pluck('description')->implode(' '))->not->toContain($plaintext)
            ->and(json_encode($all->pluck('properties')))->not->toContain($plaintext);
    }
});

it('logs a reveal refused for want of a fresh password, and sends the secret with no-store headers', function () {
    $windows = ($this->login)('Windows login', 'user_login', 'win-pass-1111');

    $this->actingAs($this->holder)->postJson("/assets/credentials/{$windows->id}/reveal")->assertStatus(423);
    expect(($this->refusals)())->toBe([$this->holder->name.' was refused Windows login of BDG-IT-0001 ThinkPad T470s (password not confirmed)']);

    $response = ($this->reveal)($this->holder, $windows)->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Cache-Control'))->toContain('private')
        ->and($response->headers->get('Pragma'))->toBe('no-cache');

    // A throttle by address, besides the one by user: thirty from one address in a minute.
    $users = [];

    for ($i = 0; $i < 3; $i++) {
        $users[] = ($this->user)('Owner');
    }

    $seen = 0;

    foreach ($users as $user) {
        for ($i = 0; $i < 10; $i++) {
            $seen += ($this->reveal)($user, $windows)->getStatusCode() === 200 ? 1 : 0;
        }
    }

    // ⚠ The throttles run BEFORE the password gate (the framework's middleware
    // priority puts ThrottleRequests ahead of everything route-level), so the
    // refused-for-password attempt above counted too: 1 + 1 + 28 = 30 attempts
    // from this address, and the 31st is refused.
    expect($seen)->toBe(28);
    ($this->reveal)($this->custodian, $windows)->assertStatus(429);
});

it('replaces a secret, records the replacement, and never keeps the old value', function () {
    $windows = ($this->login)('Windows login', 'user_login', 'old-pass-1111');
    AssetCredential::query()->whereKey($windows->id)->update(['needs_review' => true]);

    $this->actingAs($this->manager)->post("/assets/credentials/{$windows->id}/replace", ['secret' => 'new-pass-2222'])->assertForbidden();
    $this->actingAs($this->holder)->post("/assets/credentials/{$windows->id}/replace", ['secret' => 'new-pass-2222'])->assertForbidden();
    $this->actingAs($this->custodian)->post("/assets/credentials/{$windows->id}/replace", ['secret' => ''])->assertSessionHasErrors('secret');
    $this->actingAs($this->custodian)->post("/assets/credentials/{$windows->id}/replace", ['secret' => 'new-pass-2222'])->assertSessionHasNoErrors()->assertRedirect();

    $fresh = $windows->fresh();
    expect($fresh->secret)->toBe('new-pass-2222')
        ->and($fresh->hint)->toBe('2222')
        ->and($fresh->last_rotated_at?->toDateTimeString())->toBe('2026-10-13 10:00:00')
        ->and($fresh->needs_review)->toBeFalse()
        ->and($fresh->updated_by)->toBe($this->custodian->id);

    // Nowhere in the database does the old value survive, encrypted or not.
    $dump = collect(DB::select('select * from asset_credentials'))->map(fn ($row) => json_encode($row))->implode(' ')
        .collect(DB::select('select * from activity_log'))->map(fn ($row) => json_encode($row))->implode(' ');
    expect($dump)->not->toContain('old-pass-1111')->and($dump)->not->toContain('new-pass-2222');

    $line = Activity::query()->where('log_name', 'assets.secrets')->where('description', $this->custodian->name.' replaced Windows login of BDG-IT-0001 ThinkPad T470s')->sole();
    expect($line->subject_id)->toBe($this->laptop->id)
        ->and(Activity::query()->where('description', 'Login changed: Windows login')->exists())->toBeFalse();
});

it('flags every login of a thing for review when it is returned or passed on, and Reviewed clears it', function () {
    $windows = ($this->login)('Windows login', 'user_login', 'win-pass-1111');
    $bios = ($this->login)('BIOS admin', 'admin', 'bios-pass-2222');

    // Reassign through the real action: Rakib hands it to Nazmul.
    app(MoveAssets::class)->handle(AssetMovementKind::Reassign, [['item' => $this->laptop->fresh()]], ['employee_id' => $this->nazmul->id], $this->keeper, '2026-10-13');
    expect($windows->fresh()->needs_review)->toBeTrue()->and($bios->fresh()->needs_review)->toBeTrue();
    $flagged = Activity::query()->where('log_name', 'assets.secrets')->where('description', 'like', '%flagged for review%')->get();
    expect($flagged)->toHaveCount(1)
        ->and($flagged->first()->description)->toStartWith('2 login(s) of BDG-IT-0001 ThinkPad T470s flagged for review after from one person to another')
        ->and($flagged->first()->causer_id)->toBe($this->keeper->id);

    // Reviewed: manage only, clears the flag, logs.
    $this->actingAs($this->manager)->post("/assets/credentials/{$windows->id}/reviewed")->assertForbidden();
    $this->actingAs($this->other)->post("/assets/credentials/{$windows->id}/reviewed")->assertForbidden();
    $this->actingAs($this->owner)->post("/assets/credentials/{$windows->id}/reviewed")->assertSessionHasNoErrors()->assertRedirect();
    expect($windows->fresh()->needs_review)->toBeFalse()->and($bios->fresh()->needs_review)->toBeTrue()
        ->and(Activity::query()->where('description', $this->owner->name.' reviewed Windows login of BDG-IT-0001 ThinkPad T470s and kept it')->exists())->toBeTrue();

    // Return through the real action: flagged again. Only the already-clear one is counted.
    app(MoveAssets::class)->handle(AssetMovementKind::Return, [['item' => $this->laptop->fresh()]], ['office_id' => $this->office->id], $this->keeper, '2026-10-13');
    expect($windows->fresh()->needs_review)->toBeTrue()
        ->and(Activity::query()->where('description', 'like', '1 login(s) of BDG-IT-0001 ThinkPad T470s flagged for review after returned by an employee%')->exists())->toBeTrue();

    // An issue flags nothing.
    Activity::query()->where('description', 'like', '%flagged for review%')->delete();
    AssetCredential::query()->update(['needs_review' => false]);
    app(MoveAssets::class)->handle(AssetMovementKind::Issue, [['item' => $this->laptop->fresh()]], ['employee_id' => $this->rakib->id], $this->keeper, '2026-10-13');
    expect($windows->fresh()->needs_review)->toBeFalse()
        ->and(Activity::query()->where('description', 'like', '%flagged for review%')->exists())->toBeFalse();
});

it('never writes the secret to the application log, a page, or the leaver check', function () {
    $probe = storage_path('logs/credential-probe-'.uniqid().'.log');
    config(['logging.channels.probe' => ['driver' => 'single', 'path' => $probe, 'level' => 'debug'], 'logging.default' => 'probe']);
    Log::info('probe channel alive');

    $windows = ($this->login)('Windows login', 'user_login', 'win-pass-1111');
    $account = CompanyAccount::create(['kind' => 'email', 'name' => 'support@laladia.com', 'identifier' => 'support@laladia.com', 'created_by' => $this->owner->id]);
    $account->access()->create(['employee_id' => $this->rakib->id, 'role' => 'user', 'granted_on' => '2026-10-01', 'granted_by' => $this->owner->id]);
    $mail = ($this->login)('Webmail', 'user_login', 'mail-pass-5555', true, $account);

    ($this->reveal)($this->holder, $windows)->assertOk();
    ($this->reveal)($this->holder, $mail)->assertOk();
    ($this->reveal)($this->other, $windows)->assertForbidden();
    // ⚠ The test session keeps the confirmation between requests: drop it to be unconfirmed again.
    $this->flushSession();
    $this->actingAs($this->holder)->postJson("/assets/credentials/{$windows->id}/reveal")->assertStatus(423);
    // A wrong replace, so the validation path runs too.
    $this->actingAs($this->custodian)->post("/assets/credentials/{$windows->id}/replace", ['secret' => str_repeat('x', 600)])->assertSessionHasErrors('secret');

    $pages = [
        [$this->owner, "/assets/items/{$this->laptop->id}"],
        [$this->owner, "/assets/accounts/{$account->id}"],
        [$this->owner, '/assets/accounts'],
        [$this->owner, "/assets/accounts/leaver-check?employee={$this->rakib->id}"],
        [$this->holder, '/hrm/my-accounts'],
        [$this->holder, '/hrm/my-assets'],
    ];

    foreach ($pages as [$as, $url]) {
        $json = json_encode($this->actingAs($as)->get($url)->assertOk()->original->getData()['page']['props']);
        expect($json)->not->toContain('win-pass-1111', "secret leaked into {$url}")
            ->and($json)->not->toContain('mail-pass-5555', "secret leaked into {$url}")
            ->and($json)->not->toContain('"secret"', "a secret key reached {$url}");
    }

    $csv = $this->actingAs($this->owner)->get('/assets/accounts/export')->assertOk()->streamedContent();
    expect($csv)->not->toContain('mail-pass-5555')->and($csv)->not->toContain('5555');

    $written = file_exists($probe) ? file_get_contents($probe) : '';
    expect($written)->toContain('probe channel alive')
        ->and($written)->not->toContain('win-pass-1111')
        ->and($written)->not->toContain('mail-pass-5555');
    @unlink($probe);
});

it('refuses a deactivated user, a closed account, a revoked holder and a stranger, and a login whose owner is gone is not reachable', function () {
    $windows = ($this->login)('Windows login', 'user_login', 'win-pass-1111');
    $account = CompanyAccount::create(['kind' => 'email', 'name' => 'support@laladia.com', 'identifier' => 'support@laladia.com', 'created_by' => $this->owner->id]);
    $access = $account->access()->create(['employee_id' => $this->rakib->id, 'role' => 'user', 'granted_on' => '2026-10-01', 'granted_by' => $this->owner->id]);
    $mail = ($this->login)('Webmail', 'user_login', 'mail-pass-5555', true, $account);

    // Revoked access: refused, and the card is gone from My accounts.
    ($this->reveal)($this->holder, $mail)->assertOk();
    $access->update(['revoked_on' => '2026-10-13']);
    ($this->reveal)($this->holder, $mail)->assertForbidden();
    expect($this->actingAs($this->holder)->get('/hrm/my-accounts')->assertOk()->original->getData()['page']['props']['accounts'])->toBe([]);

    // A closed account: nobody, not even the Owner.
    $account->update(['status' => 'closed']);
    ($this->reveal)($this->owner, $mail)->assertForbidden();
    expect(($this->refusals)())->toContain($this->owner->name.' was refused Webmail of support@laladia.com (account closed)');

    // A deactivated user: the middleware signs them out before the controller.
    $this->owner->update(['is_active' => false]);
    $response = ($this->reveal)($this->owner, $windows);
    expect($response->getStatusCode())->not->toBe(200)
        ->and($response->getContent())->not->toContain('win-pass-1111');
    $this->owner->update(['is_active' => true]);

    // A stranger from another business: 404, the row does not exist for them.
    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-co']);
    Role::create(['name' => 'Owner', 'guard_name' => 'web', 'business_id' => $other->id]);
    TenantScope::enter($other->id);
    (new AssetsPermissionsSeeder)->run();
    TenantScope::enter($other->id);
    $stranger = User::factory()->create();
    $stranger->businesses()->attach($other->id);
    setPermissionsTeamId($other->id);
    $stranger->assignRole('Owner');
    $this->actingAs($stranger)->withSession(['auth.password_confirmed_at' => now()->timestamp])->withHeader('X-Business-Id', (string) $other->id)
        ->postJson("/assets/credentials/{$windows->id}/reveal")->assertNotFound();
    TenantScope::enter($this->business->id);
    setPermissionsTeamId($this->business->id);
    // ⚠ The stranger's request left their business in the test session and its
    // header among the test's default headers (withHeader persists): start clean,
    // or EnsurePageBusinessMatches answers every later request with a 303.
    $this->flushSession();
    $this->withoutHeader('X-Business-Id');

    // An owner that is deleted takes its logins with it: nothing is left to reach.
    $account->update(['status' => 'active']);
    $account->delete();
    expect(AssetCredential::query()->withoutGlobalScopes()->whereKey($mail->id)->exists())->toBeFalse();
    ($this->reveal)($this->custodian, $mail)->assertNotFound();

    // A row that somehow outlives its owner is a 404 too, not a 403 and not a secret.
    $orphan = ($this->login)('Ghost', 'user_login', 'ghost-pass-9999');
    AssetCredential::query()->whereKey($orphan->id)->update(['credentialable_id' => 999999]);
    $response = ($this->reveal)($this->custodian, $orphan)->assertNotFound();
    expect($response->getContent())->not->toContain('ghost-pass-9999');
});

it('stops the holder revealing the logins of a thing a stock-take marked missing, and flags them for review (FEA-006)', function () {
    $windows = ($this->login)('Windows login', 'user_login', 'win-pass-1111');
    ($this->reveal)($this->holder, $windows)->assertOk();

    $counts = app(CountAssets::class);
    $count = $counts->start($this->business, ['office_id' => $this->office->id], $this->keeper);
    $counts->mark($count, $this->laptop, false, $this->keeper);
    $counts->close($count, $this->keeper);

    expect($this->laptop->fresh()->status)->toBe(AssetStatus::Missing)
        ->and($windows->fresh()->needs_review)->toBeTrue();

    ($this->reveal)($this->holder, $windows)->assertForbidden();
    expect(collect(($this->refusals)())->contains(fn ($line) => str_contains($line, 'thing missing')))->toBeTrue();
    // IT can still read it to change it.
    ($this->reveal)($this->custodian, $windows)->assertOk();
});
