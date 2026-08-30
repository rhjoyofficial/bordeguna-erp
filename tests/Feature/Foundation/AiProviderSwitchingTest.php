<?php

use App\Models\User;
use App\Modules\Foundation\Models\AiCredential;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\Ai\AiProviders;
use App\Modules\Foundation\Support\Ai\AnthropicDriver;
use App\Modules\Foundation\Support\Ai\AssistantUnavailable;
use App\Modules\Foundation\Support\BusinessContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Which provider answers, and what happens when the model is wrong.
 *
 * ⚠⚠ THE MODEL TEST IS THE POINT OF THIS FILE. A model id of
 * `claude-haiku-4.5` (a dot where a hyphen belongs) was accepted by the
 * settings screen on 23 August and returned 404 on every single message until
 * 27 August, while the panel said only "temporarily unavailable" - so nobody
 * could tell a dead assistant from a busy one. These tests pin the two fixes:
 * a wrong model is NAMED, and it can be corrected without touching the key.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Driver Co', 'slug' => 'driver-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    // ⚠ From 30 Aug 2026 the assistant needs BOTH an admin switch and the
    // `foundation.ai.use` permission. Both are turned on here so these tests
    // keep testing what they are about - the assistant's own behaviour. Who is
    // allowed to reach it at all is AssistantAccessTest's job.
    $this->business->update(['ai_assistant_enabled' => true]);
    Permission::firstOrCreate(['name' => 'foundation.ai.use', 'guard_name' => 'web']);

    Permission::firstOrCreate(['name' => 'foundation.organization.manage', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo('foundation.organization.manage');

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');
});

it('names the model when the provider rejects it, rather than blaming the weather', function () {
    // The translation a 404 gets. Anything vaguer sends an Owner looking at
    // the key, the network and the server before the one field that is wrong.
    $message = AssistantUnavailable::badModel('claude-haiku-4.5')->getMessage();

    expect($message)->toContain('claude-haiku-4.5')
        ->and($message)->toContain('Settings');
});

it('distinguishes a bad key from a bad model from a busy provider', function () {
    expect(AssistantUnavailable::badKey()->getMessage())->toContain('key')
        ->and(AssistantUnavailable::rateLimited()->getMessage())->toContain('busy')
        ->and(AssistantUnavailable::unreachable()->getMessage())->toContain('unavailable');
});

it('answers through no provider until a key exists', function () {
    expect(app(AiProviders::class)->activeProvider())->toBeNull()
        ->and(app(AiProviders::class)->isConfigured())->toBeFalse();
});

it('switches itself on when a key is saved, with no deploy and no config change', function () {
    AiCredential::create([
        'provider' => 'anthropic',
        'secret' => 'sk-ant-not-a-real-key-000000',
        'hint' => '0000',
        'is_active' => true,
    ]);

    expect(app(AiProviders::class)->activeProvider())->toBe('anthropic');
});

it('prefers anthropic when both providers hold a key, and says so out loud', function () {
    foreach (['openai', 'anthropic'] as $provider) {
        AiCredential::create([
            'provider' => $provider,
            'secret' => 'sk-not-a-real-key-0000000000',
            'hint' => '0000',
            'is_active' => true,
        ]);
    }

    // ⚠ The order is the preference, not the insertion order: openai was
    // saved first and still does not win.
    expect(app(AiProviders::class)->activeProvider())->toBe('anthropic');
});

it('falls through to openai when the anthropic key is switched off', function () {
    $anthropic = AiCredential::create([
        'provider' => 'anthropic',
        'secret' => 'sk-ant-not-a-real-key-000000',
        'hint' => '0000',
        'is_active' => true,
    ]);

    AiCredential::create([
        'provider' => 'openai',
        'secret' => 'sk-not-a-real-key-0000000000',
        'hint' => '1111',
        'is_active' => true,
    ]);

    $anthropic->update(['is_active' => false]);

    expect(app(AiProviders::class)->activeProvider())->toBe('openai');
});

it('offers a provider that has no driver, but never lets it answer', function () {
    $providers = app(AiProviders::class);

    expect($providers->all())->toHaveKey('gemini')
        ->and($providers->isSupported('gemini'))->toBeFalse()
        ->and($providers->driverFor('gemini'))->toBeNull();

    AiCredential::create([
        'provider' => 'gemini',
        'secret' => 'not-a-real-key-00000000000',
        'hint' => '0000',
        'is_active' => true,
    ]);

    // A key for a provider that cannot send a message must not make the
    // assistant claim it is configured.
    expect($providers->activeProvider())->toBeNull();
});

it('changes the model without the key being retyped', function () {
    $credential = AiCredential::create([
        'provider' => 'anthropic',
        'model' => 'claude-haiku-4.5',
        'secret' => 'sk-ant-not-a-real-key-000000',
        'hint' => '0000',
        'is_active' => true,
        'verified_at' => now(),
    ]);

    $this->actingAs($this->owner)
        ->patch("/settings/ai-providers/{$credential->id}/model", ['model' => 'claude-haiku-4-5'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $credential->refresh();

    expect($credential->model)->toBe('claude-haiku-4-5')
        // The old verification described a different model, so it is no
        // longer evidence and must not keep reassuring anyone.
        ->and($credential->verified_at)->toBeNull();
});

it('refuses a model change from anyone without the organisation permission', function () {
    $credential = AiCredential::create([
        'provider' => 'anthropic',
        'model' => 'claude-haiku-4-5',
        'secret' => 'sk-ant-not-a-real-key-000000',
        'hint' => '0000',
        'is_active' => true,
    ]);

    $outsider = User::factory()->create();
    $outsider->businesses()->attach($this->business->id);

    $this->actingAs($outsider)
        ->patch("/settings/ai-providers/{$credential->id}/model", ['model' => 'claude-opus-5'])
        ->assertForbidden();

    expect($credential->refresh()->model)->toBe('claude-haiku-4-5');
});

it('will not test a key that is switched off', function () {
    $credential = AiCredential::create([
        'provider' => 'anthropic',
        'secret' => 'sk-ant-not-a-real-key-000000',
        'hint' => '0000',
        'is_active' => false,
    ]);

    $this->actingAs($this->owner)
        ->post("/settings/ai-providers/{$credential->id}/verify")
        ->assertRedirect()
        ->assertSessionHas('error');
});

it('will not test a provider that has no driver', function () {
    $credential = AiCredential::create([
        'provider' => 'openrouter',
        'secret' => 'sk-not-a-real-key-0000000000',
        'hint' => '0000',
        'is_active' => true,
    ]);

    $this->actingAs($this->owner)
        ->post("/settings/ai-providers/{$credential->id}/verify")
        ->assertRedirect()
        ->assertSessionHas('error');
});

it('falls back to the driver default when no model is stored', function () {
    expect(app(AnthropicDriver::class)->defaultModel())->toBe('claude-haiku-4-5');
});
