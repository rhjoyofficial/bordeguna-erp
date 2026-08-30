<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Who can see and use the AI assistant (Joy, 30 August 2026).
 *
 * ⚠⚠ TWO CONDITIONS, BOTH REQUIRED: the business has the assistant switched on
 * by an admin, AND the person holds `foundation.ai.use`. Off means nobody at
 * all - including an Owner holding every other permission.
 *
 * ⚠⚠ AND THE ENDPOINT IS THE REAL BOUNDARY, NOT THE HIDDEN BUTTON. Until today
 * this endpoint checked only that somebody was logged in: any user could POST
 * to it and spend money on the API key. A hidden button is still a URL, so
 * every test below that covers the prop has a partner that covers the POST.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Assistant Co', 'slug' => 'assistant-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Permission::firstOrCreate(['name' => 'foundation.ai.use', 'guard_name' => 'web']);

    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo('foundation.ai.use');
    Role::firstOrCreate(['name' => 'Staff', 'business_id' => $this->business->id, 'guard_name' => 'web']);

    $this->permitted = User::factory()->create();
    $this->permitted->businesses()->attach($this->business->id);
    $this->permitted->assignRole('Owner');

    $this->plain = User::factory()->create();
    $this->plain->businesses()->attach($this->business->id);
    $this->plain->assignRole('Staff');

    $this->enable = fn (bool $on) => $this->business->update(['ai_assistant_enabled' => $on]);

    $this->ask = fn (User $user) => $this->actingAs($user)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('assistant.chat'), [
            'messages' => [['role' => 'user', 'content' => 'How do I raise a credit note?']],
        ]);

    $this->page = fn (User $user) => $this->actingAs($user)
        ->withSession(['current_business_id' => $this->business->id])
        ->get(route('dashboard'));
});

it('starts switched off, so nobody sees it until somebody decides', function () {
    // ⚠ The migration defaults to false on purpose. The button used to render
    // for every user on every page, with no permission check behind it - and
    // the safe direction for a feature that bills per message is off.
    expect($this->business->fresh()->ai_assistant_enabled)->toBeFalse();

    ($this->page)($this->permitted)
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canUseAssistant', false));
});

it('shows it only to somebody with the permission once an admin switches it on', function () {
    ($this->enable)(true);

    ($this->page)($this->permitted)
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canUseAssistant', true));

    ($this->page)($this->plain)
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canUseAssistant', false));
});

it('hides it from everybody again when the admin switches it off', function () {
    ($this->enable)(true);
    ($this->enable)(false);

    // ⚠ Even from the person holding the permission. The switch is a master
    // switch, not a default that a permission can override.
    ($this->page)($this->permitted)
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canUseAssistant', false));
});

it('refuses the endpoint to somebody without the permission, switch on or not', function () {
    ($this->enable)(true);

    // ⚠⚠ THE ONE THAT MATTERS. Hiding the button is presentation; this is the
    // control. Without it, anybody who could log in could spend the API budget
    // by posting to a URL they read in the JavaScript bundle.
    ($this->ask)($this->plain)->assertForbidden();
});

it('refuses the endpoint to everybody while the assistant is switched off', function () {
    ($this->enable)(false);

    ($this->ask)($this->permitted)->assertForbidden();
});

it('lets a permitted person through once it is on', function () {
    ($this->enable)(true);

    // ⚠ Not asserting a reply: no provider key is configured in tests, so the
    // controller answers 503 "not configured yet". What is under test is that
    // the request got PAST the gate - a 403 here would mean it did not.
    ($this->ask)($this->permitted)->assertStatus(503);
});

it('only lets an organisation admin move the switch', function () {
    $this->actingAs($this->plain)->withSession(['current_business_id' => $this->business->id])
        ->patch(route('ai-providers.assistant'), ['enabled' => true])
        ->assertForbidden();

    expect($this->business->fresh()->ai_assistant_enabled)->toBeFalse();
});
