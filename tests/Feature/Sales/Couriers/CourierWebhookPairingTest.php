<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Couriers\Actions\SaveCourierAccount;
use App\Modules\Sales\Couriers\Models\CourierAccount;
use App\Modules\Sales\Couriers\Models\CourierAccountBusiness;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Support\Attention\AttentionItem;
use App\Support\Attention\NeedsAttention;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The webhook pairing guard (11 Oct 2026). Three times in one day a courier's
 * connect button said "Validation error" because the value it wants back in
 * our answer was not saved, and nothing in the ERP said why. Every attempt is
 * now recorded on the account and a failed one tells the owner what to do.
 */
const CWP_ECHO = '40489fe0-9386-4fc9-8e92-2b2fcb9d451c';

beforeEach(function () {
    Carbon::setTestNow('2026-10-11 10:00:00');

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

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->courierMoney = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'CarryBee LaLaDia', 'type' => MoneyAccountType::Courier,
        'provider' => 'CarryBee', 'account_id' => Account::bySlug('courier_receivable')->id, 'is_active' => true,
    ]);

    Http::preventStrayRequests();
    $this->withoutVite();
});

function cwpAccount(object $t, string $provider = 'carrybee', string $label = 'Bionic', bool $active = true): CourierAccount
{
    $account = app(SaveCourierAccount::class)->handle(null, $provider === 'carrybee' ? [
        'provider' => 'carrybee', 'label' => $label, 'environment' => 'live', 'base_url' => 'https://developers.carrybee.com',
        'credentials' => ['client_id' => 'cb-id', 'client_secret' => 'cb-SECRET-1234', 'client_context' => 'cb-CONTEXT-5678'],
    ] : [
        'provider' => 'pathao', 'label' => $label, 'environment' => 'live', 'base_url' => 'https://api-hermes.pathao.com',
        'credentials' => ['client_id' => 'p-id', 'client_secret' => 'p-SECRET-1234', 'username' => 'ops@bionic.garden', 'password' => 'p-PASSWORD-99'],
    ]);

    if ($active) {
        $account->forceFill(['last_test_at' => now(), 'last_test_ok' => true, 'tested_fingerprint' => $account->currentFingerprint()])->save();
        $account->forceFill(['is_active' => true, 'activated_at' => now()])->save();
    }

    CourierAccountBusiness::query()->withoutGlobalScopes()->create([
        'courier_account_id' => $account->id, 'business_id' => $t->business->id, 'provider_store_id' => '24869',
        'provider_store_name' => 'Adabor 2', 'money_account_id' => $t->courierMoney->id, 'is_default' => true,
    ]);

    return $account->fresh(['provider']);
}

/** @return list<AttentionItem> */
function cwpItems(object $t): array
{
    return array_values(array_filter(
        app(NeedsAttention::class)->for($t->owner, $t->business->id)['items'],
        fn ($item) => $item->source === 'courier_webhook_pairing',
    ));
}

function cwpHandshake(object $t, CourierAccount $account, ?string $signature = null)
{
    return $t->postJson('/webhooks/couriers/'.$account->uuid, ['event' => 'webhook.integration'], [
        'X-CarryBee-Webhook-Signature' => $signature ?? $account->webhookSecretValue(),
    ]);
}

it('records a CarryBee pairing with no reply value saved, answers 202 without it, and tells the owner what to do', function () {
    Log::spy();
    $account = cwpAccount($this);

    $response = cwpHandshake($this, $account)->assertStatus(202);
    expect($response->headers->has('X-CB-Webhook-Integration-Header'))->toBeFalse();

    $account->refresh();
    expect($account->webhook_last_handshake_at?->toDateTimeString())->toBe('2026-10-11 10:00:00')
        ->and($account->webhook_last_handshake_ok)->toBeFalse()
        ->and($account->webhook_last_handshake_problem)->toBe(CourierAccount::PAIRING_MISSING_ECHO)
        ->and($account->webhook_last_event_at)->toBeNull();

    $items = cwpItems($this);
    expect($items)->toHaveCount(1)
        ->and($items[0]->key)->toBe("courier_webhook_pairing:{$account->id}:missing_echo")
        ->and($items[0]->reasonKey)->toBe('attention.reason.courier_pairing_missing_echo')
        ->and($items[0]->url)->toBe('/settings/couriers')
        ->and($items[0]->reason)->toContain("CarryBee 'Bionic' tried to connect its webhook")
        ->and($items[0]->reason)->toContain('CarryBee Developer > Webhook Integration')
        ->and($items[0]->reason)->toContain('X-CB-Webhook-Integration-Header');

    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => str_contains($message, 'reply value is missing') && ($context['courier_account_id'] ?? null) === $account->id);
});

it('asks for one more connect once the value is saved, then records the pairing and clears the item', function () {
    $account = cwpAccount($this);
    cwpHandshake($this, $account)->assertStatus(202);

    $this->actingAs($this->owner)->put("/settings/couriers/{$account->id}", [
        'label' => $account->label, 'environment' => 'live', 'base_url' => 'https://developers.carrybee.com', 'integration_secret' => CWP_ECHO,
    ])->assertSessionHasNoErrors();

    expect(collect(cwpItems($this))->pluck('reasonKey')->all())->toBe(['attention.reason.courier_pairing_retry']);

    cwpHandshake($this, $account->fresh())->assertStatus(202)->assertHeader('X-CB-Webhook-Integration-Header', CWP_ECHO);

    $account->refresh();
    expect($account->webhook_last_handshake_ok)->toBeTrue()
        ->and($account->webhook_last_handshake_problem)->toBeNull()
        ->and(cwpItems($this))->toBe([])
        // ⚠ Never a header value in the columns.
        ->and(json_encode(DB::table('courier_accounts')->where('id', $account->id)->first([
            'webhook_last_handshake_at', 'webhook_last_handshake_ok', 'webhook_last_handshake_problem', 'webhook_last_event_at',
        ])))->not->toContain(CWP_ECHO);
});

