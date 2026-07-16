<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;

it('only lists businesses the user belongs to', function () {
    $laladia = Business::create(['name' => 'LaLaDia', 'slug' => 'laladia']);
    Business::create(['name' => 'Bionic', 'slug' => 'bionic']);

    $user = User::factory()->create();
    $user->businesses()->attach($laladia->id);

    $this->actingAs($user)
        ->get('/businesses/switch')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('business-switcher')
            ->has('businesses', 1)
            ->where('businesses.0.slug', 'laladia')
        );
});

it('forbids switching into a business the user does not belong to', function () {
    $laladia = Business::create(['name' => 'LaLaDia', 'slug' => 'laladia']);
    $bionic = Business::create(['name' => 'Bionic', 'slug' => 'bionic']);

    $user = User::factory()->create();
    $user->businesses()->attach($laladia->id);

    $this->actingAs($user)
        ->post('/businesses/switch', ['business_id' => $bionic->id])
        ->assertForbidden();

    expect(session('current_business_id'))->not->toBe($bionic->id);
});

it('allows a super admin to switch into any business', function () {
    $bionic = Business::create(['name' => 'Bionic', 'slug' => 'bionic']);
    $admin = User::factory()->create(['is_super_admin' => true]);

    $this->actingAs($admin)
        ->post('/businesses/switch', ['business_id' => $bionic->id])
        ->assertRedirect(route('dashboard'));

    expect(session('current_business_id'))->toBe($bionic->id);
});

it('auto-selects the user\'s only business when none is set in session yet', function () {
    $laladia = Business::create(['name' => 'LaLaDia', 'slug' => 'laladia']);

    $user = User::factory()->create();
    $user->businesses()->attach($laladia->id);

    $this->actingAs($user)->get('/dashboard')->assertOk();

    expect(session('current_business_id'))->toBe($laladia->id);
});
