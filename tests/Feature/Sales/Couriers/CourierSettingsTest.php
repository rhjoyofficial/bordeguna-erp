<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Couriers\Models\CourierAccount;
use App\Modules\Sales\Couriers\Models\CourierAccountBusiness;
use App\Modules\Sales\Couriers\Models\CourierProvider;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Settings > Couriers, courier hub slice 1 (11 Oct 2026). Joy: "all settings
 * must be available on UI so I can change them anytime".
 *
 * ⚠⚠ Most tests here are about what must NOT happen: a secret reaching a
 * browser or a log, a credential posted to a URL nobody vetted, an account
 * switched on with credentials nobody tested.
 */
const CB_SECRET = 'cb-secret-VALUE-9f8e7d';
const CB_CONTEXT = 'cb-context-VALUE-1a2b3c';

beforeEach(function () {
    $this->business = Business::create(['name' => 'LaLaDia', 'slug' => 'laladia']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new ChartOfAccountsSeeder)->run();
    (new SalesPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['owner' => 'Owner', 'manager' => 'Manager', 'staff' => 'Staff'] as $prop => $role) {
        $this->{$prop} = User::factory()->create();
        $this->{$prop}->businesses()->attach($this->business->id);
        $this->{$prop}->assignRole($role);
    }

    $this->courierMoney = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'CarryBee LaLaDia', 'type' => MoneyAccountType::Courier,
        'provider' => 'CarryBee', 'account_id' => Account::bySlug('courier_receivable')->id, 'is_active' => true,
    ]);

    Http::preventStrayRequests();
    // The worktree has no built assets; the page props are what is tested.
    $this->withoutVite();
});

function carryBeeInput(array $overrides = []): array
{
    return array_replace_recursive([
        'provider' => 'carrybee',
        'label' => 'LaLaDia',
        'environment' => 'live',
        'base_url' => 'https://developers.carrybee.com',
        'credentials' => ['client_id' => 'cb-client-id-1234', 'client_secret' => CB_SECRET, 'client_context' => CB_CONTEXT],
        'options' => ['delivery_type' => '1', 'product_type' => '1', 'cod_percent' => '0.5', 'max_weight_kg' => '25'],
    ], $overrides);
}

function fakeCarryBeeOk(): void
{
    Http::fake([
        'https://developers.carrybee.com/api/v2/stores' => Http::response(['error' => false, 'message' => 'ok', 'data' => ['stores' => [
            ['id' => '24869', 'name' => 'Adabor 2', 'is_active' => true],
            ['id' => '17237', 'name' => 'La La Dia (Shekertek)', 'is_active' => false],
        ]]]),
        'https://developers.carrybee.com/api/v2/cities' => Http::response(['error' => false, 'message' => 'ok', 'data' => ['cities' => array_map(fn ($i) => ['id' => $i, 'name' => "City {$i}"], range(1, 64))]]),
    ]);
}

function makeCarryBeeAccount($test): CourierAccount
{
    $test->actingAs($test->owner)->post('/settings/couriers', carryBeeInput())->assertSessionHasNoErrors();

    return CourierAccount::query()->latest('id')->firstOrFail();
}

it('lets the owner open Settings > Couriers with the providers listed', function () {
    $this->actingAs($this->owner)->get('/settings/couriers')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/couriers')
            ->has('providers', 5)
            ->where('providers.0.code', 'pathao')
            ->where('providers.0.connected', true)
            ->where('providers.2.code', 'steadfast')
            ->where('providers.2.connected', false)
            ->has('accounts', 0));
});

it('keeps managers and staff out of courier settings', function () {
    $this->actingAs($this->manager)->get('/settings/couriers')->assertForbidden();
    $this->actingAs($this->staff)->get('/settings/couriers')->assertForbidden();
    $this->actingAs($this->manager)->post('/settings/couriers', carryBeeInput())->assertForbidden();

    expect(CourierAccount::count())->toBe(0);
});

it('grants the parcel permissions by role and not as accounting authority', function () {
    expect($this->owner->can('couriers.settings.manage'))->toBeTrue()
        ->and($this->manager->can('couriers.settings.manage'))->toBeFalse()
        ->and($this->staff->can('couriers.parcels.view'))->toBeTrue()
        ->and($this->staff->can('couriers.parcels.book'))->toBeFalse()
        ->and($this->manager->can('couriers.parcels.book'))->toBeTrue();
});

