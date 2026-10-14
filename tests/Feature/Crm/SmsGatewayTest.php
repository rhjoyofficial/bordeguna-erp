<?php

use App\Models\User;
use App\Modules\Crm\Messaging\EndpointGuard;
use App\Modules\Crm\Messaging\HttpSmsDriver;
use App\Modules\Crm\Messaging\ProviderPresets;
use App\Modules\Crm\Models\MessageProvider;
use App\Modules\Crm\Models\MessagingSetting;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The configurable SMS gateway (Joy, 30 August 2026).
 *
 * ⚠⚠ THE TEST THAT MATTERS MOST IS "a 200 that means no". Every Bangladeshi
 * gateway answers HTTP 200 whether it accepted the message or refused it, and
 * puts the truth in the body. A driver that trusts the status line reports a
 * flawless campaign that never left the building, and the first anybody knows is
 * that no customer replied. Half of what follows is about that one lie.
 *
 * ⚠ The endpoints below are IP literals rather than hostnames on purpose:
 * EndpointGuard resolves a hostname before judging it, and a test must not
 * depend on DNS being reachable.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Gateway Co', 'slug' => 'gateway-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Permission::firstOrCreate(['name' => 'crm.settings.manage', 'guard_name' => 'web']);

    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo('crm.settings.manage');

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->provider = MessageProvider::create([
        'business_id' => $this->business->id,
        'channel' => 'sms',
        'driver' => 'http_sms',
        'name' => 'A gateway',
        'sender_id' => 'BORDEGUNA',
        'cost_per_part' => 0.35,
        'credentials' => ['api_key' => 'super-secret-key'],
        'config' => [
            'endpoint' => 'https://8.8.8.8/api/smsapi',
            'method' => 'post_form',
            'auth' => 'params',
            'credential_map' => ['api_key' => 'api_key'],
            'recipient_field' => 'number',
            'message_field' => 'message',
            'sender_field' => 'senderid',
            'number_format' => 'msisdn88',
            'extra_params' => ['type' => 'text'],
            'unicode_params' => ['type' => 'unicode'],
            'success' => ['mode' => 'json_in', 'path' => 'response_code', 'values' => ['202']],
            'error_path' => 'error_message',
            'message_id_path' => null,
        ],
    ]);
});

// ─── Telling acceptance from refusal ─────────────────────────────────────────

it('accepts only the code the gateway actually uses for acceptance', function () {
    Http::fake(['*' => Http::response(['response_code' => 202, 'success_message' => 'SMS Submitted'], 200)]);

    $result = app(HttpSmsDriver::class)->send($this->provider, '01711000000', 'Hello');

    expect($result->accepted)->toBeTrue();
});

it('refuses a 200 that says the account is out of balance', function () {
    // ⚠⚠ THE WHOLE POINT. HTTP 200, a perfectly successful request, and not one
    // message sent. Trusting the status line here bills a campaign as delivered.
    Http::fake(['*' => Http::response(['response_code' => 1007, 'error_message' => 'Balance Insufficient'], 200)]);

    $result = app(HttpSmsDriver::class)->send($this->provider, '01711000000', 'Hello');

    expect($result->accepted)->toBeFalse()
        ->and($result->error)->toBe('Balance Insufficient');
});

it('refuses anything that is not a 2xx whatever the body says', function () {
    Http::fake(['*' => Http::response(['response_code' => 202], 500)]);

    expect(app(HttpSmsDriver::class)->send($this->provider, '01711000000', 'Hello')->accepted)->toBeFalse();
});

it('reports the raw reply when the gateway names no error field', function () {
    $provider = $this->provider;
    $config = $provider->config;
    $config['error_path'] = null;
    $provider->update(['config' => $config]);

    Http::fake(['*' => Http::response('SENDER ID NOT APPROVED', 200)]);

    $result = app(HttpSmsDriver::class)->send($provider, '01711000000', 'Hello');

    expect($result->accepted)->toBeFalse()
        ->and($result->error)->toContain('SENDER ID NOT APPROVED');
});

// ─── The wire format ─────────────────────────────────────────────────────────

it('sends the number in the shape the gateway asked for', function () {
    Http::fake(['*' => Http::response(['response_code' => 202], 200)]);

    app(HttpSmsDriver::class)->send($this->provider, '01711000000', 'Hello');

    Http::assertSent(fn ($request) => $request['number'] === '8801711000000');

    // The same contact, written three other ways, must reach the gateway the same.
    foreach (['+8801711000000', '8801711000000', '01711000000'] as $written) {
        app(HttpSmsDriver::class)->send($this->provider, $written, 'Hello');
    }

    Http::assertSentCount(4);
});

it('switches the gateway into unicode mode when the message turns Bangla', function () {
    // ⚠⚠ Several gateways silently mangle Bangla without this flag, and one
    // Bangla character converts the whole message - the same test that triples
    // the price also changes the wire format.
    Http::fake(['*' => Http::response(['response_code' => 202], 200)]);

    app(HttpSmsDriver::class)->send($this->provider, '01711000000', 'ঈদ মোবারক');

    Http::assertSent(fn ($request) => $request['type'] === 'unicode');
});

