<?php

use App\Models\User;
use App\Modules\Foundation\Models\AiCredential;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\AiCredentialResolver;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The security promises made on the AI providers screen, pinned.
 *
 * ⚠⚠ Every test below exists because breaking it would leak an API key. They
 * are not coverage; they are the feature.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Key Co', 'slug' => 'key-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Permission::firstOrCreate(['name' => 'foundation.organization.manage', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo('foundation.organization.manage');
    Role::firstOrCreate(['name' => 'Staff', 'business_id' => $this->business->id, 'guard_name' => 'web']);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');
});

it('stores the key encrypted, never in plain text', function () {
    $this->actingAs($this->owner)
        ->post('/settings/ai-providers', [
            'provider' => 'anthropic',
            'label' => 'Main account',
            'secret' => 'sk-ant-test-key-abcdefghij1234',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $raw = DB::table('ai_credentials')->value('secret');

    expect($raw)->not->toContain('sk-ant-test-key-abcdefghij1234')
        ->and($raw)->not->toBeEmpty();

    // And it still decrypts back to the real thing for the server.
    expect(AiCredential::first()->secret)->toBe('sk-ant-test-key-abcdefghij1234');
});

it('never sends the secret to the browser', function () {
    AiCredential::create([
        'provider' => 'anthropic',
        'secret' => 'sk-ant-secret-value-9999',
        'hint' => '9999',
        'is_active' => true,
    ]);

    $response = $this->actingAs($this->owner)->get('/settings/ai-providers');

    $response->assertOk();

    // The whole rendered payload, props and all, must not contain the key.
    expect($response->getContent())->not->toContain('sk-ant-secret-value-9999');
});

it('keeps only the last four characters readable', function () {
    $this->actingAs($this->owner)->post('/settings/ai-providers', [
        'provider' => 'anthropic',
        'secret' => 'sk-ant-something-long-wxyz',
    ])->assertSessionHasNoErrors();

    expect(AiCredential::first()->hint)->toBe('wxyz');
});

it('supersedes the previous key rather than overwriting it', function () {
    $this->actingAs($this->owner)->post('/settings/ai-providers', [
        'provider' => 'anthropic',
        'secret' => 'sk-ant-first-key-000000000',
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->owner)->post('/settings/ai-providers', [
        'provider' => 'anthropic',
        'secret' => 'sk-ant-second-key-11111111',
    ])->assertSessionHasNoErrors();

    expect(AiCredential::count())->toBe(2)
        ->and(AiCredential::where('is_active', true)->count())->toBe(1)
        ->and(AiCredential::active()->first()->secret)->toBe('sk-ant-second-key-11111111');
});

it('prefers the database key over the environment', function () {
    config()->set('services.anthropic.key', 'sk-ant-from-the-environment');

    expect(app(AiCredentialResolver::class)->keyFor('anthropic'))->toBe('sk-ant-from-the-environment');

    AiCredential::create([
        'provider' => 'anthropic',
        'secret' => 'sk-ant-from-the-database',
        'hint' => 'base',
        'is_active' => true,
    ]);

    expect(app(AiCredentialResolver::class)->keyFor('anthropic'))->toBe('sk-ant-from-the-database');
});

it('falls back to the environment when no row is active', function () {
    config()->set('services.anthropic.key', 'sk-ant-from-the-environment');

    AiCredential::create([
        'provider' => 'anthropic',
        'secret' => 'sk-ant-switched-off',
        'hint' => 'doff',
        'is_active' => false,
    ]);

    expect(app(AiCredentialResolver::class)->keyFor('anthropic'))->toBe('sk-ant-from-the-environment');
});

it('reports not configured when nothing is set anywhere', function () {
    config()->set('services.anthropic.key', null);

    expect(app(AiCredentialResolver::class)->isConfigured('anthropic'))->toBeFalse()
        ->and(app(AiCredentialResolver::class)->keyFor('anthropic'))->toBeNull();
});

it('refuses a user without organization permission', function () {

    $this->actingAs($this->staff)->get('/settings/ai-providers')->assertForbidden();

    $this->actingAs($this->staff)->post('/settings/ai-providers', [
        'provider' => 'anthropic',
        'secret' => 'sk-ant-should-never-be-saved',
    ])->assertForbidden();

    expect(AiCredential::count())->toBe(0);
});

it('rejects an obviously wrong key', function () {
    $this->actingAs($this->owner)
        ->post('/settings/ai-providers', ['provider' => 'anthropic', 'secret' => 'short'])
        ->assertSessionHasErrors('secret');

    $this->actingAs($this->owner)
        ->post('/settings/ai-providers', ['provider' => 'not-a-provider', 'secret' => 'sk-ant-long-enough-key-here'])
        ->assertSessionHasErrors('provider');
});