it('encrypts credentials at rest and never sends them to the browser', function () {
    $account = makeCarryBeeAccount($this);

    $raw = DB::table('courier_accounts')->where('id', $account->id)->value('credentials');
    expect($raw)->not->toContain(CB_SECRET)->not->toContain(CB_CONTEXT)->not->toContain('cb-client-id-1234');
    expect($account->fresh()->credential('client_secret'))->toBe(CB_SECRET);

    $response = $this->actingAs($this->owner)->get('/settings/couriers')->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('accounts', 1)
            ->where('accounts.0.credentials_set', ['client_id' => true, 'client_secret' => true, 'client_context' => true])
            ->where('accounts.0.is_active', false)
            ->missing('accounts.0.credentials')
            ->missing('accounts.0.webhook_secret'));

    $html = $response->getContent();
    expect($html)->not->toContain(CB_SECRET)->not->toContain(CB_CONTEXT)->not->toContain('cb-client-id-1234')
        ->not->toContain($account->fresh()->webhook_secret);

    expect($account->fresh()->toArray())->not->toHaveKey('credentials')->not->toHaveKey('webhook_secret');
});

it('keeps saved secrets when the form sends them blank', function () {
    $account = makeCarryBeeAccount($this);

    $this->actingAs($this->owner)->put("/settings/couriers/{$account->id}", carryBeeInput([
        'label' => 'LaLaDia main',
        'credentials' => ['client_id' => '', 'client_secret' => '', 'client_context' => ''],
    ]))->assertSessionHasNoErrors();

    $account->refresh();
    expect($account->label)->toBe('LaLaDia main')
        ->and($account->credential('client_secret'))->toBe(CB_SECRET)
        ->and($account->credential('client_context'))->toBe(CB_CONTEXT)
        ->and($account->credential('client_id'))->toBe('cb-client-id-1234');
});

it('requires every credential when an account is created', function () {
    $this->actingAs($this->owner)->post('/settings/couriers', carryBeeInput(['credentials' => ['client_context' => '']]))
        ->assertSessionHasErrors('credentials.client_context');

    expect(CourierAccount::count())->toBe(0);
});

it('refuses a base URL outside the provider allow-list', function (string $env, string $url) {
    $this->actingAs($this->owner)->post('/settings/couriers', carryBeeInput(['environment' => $env, 'base_url' => $url]))
        ->assertSessionHasErrors('base_url');

    expect(CourierAccount::count())->toBe(0);
})->with([
    'attacker host' => ['live', 'https://evil.example.com'],
    'look-alike' => ['live', 'https://developers.carrybee.com.evil.io'],
    'plain http' => ['live', 'http://developers.carrybee.com'],
    'sandbox URL as live' => ['live', 'https://sandbox.carrybee.com'],
    'pathao URL for carrybee' => ['live', 'https://api-hermes.pathao.com'],
]);

it('refuses to change an existing account to a URL outside the allow-list', function () {
    $account = makeCarryBeeAccount($this);

    $this->actingAs($this->owner)->put("/settings/couriers/{$account->id}", carryBeeInput(['base_url' => 'https://127.0.0.1']))
        ->assertSessionHasErrors('base_url');

    expect($account->fresh()->base_url)->toBe('https://developers.carrybee.com');
});

it('validates options against the provider schema', function () {
    $this->actingAs($this->owner)->post('/settings/couriers', carryBeeInput(['options' => ['delivery_type' => '48']]))
        ->assertSessionHasErrors('options.delivery_type');
    $this->actingAs($this->owner)->post('/settings/couriers', carryBeeInput(['options' => ['max_weight_kg' => '40']]))
        ->assertSessionHasErrors('options.max_weight_kg');
    $this->actingAs($this->owner)->post('/settings/couriers', carryBeeInput(['options' => ['cod_percent' => 'abc']]))
        ->assertSessionHasErrors('options.cod_percent');

    $this->actingAs($this->owner)->post('/settings/couriers', carryBeeInput(['options' => ['delivery_type' => '2', 'unknown' => 'x']]))
        ->assertSessionHasNoErrors();

    expect(CourierAccount::sole()->options)->toEqual(['delivery_type' => 2, 'product_type' => 1, 'cod_percent' => 0.5, 'max_weight_kg' => 25]);
});

it('checks Pathao weights are in order', function () {
    $this->actingAs($this->owner)->post('/settings/couriers', [
        'provider' => 'pathao', 'label' => 'Bionic', 'environment' => 'live', 'base_url' => 'https://api-hermes.pathao.com',
        'credentials' => ['client_id' => 'p1', 'client_secret' => 'p2secret', 'username' => 'm@x.com', 'password' => 'pw123456'],
        'options' => ['min_weight_kg' => '5', 'max_weight_kg' => '2'],
    ])->assertSessionHasErrors('options.min_weight_kg');
});

it('cannot add an account for a courier that is not connected', function () {
    $this->actingAs($this->owner)->post('/settings/couriers', carryBeeInput(['provider' => 'steadfast']))
        ->assertSessionHasErrors('provider');
});

