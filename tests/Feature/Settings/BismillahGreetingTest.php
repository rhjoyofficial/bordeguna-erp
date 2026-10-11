<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Support\Preferences\BismillahGreeting;
use Spatie\Permission\PermissionRegistrar;

/**
 * The Bismillah greeting (owner request, 11 Oct 2026): ON for everybody by
 * default, each person's own switch saved on their own row, and a sign-in
 * nonce so the first page after login greets. Asserted on the SHARED PROP,
 * which is what the screen is actually handed.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Greeting Co', 'slug' => 'greeting-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);
});

function greetingPage($test)
{
    return $test->actingAs($test->user)
        ->withHeader('X-Business-Id', (string) $test->business->id)
        ->get('/settings/appearance');
}

it('is on by default and shared with every page', function () {
    greetingPage($this)->assertInertia(fn ($page) => $page
        ->where('bismillah.enabled', true)
        ->where('bismillah.login', null));
});

it('saves the person\'s own choice and shares it', function () {
    $this->actingAs($this->user)
        ->patch('/settings/greeting', ['enabled' => false])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($this->user->fresh()->preferences[BismillahGreeting::KEY])->toBe(['enabled' => false]);
    greetingPage($this)->assertInertia(fn ($page) => $page->where('bismillah.enabled', false));

    $this->actingAs($this->user)->patch('/settings/greeting', ['enabled' => true])->assertSessionHasNoErrors();

    greetingPage($this)->assertInertia(fn ($page) => $page->where('bismillah.enabled', true));
});

it('keeps the other personal settings when it saves', function () {
    $this->user->forceFill(['preferences' => ['dashboard_sound' => ['enabled' => true]]])->save();

    $this->actingAs($this->user)->patch('/settings/greeting', ['enabled' => false])->assertSessionHasNoErrors();

    expect($this->user->fresh()->preferences)->toBe([
        'dashboard_sound' => ['enabled' => true],
        BismillahGreeting::KEY => ['enabled' => false],
    ]);
});

it('refuses a value that is not a yes or no', function () {
    $this->actingAs($this->user)
        ->patch('/settings/greeting', ['enabled' => 'sometimes'])
        ->assertSessionHasErrors('enabled');

    $this->actingAs($this->user)
        ->patch('/settings/greeting', [])
        ->assertSessionHasErrors('enabled');

    expect(BismillahGreeting::enabled($this->user->fresh()))->toBeTrue();
});

it('is not reachable by a guest', function () {
    $this->patch('/settings/greeting', ['enabled' => false])->assertRedirect('/login');
});

it('hands a sign-in nonce to the first page after login, and a new one on the next login', function () {
    $this->post('/login', ['email' => $this->user->email, 'password' => 'password'])->assertRedirect();

    $first = null;
    $this->withHeader('X-Business-Id', (string) $this->business->id)
        ->get('/settings/appearance')
        ->assertInertia(function ($page) use (&$first) {
            $page->where('bismillah.login', fn ($nonce) => is_string($nonce) && strlen($nonce) === 16);
            $first = $page->toArray()['props']['bismillah']['login'];
        });

    $this->post('/logout');
    $this->post('/login', ['email' => $this->user->email, 'password' => 'password']);

    $this->withHeader('X-Business-Id', (string) $this->business->id)
        ->get('/settings/appearance')
        ->assertInertia(fn ($page) => $page->where('bismillah.login', fn ($nonce) => is_string($nonce) && $nonce !== $first));
});

it('is never shared with a guest page', function () {
    $this->get('/login')->assertInertia(fn ($page) => $page->where('bismillah', null));
});
