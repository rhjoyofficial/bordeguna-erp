<?php

use App\Models\User;
use App\Modules\Assets\Actions\MoveAssets;
use App\Modules\Assets\Actions\SaveAssetItem;
use App\Modules\Assets\database\seeders\AssetCategorySeeder;
use App\Modules\Assets\database\seeders\AssetsPermissionsSeeder;
use App\Modules\Assets\Enums\AssetMovementKind;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Models\AssetCredential;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\Ai\ReadableData;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Asset register slice 3 (13 October 2026): the logins a thing carries.
 *
 * ⭐ Stored as ciphertext; never in a page's props; revealed once, as JSON,
 * to the holder or a secrets permission, after the person re-typed their own
 * password; every reveal logged without the secret.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-13 10:00:00');
    Storage::fake('local');

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

    $this->manager = ($this->user)('Manager');
    $this->keeper = ($this->user)('Asset Keeper');
    $this->custodian = ($this->user)('IT Custodian');
    $this->holder = ($this->user)('Staff');
    $this->bystander = ($this->user)('Staff');

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
    $this->nazmul = ($this->employee)('Nazmul', 'BDG-000002', $this->bystander);

    $this->laptop = app(SaveAssetItem::class)->register(
        AssetCategory::query()->where('code', 'IT')->sole(),
        ['name' => 'ThinkPad T470s', 'office_id' => $this->office->id, 'status' => 'in_store', 'condition' => 'good'],
        $this->keeper,
    )->first();
    app(MoveAssets::class)->handle(AssetMovementKind::Issue, [['item' => $this->laptop]], ['employee_id' => $this->rakib->id], $this->keeper, '2026-10-13');

    $this->plaintext = 'Tr0ub4dor&3-laptop';
    $this->add = fn (User $by, array $overrides = []) => $this->actingAs($by)->post("/assets/items/{$this->laptop->id}/credentials", array_replace([
        'label' => 'Windows login', 'username' => 'rakib', 'secret' => $this->plaintext, 'host_or_url' => null, 'note' => null,
    ], $overrides));
    // ⚠ The framework reads the clock through Date::now(), which the test froze; time() is the wall clock.
    $this->confirmed = fn (User $as) => $this->actingAs($as)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    $this->itemProps = fn (User $as) => $this->actingAs($as)->get("/assets/items/{$this->laptop->id}")->assertOk()->original->getData()['page']['props'];
});

afterEach(fn () => Carbon::setTestNow());

it('stores the secret as ciphertext with a four-character hint, and never sends it to a page', function () {
    ($this->add)($this->manager)->assertSessionHasNoErrors()->assertRedirect();

    $credential = AssetCredential::query()->sole();
    $raw = DB::table('asset_credentials')->where('id', $credential->id)->value('secret');

    expect($raw)->not->toBe($this->plaintext)
        ->and(str_contains((string) $raw, $this->plaintext))->toBeFalse()
        ->and($credential->hint)->toBe('ptop')
        ->and($credential->secret)->toBe($this->plaintext)
        ->and($credential->toArray())->not->toHaveKey('secret');

    $props = ($this->itemProps)($this->manager);
    $json = json_encode($props);

    expect($props['credentials']['rows'])->toHaveCount(1)
        ->and($props['credentials']['rows'][0])->toMatchArray(['label' => 'Windows login', 'username' => 'rakib', 'hint' => 'ptop'])
        ->and($props['credentials']['can_manage'])->toBeTrue()
        ->and($props['credentials']['can_reveal'])->toBeTrue()
        ->and($props['credentials']['confirm_minutes'])->toBe(max(1, intdiv((int) config('auth.password_timeout'), 60)))
        ->and(str_contains($json, $this->plaintext))->toBeFalse()
        ->and(str_contains($json, '"secret"'))->toBeFalse();

    // The model's own log says a login was added, with its label and never its secret.
    $added = Activity::query()->where('log_name', 'assets.secrets')->where('description', 'Login added: Windows login')->sole();
    expect(json_encode($added->properties))->not->toContain($this->plaintext)
        ->and($added->properties['attributes'])->toMatchArray(['label' => 'Windows login', 'username' => 'rakib'])
        ->and($added->properties['attributes'])->not->toHaveKey('secret');
});