it('activates only after a passing test with the current credentials', function () {
    $account = makeCarryBeeAccount($this);

    $this->actingAs($this->owner)->post("/settings/couriers/{$account->id}/activate")->assertSessionHasErrors('activate');
    expect($account->fresh()->is_active)->toBeFalse();

    fakeCarryBeeOk();
    $this->actingAs($this->owner)->post("/settings/couriers/{$account->id}/test")->assertSessionHas('success');

    $account->refresh();
    expect($account->last_test_ok)->toBeTrue()
        ->and($account->last_test_city_count)->toBe(64)
        ->and($account->testedStores())->toHaveCount(2);

    $this->actingAs($this->owner)->post("/settings/couriers/{$account->id}/activate")->assertSessionHasNoErrors();
    expect($account->fresh()->is_active)->toBeTrue()->and($account->fresh()->activated_at)->not->toBeNull();
});

it('switches an active account off when its credentials change, and needs a new test', function () {
    $account = makeCarryBeeAccount($this);
    fakeCarryBeeOk();
    $this->actingAs($this->owner)->post("/settings/couriers/{$account->id}/test");
    $this->actingAs($this->owner)->post("/settings/couriers/{$account->id}/activate");
    expect($account->fresh()->is_active)->toBeTrue();

    $this->actingAs($this->owner)->put("/settings/couriers/{$account->id}", carryBeeInput([
        'credentials' => ['client_id' => '', 'client_secret' => 'a-brand-new-secret', 'client_context' => ''],
    ]))->assertSessionHasNoErrors();

    $account->refresh();
    expect($account->is_active)->toBeFalse()
        ->and($account->testIsCurrent())->toBeFalse()
        ->and($account->canActivate())->toBeFalse()
        ->and($account->testedStores())->toBe([]);

    $this->actingAs($this->owner)->post("/settings/couriers/{$account->id}/activate")->assertSessionHasErrors('activate');
});

it('switches an active account off when its environment changes', function () {
    $account = makeCarryBeeAccount($this);
    fakeCarryBeeOk();
    $this->actingAs($this->owner)->post("/settings/couriers/{$account->id}/test");
    $this->actingAs($this->owner)->post("/settings/couriers/{$account->id}/activate");

    $this->actingAs($this->owner)->put("/settings/couriers/{$account->id}", carryBeeInput([
        'environment' => 'sandbox', 'base_url' => 'https://sandbox.carrybee.com',
    ]))->assertSessionHasNoErrors();

    expect($account->fresh()->is_active)->toBeFalse();
});

it('keeps an active account on when only the label or options change', function () {
    $account = makeCarryBeeAccount($this);
    fakeCarryBeeOk();
    $this->actingAs($this->owner)->post("/settings/couriers/{$account->id}/test");
    $this->actingAs($this->owner)->post("/settings/couriers/{$account->id}/activate");

    $this->actingAs($this->owner)->put("/settings/couriers/{$account->id}", carryBeeInput([
        'label' => 'Renamed', 'credentials' => ['client_id' => '', 'client_secret' => '', 'client_context' => ''],
        'options' => ['delivery_type' => '2'],
    ]))->assertSessionHasNoErrors();

    expect($account->fresh()->is_active)->toBeTrue();
});

it('shows a rotated webhook secret once and never again', function () {
    $account = makeCarryBeeAccount($this);
    $old = $account->webhook_secret;

    $this->actingAs($this->owner)->post("/settings/couriers/{$account->id}/rotate-webhook-secret")->assertSessionHas('courier_webhook_secret');
    $new = $account->fresh()->webhook_secret;
    expect($new)->not->toBe($old)->and(strlen($new))->toBe(48);

    $this->actingAs($this->owner)->get('/settings/couriers')
        ->assertInertia(fn ($page) => $page->where('revealedSecret.secret', $new)->where('revealedSecret.account_id', $account->id));

    $this->actingAs($this->owner)->get('/settings/couriers')
        ->assertInertia(fn ($page) => $page->where('revealedSecret', null)->where('accounts.0.webhook_url', fn ($url) => str_ends_with($url, '/webhooks/couriers/'.$account->uuid)));
});

it('logs account changes without credentials or secrets', function () {
    $account = makeCarryBeeAccount($this);
    $this->actingAs($this->owner)->put("/settings/couriers/{$account->id}", carryBeeInput([
        'label' => 'Changed', 'credentials' => ['client_secret' => 'another-secret-xyz'],
    ]));
    $this->actingAs($this->owner)->post("/settings/couriers/{$account->id}/rotate-webhook-secret");

    $logs = Activity::query()->where('subject_type', $account->getMorphClass())->where('subject_id', $account->id)->get();
    expect($logs)->not->toBeEmpty();

    $dump = $logs->map(fn ($l) => json_encode($l->properties).$l->description)->implode("\n");
    expect($dump)->toContain('Changed')
        ->not->toContain(CB_SECRET)->not->toContain('another-secret-xyz')->not->toContain(CB_CONTEXT)
        ->not->toContain($account->fresh()->webhook_secret)
        ->not->toContain('credentials');
});