it('records a pairing call that does not carry our secret, for CarryBee and Pathao', function () {
    $carrybee = cwpAccount($this);
    $pathao = cwpAccount($this, 'pathao', 'Pathao main');

    cwpHandshake($this, $carrybee, 'not-our-secret')->assertStatus(401);
    $this->postJson('/webhooks/couriers/'.$pathao->uuid, ['event' => 'webhook_integration'], ['X-PATHAO-Signature' => 'nope'])->assertStatus(401);

    expect($carrybee->fresh()->webhook_last_handshake_problem)->toBe(CourierAccount::PAIRING_BAD_SIGNATURE)
        ->and($carrybee->fresh()->webhook_last_handshake_ok)->toBeFalse()
        ->and($pathao->fresh()->webhook_last_handshake_problem)->toBe(CourierAccount::PAIRING_BAD_SIGNATURE);

    $items = collect(cwpItems($this));
    expect($items->pluck('key')->sort()->values()->all())->toBe([
        "courier_webhook_pairing:{$carrybee->id}:bad_secret",
        "courier_webhook_pairing:{$pathao->id}:bad_secret",
    ])->and($items->first()->reason)->toContain("the webhook secret in the courier's form does not match the ERP");

    // A refused status update (not a pairing call) does not overwrite the pairing record.
    $carrybee->forceFill(['integration_secret' => CWP_ECHO])->save();
    cwpHandshake($this, $carrybee)->assertStatus(202);
    $this->postJson('/webhooks/couriers/'.$carrybee->uuid, ['event' => 'order.delivered', 'consignment_id' => 'X'], ['X-CarryBee-Webhook-Signature' => 'wrong'])->assertStatus(401);
    expect($carrybee->fresh()->webhook_last_handshake_ok)->toBeTrue();
});

it('records each authentic status update, and a later update makes an old failed attempt history', function () {
    $account = cwpAccount($this);
    cwpHandshake($this, $account)->assertStatus(202);
    expect(cwpItems($this))->toHaveCount(1);

    Carbon::setTestNow('2026-10-11 11:00:00');
    $this->postJson('/webhooks/couriers/'.$account->uuid, ['event' => 'order.delivered', 'consignment_id' => 'NOT-OURS'], ['X-CarryBee-Webhook-Signature' => $account->webhookSecretValue()])
        ->assertStatus(202);

    expect($account->fresh()->webhook_last_event_at?->toDateTimeString())->toBe('2026-10-11 11:00:00')
        ->and(cwpItems($this))->toBe([]);
});

it('warns about an active account that would fail to pair, before anyone tries', function () {
    $account = cwpAccount($this);
    cwpAccount($this, 'carrybee', 'Not switched on', active: false);

    expect(collect(cwpItems($this))->pluck('key')->all())->toBe(["courier_webhook_pairing:{$account->id}:not_ready"]);

    $account->forceFill(['integration_secret' => CWP_ECHO])->save();
    expect(cwpItems($this))->toBe([]);
});

it('shows the webhook status and the reply-value field on Settings > Couriers, never the value', function () {
    $account = cwpAccount($this);

    $props = fn () => $this->actingAs($this->owner)->get('/settings/couriers')->assertOk()->original->getData()['page']['props'];

    $first = $props();
    $carrybee = collect($first['providers'])->firstWhere('code', 'carrybee');
    expect($carrybee['integration_echo_header'])->toBe('X-CB-Webhook-Integration-Header')
        ->and($carrybee['integration_echo_page'])->toBe('CarryBee Developer > Webhook Integration')
        ->and($carrybee['integration_echo_hint_key'])->toBe('couriers.echo_hint.carrybee')
        ->and(collect($first['providers'])->firstWhere('code', 'pathao')['integration_echo_header'])->toBe('X-Pathao-Merchant-Webhook-Integration-Secret')
        ->and(collect($first['providers'])->firstWhere('code', 'steadfast')['integration_echo_header'])->toBeNull()
        ->and($first['accounts'][0]['webhook_status'])->toBe([
            'needs_echo' => true, 'last_handshake_at' => null, 'last_handshake_ok' => null, 'problem' => null, 'last_event_at' => null,
        ]);

    cwpHandshake($this, $account)->assertStatus(202);
    expect($props()['accounts'][0]['webhook_status'])->toMatchArray([
        'needs_echo' => true, 'last_handshake_at' => '11 Oct 2026, 10:00 am', 'last_handshake_ok' => false, 'problem' => 'missing_echo',
    ]);

    $account->forceFill(['integration_secret' => CWP_ECHO])->save();
    cwpHandshake($this, $account->fresh())->assertStatus(202);

    $last = $props();
    expect($last['accounts'][0]['webhook_status'])->toMatchArray(['needs_echo' => false, 'last_handshake_ok' => true, 'problem' => null])
        ->and(json_encode($last))->not->toContain(CWP_ECHO);
});