it('draws the card only for someone who may reveal or manage', function () {
    ($this->add)($this->manager);

    // A keeper keeps the thing and does not hold its password: no card at all.
    expect(($this->itemProps)($this->keeper)['credentials'])->toBeNull()
        ->and(($this->itemProps)($this->custodian)['credentials']['can_manage'])->toBeTrue();
});

it('reveals to the holder and to a secrets permission, refuses everyone else, and logs each look without the secret', function () {
    ($this->add)($this->manager);
    $credential = AssetCredential::query()->sole();

    // A bystander in the business, not the holder, no permission.
    ($this->confirmed)($this->bystander)->postJson("/assets/credentials/{$credential->id}/reveal")->assertForbidden();
    // The keeper manages things, not secrets.
    ($this->confirmed)($this->keeper)->postJson("/assets/credentials/{$credential->id}/reveal")->assertForbidden();

    // The holder of the laptop reads its password.
    ($this->confirmed)($this->holder)->postJson("/assets/credentials/{$credential->id}/reveal")
        ->assertOk()->assertExactJson(['secret' => $this->plaintext]);

    $fresh = $credential->fresh();
    expect($fresh->last_revealed_by)->toBe($this->holder->id)
        ->and($fresh->last_revealed_at?->toDateTimeString())->toBe('2026-10-13 10:00:00');

    $look = Activity::query()->where('log_name', 'assets.secrets')->where('description', 'like', '%revealed%')->sole();
    expect($look->description)->toBe($this->holder->name.' revealed Windows login of BDG-IT-0001 ThinkPad T470s')
        ->and($look->causer_id)->toBe($this->holder->id)
        ->and($look->subject_id)->toBe($this->laptop->id)
        ->and(json_encode($look->properties))->not->toContain($this->plaintext);

    // Once the laptop is returned, the former holder is a bystander again.
    app(MoveAssets::class)->handle(AssetMovementKind::Return, [['item' => $this->laptop->fresh()]], ['office_id' => $this->office->id], $this->keeper, '2026-10-13');
    ($this->confirmed)($this->holder)->postJson("/assets/credentials/{$credential->id}/reveal")->assertForbidden();
    ($this->confirmed)($this->custodian)->postJson("/assets/credentials/{$credential->id}/reveal")->assertOk();
});

it('asks for the password first: 423 until the session holds a fresh confirmation', function () {
    ($this->add)($this->manager);
    $credential = AssetCredential::query()->sole();

    $this->actingAs($this->manager)->postJson("/assets/credentials/{$credential->id}/reveal")->assertStatus(423);
    expect(Activity::query()->where('description', 'like', '%revealed%')->exists())->toBeFalse();

    // A stale confirmation is no confirmation.
    $this->actingAs($this->manager)->withSession(['auth.password_confirmed_at' => now()->timestamp - (int) config('auth.password_timeout') - 1])
        ->postJson("/assets/credentials/{$credential->id}/reveal")->assertStatus(423);

    ($this->confirmed)($this->manager)->postJson("/assets/credentials/{$credential->id}/reveal")->assertOk();

    // The confirm helper sends the person to the password page and remembers where to come back to.
    $this->actingAs($this->manager)->get('/assets/credentials/confirm?back='.urlencode("/assets/items/{$this->laptop->id}"))
        ->assertRedirect(route('password.confirm'))
        ->assertSessionHas('url.intended', url("/assets/items/{$this->laptop->id}"));
    // An outside address is not a place to come back to.
    $this->actingAs($this->manager)->get('/assets/credentials/confirm?back='.urlencode('https://evil.example/x'))
        ->assertSessionHas('url.intended', route('assets.items.index'));
});