it('links the account to this business with a tested store and a courier money account', function () {
    $account = makeCarryBeeAccount($this);

    // No test yet: no store list.
    $this->actingAs($this->owner)->put("/settings/couriers/{$account->id}/business", [
        'provider_store_id' => '24869', 'money_account_id' => $this->courierMoney->id,
    ])->assertSessionHasErrors('provider_store_id');

    fakeCarryBeeOk();
    $this->actingAs($this->owner)->post("/settings/couriers/{$account->id}/test");

    $this->actingAs($this->owner)->put("/settings/couriers/{$account->id}/business", [
        'provider_store_id' => '99999', 'money_account_id' => $this->courierMoney->id,
    ])->assertSessionHasErrors('provider_store_id');

    $this->actingAs($this->owner)->put("/settings/couriers/{$account->id}/business", [
        'provider_store_id' => '24869', 'money_account_id' => $this->courierMoney->id, 'is_default' => true,
    ])->assertSessionHasNoErrors();

    $link = CourierAccountBusiness::sole();
    expect($link->business_id)->toBe($this->business->id)
        ->and($link->provider_store_name)->toBe('Adabor 2')
        ->and($link->money_account_id)->toBe($this->courierMoney->id)
        ->and($link->is_default)->toBeTrue();

    $this->actingAs($this->owner)->get('/settings/couriers')
        ->assertInertia(fn ($page) => $page->where('accounts.0.link.provider_store_id', '24869')->has('moneyAccounts', 1));
});

it('refuses a money account that is not a courier account of this business', function () {
    $account = makeCarryBeeAccount($this);
    fakeCarryBeeOk();
    $this->actingAs($this->owner)->post("/settings/couriers/{$account->id}/test");

    $bank = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'BRAC', 'type' => MoneyAccountType::Bank,
        'account_id' => Account::bySlug('courier_receivable')->id, 'is_active' => true,
    ]);

    $other = Business::create(['name' => 'Bionic', 'slug' => 'bionic']);
    $theirs = MoneyAccount::withoutGlobalScopes()->create([
        'business_id' => $other->id, 'name' => 'CarryBee Bionic', 'type' => MoneyAccountType::Courier,
        'account_id' => Account::bySlug('courier_receivable')->id, 'is_active' => true,
    ]);

    foreach ([$bank->id, $theirs->id, 999999] as $id) {
        $this->actingAs($this->owner)->put("/settings/couriers/{$account->id}/business", [
            'provider_store_id' => '24869', 'money_account_id' => $id,
        ])->assertSessionHasErrors('money_account_id');
    }

    expect(DB::table('courier_account_business')->count())->toBe(0);
});

it('lets one account serve two businesses, each with its own store and money account', function () {
    $account = makeCarryBeeAccount($this);
    fakeCarryBeeOk();
    $this->actingAs($this->owner)->post("/settings/couriers/{$account->id}/test");
    $this->actingAs($this->owner)->put("/settings/couriers/{$account->id}/business", [
        'provider_store_id' => '24869', 'money_account_id' => $this->courierMoney->id,
    ])->assertSessionHasNoErrors();

    $other = Business::create(['name' => 'Bionic', 'slug' => 'bionic']);
    $this->owner->businesses()->attach($other->id);
    Role::create(['name' => 'Owner', 'guard_name' => 'web', 'business_id' => $other->id]);
    (new SalesPermissionsSeeder)->run();
    setPermissionsTeamId($other->id);
    $this->owner->assignRole(Role::where('business_id', $other->id)->where('name', 'Owner')->first());
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $theirMoney = MoneyAccount::withoutGlobalScopes()->create([
        'business_id' => $other->id, 'name' => 'CarryBee Bionic', 'type' => MoneyAccountType::Courier,
        'account_id' => Account::bySlug('courier_receivable')->id, 'is_active' => true,
    ]);

    $this->actingAs($this->owner)->withSession(['current_business_id' => $other->id])
        ->put("/settings/couriers/{$account->id}/business", [
            'provider_store_id' => '17237', 'money_account_id' => $theirMoney->id,
        ])->assertSessionHasNoErrors();

    $rows = DB::table('courier_account_business')->orderBy('business_id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->money_account_id)->toBe($this->courierMoney->id)
        ->and($rows[1]->money_account_id)->toBe($theirMoney->id)
        ->and($rows[1]->provider_store_id)->toBe('17237');
});

it('seeds the five providers', function () {
    expect(CourierProvider::orderBy('sort_order')->pluck('is_connected', 'code')->all())->toBe([
        'pathao' => true, 'carrybee' => true, 'steadfast' => false, 'redx' => false, 'paperfly' => false,
    ]);
});
