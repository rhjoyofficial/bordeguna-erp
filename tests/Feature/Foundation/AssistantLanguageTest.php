<?php

use App\Models\User;
use App\Modules\Foundation\Models\AssistantMessage;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\Ai\AssistantLanguages;
use App\Modules\Foundation\Support\BusinessContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The chat language toggle, and the monthly ceiling (Joy, 30 August 2026).
 *
 * ⚠⚠ EXACTLY TWO LANGUAGES, ENFORCED IN ONE PLACE. A toggle is a two-position
 * control: one makes it pointless, three makes it a different control that has
 * to be designed. Settings, the chat panel and the system prompt all read the
 * same rule rather than each holding an opinion about it.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Lang Co', 'slug' => 'lang-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Permission::firstOrCreate(['name' => 'foundation.organization.manage', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo('foundation.organization.manage');

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->asOwner = fn () => $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id]);
});

it('starts on Bangla and English, because that is what this business writes', function () {
    expect(AssistantLanguages::activeFor($this->business->fresh()))->toBe(['en', 'bn']);
});

it('saves exactly two and refuses any other number', function () {
    ($this->asOwner)()->patch(route('ai-providers.preferences'), ['languages' => ['bn', 'hi']])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(AssistantLanguages::activeFor($this->business->fresh()))->toBe(['bn', 'hi']);

    // One is not a toggle.
    ($this->asOwner)()->patch(route('ai-providers.preferences'), ['languages' => ['bn']])
        ->assertSessionHasErrors('languages');

    // Nor is three.
    ($this->asOwner)()->patch(route('ai-providers.preferences'), ['languages' => ['bn', 'en', 'hi']])
        ->assertSessionHasErrors('languages');
});

it('refuses the same language twice', function () {
    // ⚠ Two values that are one choice. The toggle would render two identical
    // buttons and neither would do anything.
    ($this->asOwner)()->patch(route('ai-providers.preferences'), ['languages' => ['bn', 'bn']])
        ->assertSessionHasErrors('languages');
});

it('refuses a language it does not support', function () {
    ($this->asOwner)()->patch(route('ai-providers.preferences'), ['languages' => ['bn', 'klingon']])
        ->assertSessionHasErrors('languages.1');
});

it('answers in a language only if that language is switched on', function () {
    $this->business->update(['assistant_languages' => ['en', 'bn']]);

    expect(AssistantLanguages::instructionFor('bn', $this->business->fresh()))->toContain('Bangla');

    // ⚠⚠ The chat box offers two, but the request is only JSON and could name
    // anything. A language that is not active falls back to the first active
    // one rather than being honoured.
    $fallback = AssistantLanguages::instructionFor('zh', $this->business->fresh());

    expect($fallback)->toContain('English')
        ->and($fallback)->not->toContain('Chinese');
});

it('falls back to the defaults when the stored pair is unusable', function () {
    // ⚠ A code removed from the catalogue in a later release must not reach the
    // chat box as a toggle nobody can serve.
    $this->business->update(['assistant_languages' => ['bn', 'this-was-removed']]);

    expect(AssistantLanguages::activeFor($this->business->fresh()))->toBe(['en', 'bn']);
});

it('always hands the toggle exactly two options with a native label', function () {
    $toggle = AssistantLanguages::toggleFor($this->business->fresh());

    expect($toggle)->toHaveCount(2)
        ->and($toggle[0])->toHaveKeys(['code', 'label', 'native']);
});

// ─── The ceiling ────────────────────────────────────────────────────────────

it('counts a question the moment it is asked, not when it succeeds', function () {
    // ⚠⚠ A turn that fails halfway has still been paid for. A counter that only
    // increments on success is a cap a run of errors walks straight through.
    AssistantMessage::create([
        'business_id' => $this->business->id,
        'user_id' => $this->owner->id,
        'question' => 'anything',
        'answered' => false,
    ]);

    expect(AssistantMessage::usedThisMonth($this->business->id))->toBe(1);
});

it('saves a monthly ceiling, and empty means no ceiling', function () {
    ($this->asOwner)()->patch(route('ai-providers.preferences'), ['languages' => ['en', 'bn'], 'monthly_cap' => 500]);
    expect($this->business->fresh()->ai_monthly_message_cap)->toBe(500);

    ($this->asOwner)()->patch(route('ai-providers.preferences'), ['languages' => ['en', 'bn'], 'monthly_cap' => null]);
    expect($this->business->fresh()->ai_monthly_message_cap)->toBeNull();
});

it('does not count last month against this month', function () {
    // ⚠ created_at is not fillable, so mass assignment silently ignores it and
    // the row lands with today's date - which would make this test pass for the
    // wrong reason. Back-dated after creation instead.
    $old = AssistantMessage::create([
        'business_id' => $this->business->id, 'user_id' => $this->owner->id, 'question' => 'old',
    ]);
    $old->forceFill(['created_at' => now()->subMonth()])->saveQuietly();

    expect(AssistantMessage::usedThisMonth($this->business->id))->toBe(0);
});

it('lets only an organisation admin change the preferences', function () {
    $plain = User::factory()->create();
    $plain->businesses()->attach($this->business->id);

    $this->actingAs($plain)->withSession(['current_business_id' => $this->business->id])
        ->patch(route('ai-providers.preferences'), ['languages' => ['bn', 'hi']])
        ->assertForbidden();
});
