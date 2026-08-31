<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Spatie\Permission\PermissionRegistrar;

/**
 * Bangla UI phase 1 (C-I18N-1/5): each user chooses their own language, saved
 * on their own row so it follows them to any device. English is the default
 * and the fallback.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Locale Co', 'slug' => 'locale-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);
});

it('defaults every user to English', function () {
    expect($this->user->fresh()->locale)->toBe('en');
});

it('saves the choice on the user row and shares it with every page', function () {
    $this->actingAs($this->user)
        ->withHeader('X-Business-Id', (string) $this->business->id)
        ->patch('/settings/locale', ['locale' => 'bn'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($this->user->fresh()->locale)->toBe('bn');

    // C-I18N-3: the shared prop every screen translates from.
    $this->actingAs($this->user)
        ->withHeader('X-Business-Id', (string) $this->business->id)
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('auth.user.locale', 'bn'));
});

it('refuses a language the dictionary does not carry', function () {
    $this->actingAs($this->user)
        ->withHeader('X-Business-Id', (string) $this->business->id)
        ->patch('/settings/locale', ['locale' => 'fr'])
        ->assertSessionHasErrors('locale');

    expect($this->user->fresh()->locale)->toBe('en');
});