it('throttles reveals to ten a minute', function () {
    ($this->add)($this->manager);
    $credential = AssetCredential::query()->sole();

    for ($i = 0; $i < 10; $i++) {
        ($this->confirmed)($this->manager)->postJson("/assets/credentials/{$credential->id}/reveal")->assertOk();
    }

    ($this->confirmed)($this->manager)->postJson("/assets/credentials/{$credential->id}/reveal")->assertStatus(429);
});

it('lets a secrets manager add, change and remove a login, and refuses a keeper', function () {
    ($this->add)($this->keeper)->assertForbidden();
    ($this->add)($this->manager, ['secret' => ''])->assertSessionHasErrors('secret');
    ($this->add)($this->manager)->assertSessionHasNoErrors();
    ($this->add)($this->custodian, ['label' => 'BIOS', 'username' => null, 'secret' => '4321'])->assertSessionHasNoErrors();

    $windows = AssetCredential::query()->where('label', 'Windows login')->sole();
    $bios = AssetCredential::query()->where('label', 'BIOS')->sole();
    expect($bios->hint)->toBe('4321');

    // A blank secret on an edit keeps the stored one; a new one replaces it and its hint.
    $this->actingAs($this->keeper)->put("/assets/credentials/{$windows->id}", ['label' => 'Windows', 'secret' => ''])->assertForbidden();
    $this->actingAs($this->manager)->put("/assets/credentials/{$windows->id}", ['label' => 'Windows', 'username' => 'rakib', 'secret' => ''])->assertSessionHasNoErrors();
    expect($windows->fresh()->label)->toBe('Windows')
        ->and($windows->fresh()->secret)->toBe($this->plaintext)
        ->and($windows->fresh()->updated_by)->toBe($this->manager->id);
    $this->actingAs($this->manager)->put("/assets/credentials/{$windows->id}", ['label' => 'Windows', 'username' => 'rakib', 'secret' => 'NewPass-9876'])->assertSessionHasNoErrors();
    expect($windows->fresh()->secret)->toBe('NewPass-9876')->and($windows->fresh()->hint)->toBe('9876');

    $changed = Activity::query()->where('log_name', 'assets.secrets')->where('description', 'Login changed: Windows')->first();
    expect($changed)->not->toBeNull()
        ->and(json_encode($changed->properties))->not->toContain('NewPass-9876')
        ->and(json_encode($changed->properties))->not->toContain($this->plaintext);

    $this->actingAs($this->keeper)->delete("/assets/credentials/{$bios->id}")->assertForbidden();
    $this->actingAs($this->custodian)->delete("/assets/credentials/{$bios->id}")->assertRedirect();
    expect(AssetCredential::query()->count())->toBe(1)
        ->and(Activity::query()->where('description', 'Login removed: BIOS')->exists())->toBeTrue();
});

it('keeps logins away from the AI assistant and from another business', function () {
    expect(ReadableData::tables())->not->toContain('asset_credentials')
        ->and(ReadableData::tables())->not->toContain('company_accounts');

    ($this->add)($this->manager);
    $credential = AssetCredential::query()->sole();

    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-co']);
    Role::create(['name' => 'Owner', 'guard_name' => 'web', 'business_id' => $other->id]);
    TenantScope::enter($other->id);
    (new AssetsPermissionsSeeder)->run();
    TenantScope::enter($other->id);
    $stranger = User::factory()->create();
    $stranger->businesses()->attach($other->id);
    setPermissionsTeamId($other->id);
    $stranger->assignRole('Owner');

    $this->actingAs($stranger)->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->withHeader('X-Business-Id', (string) $other->id)
        ->postJson("/assets/credentials/{$credential->id}/reveal")->assertNotFound();
});
