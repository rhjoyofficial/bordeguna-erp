<?php

use App\Models\User;
use App\Modules\Foundation\Models\AiCredential;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\AiCredentialResolver;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Support\Facades\DB;

/**
 * What happens to a stored API key when the database is restored onto a server
 * with a different APP_KEY.
 *
 * ⚠⚠ This is the documented disaster-recovery path, not a hypothetical. The
 * encrypted column is readable only with the key that wrote it, so after a
 * restore the row is there and its contents are gone. Found by running a copy
 * of production locally, where exactly that mismatch exists.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Key Co', 'slug' => 'key-co']);
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
});

it('treats a key it cannot decrypt as no key at all', function () {
    $credential = AiCredential::create([
        'provider' => 'anthropic',
        'label' => 'Restored from a backup',
        'secret' => 'sk-ant-something-real-enough',
        'is_active' => true,
    ]);

    // What a restore onto a server with a different APP_KEY leaves behind:
    // ciphertext this installation has no way to read.
    DB::table('ai_credentials')->where('id', $credential->id)
        ->update(['secret' => 'eyJpdiI6InRoaXMtaXMtbm90LW91cnMiLCJ2YWx1ZSI6ImdhcmJhZ2UifQ==']);

    config(['services.anthropic.key' => '']);

    $resolver = app(AiCredentialResolver::class);

    expect($resolver->keyFor('anthropic'))->toBeNull()
        ->and($resolver->isConfigured('anthropic'))->toBeFalse();
});

it('still falls back to the environment when the stored key is unreadable', function () {
    $credential = AiCredential::create([
        'provider' => 'anthropic',
        'secret' => 'sk-ant-written-here',
        'is_active' => true,
    ]);

    DB::table('ai_credentials')->where('id', $credential->id)
        ->update(['secret' => 'eyJpdiI6Im5vdC1vdXJzIiwidmFsdWUiOiJnYXJiYWdlIn0=']);

    config(['services.anthropic.key' => 'sk-ant-from-the-environment']);

    expect(app(AiCredentialResolver::class)->keyFor('anthropic'))->toBe('sk-ant-from-the-environment');
});

it('keeps the settings screen open so the dead key can be replaced', function () {
    // ⚠ The point of the guard. Before it, the one screen that could fix the
    // problem was the screen the problem took down.
    $owner = User::factory()->create(['is_super_admin' => true]);
    $owner->businesses()->attach($this->business->id);

    $credential = AiCredential::create([
        'provider' => 'anthropic',
        'secret' => 'sk-ant-written-here',
        'is_active' => true,
    ]);

    DB::table('ai_credentials')->where('id', $credential->id)
        ->update(['secret' => 'eyJpdiI6Im5vdC1vdXJzIiwidmFsdWUiOiJnYXJiYWdlIn0=']);

    $this->actingAs($owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->get('/settings/ai-providers')
        ->assertOk();
});