it('leaves an English message in text mode', function () {
    Http::fake(['*' => Http::response(['response_code' => 202], 200)]);

    app(HttpSmsDriver::class)->send($this->provider, '01711000000', 'Eid Mubarak');

    Http::assertSent(fn ($request) => $request['type'] === 'text');
});

it('sends the masking sender id under whatever name the gateway uses', function () {
    Http::fake(['*' => Http::response(['response_code' => 202], 200)]);

    app(HttpSmsDriver::class)->send($this->provider, '01711000000', 'Hello');

    Http::assertSent(fn ($request) => $request['senderid'] === 'BORDEGUNA');
});

// ─── Not leaking the key ─────────────────────────────────────────────────────

it('never hands the api key back to the screen', function () {
    // ⚠⚠ The Test button prints the request back so a wrong field name can be
    // spotted. That is exactly the shape that leaks a key into a screenshot.
    Http::fake(['*' => Http::response(['response_code' => 202], 200)]);

    $attempt = app(HttpSmsDriver::class)->attempt($this->provider, '01711000000', 'Hello');

    expect($attempt['sent']['api_key'])->not->toBe('super-secret-key')
        ->and(json_encode($attempt['sent']))->not->toContain('super-secret-key');
});

// ─── Where an endpoint may point ─────────────────────────────────────────────

it('refuses to call anything inside the server or its own network', function () {
    // ⚠⚠ Without this the settings screen is a request forger: the reply body is
    // printed straight back to whoever pressed Test.
    expect(EndpointGuard::reject('http://127.0.0.1/send'))->not->toBeNull()
        ->and(EndpointGuard::reject('http://10.0.0.5/send'))->not->toBeNull()
        ->and(EndpointGuard::reject('http://192.168.1.1/send'))->not->toBeNull()
        ->and(EndpointGuard::reject('file:///etc/passwd'))->not->toBeNull()
        ->and(EndpointGuard::reject('https://8.8.8.8/api/smsapi'))->toBeNull();
});

it('will not save a provider pointing at the machine it runs on', function () {
    $this->actingAs($this->owner)
        ->post(route('crm.settings.messaging.store'), [
            'name' => 'Sneaky',
            'channel' => 'sms',
            'driver' => 'http_sms',
            'priority' => 100,
            'cost_per_part' => 0,
            'config' => ['endpoint' => 'http://127.0.0.1:3306/', 'method' => 'get'],
        ])
        // CRM-015 (audit 14 Oct 2026): a message under the field, keeping the
        // form, not a bare 422 page.
        ->assertRedirect()
        ->assertSessionHasErrors('config.endpoint');

    expect(MessageProvider::where('name', 'Sneaky')->exists())->toBeFalse();
});

// ─── The screen that configures it ───────────────────────────────────────────

it('offers the Bangladeshi gateways already wired up', function () {
    $keys = array_column(ProviderPresets::all(), 'key');

    expect($keys)->toContain('bulksmsbd')->toContain('alpha_sms')->toContain('custom');

    // ⚠ Every preset must describe a complete request. A half-filled one is
    // worse than none: it looks configured and sends nothing.
    foreach (ProviderPresets::all() as $preset) {
        if ($preset['key'] === 'custom') {
            continue;
        }

        expect($preset['config']['endpoint'])->not->toBe('')
            ->and($preset['config']['recipient_field'])->not->toBe('')
            ->and($preset['config']['message_field'])->not->toBe('')
            ->and($preset['credential_fields'])->not->toBeEmpty();
    }
});

it('shows the presets and the threshold on the settings screen', function () {
    $this->actingAs($this->owner)->get(route('crm.settings.messaging'))
        ->assertInertia(fn ($page) => $page
            ->has('presets')
            ->where('approvalAbove', 50)
        );
});

it('hands the test result back to the screen as a prop', function () {
    Http::fake(['*' => Http::response(['response_code' => 1007, 'error_message' => 'Balance Insufficient'], 200)]);

    $this->actingAs($this->owner)
        ->post(route('crm.settings.messaging.test', $this->provider->id), ['to' => '01711000000', 'body' => 'test']);

    // ⚠ Through a real page load, not assertSessionHas - see
    // FlashedPreviewsReachTheScreenTest for why that distinction is not academic.
    $this->actingAs($this->owner)->get(route('crm.settings.messaging'))
        ->assertInertia(fn ($page) => $page
            ->where('test_result.accepted', false)
            ->where('test_result.error', 'Balance Insufficient')
        );
});

// ─── The threshold Joy asked to be able to move ──────────────────────────────

it('defaults to fifty when nobody has set a threshold', function () {
    expect(MessagingSetting::approvalAbove())->toBe(50);
});

it('uses the number that was saved', function () {
    $this->actingAs($this->owner)->put(route('crm.settings.messaging.settings'), ['approval_above' => 200]);

    expect(MessagingSetting::approvalAbove())->toBe(200);
});

it('treats a cleared threshold as everything needing approval, not nothing', function () {
    // ⚠⚠ A blank that read as "no limit" would switch off the spending control
    // by deleting a number.
    $this->actingAs($this->owner)->put(route('crm.settings.messaging.settings'), ['approval_above' => null]);

    expect(MessagingSetting::approvalAbove())->toBe(0);
});
