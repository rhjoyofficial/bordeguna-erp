<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;

/**
 * ⚠⚠ Switching business was completely broken in the UI until 2026-07-29.
 *
 * The page POSTed an empty body because `useForm().post(url, {data: ...})`
 * sends the form's own (empty) data and treats the second argument as visit
 * options. An `as never` cast suppressed the TypeScript error that was
 * correctly refusing it, so `tsc` stayed clean while the feature did nothing.
 *
 * These pin the server contract the page must satisfy. ⚠ They cannot catch the
 * front-end mistake itself — only a browser could, and did.
 */
it('switches the active business when given a business_id', function () {
    $a = Business::create(['name' => 'Alpha Co', 'slug' => 'alpha-co']);
    $b = Business::create(['name' => 'Beta Co', 'slug' => 'beta-co']);

    $user = User::factory()->create(['is_super_admin' => true]);
    $user->businesses()->attach([$a->id, $b->id]);

    $this->actingAs($user)
        ->withSession(['current_business_id' => $a->id])
        ->post(route('businesses.switch.store'), ['business_id' => $b->id])
        ->assertRedirect();

    expect(session('current_business_id'))->toBe($b->id);
});

it('rejects a switch with no business_id, which is exactly what the broken page sent', function () {
    $a = Business::create(['name' => 'Alpha Co', 'slug' => 'alpha-co']);
    $user = User::factory()->create(['is_super_admin' => true]);
    $user->businesses()->attach($a->id);

    $this->actingAs($user)
        ->withSession(['current_business_id' => $a->id])
        ->post(route('businesses.switch.store'), [])
        ->assertSessionHasErrors('business_id');

    // The tell-tale symptom: the user stays where they were and nothing says why.
    expect(session('current_business_id'))->toBe($a->id);
});

it('refuses a business the user does not belong to', function () {
    $mine = Business::create(['name' => 'Mine Co', 'slug' => 'mine-co']);
    $theirs = Business::create(['name' => 'Theirs Co', 'slug' => 'theirs-co']);

    $user = User::factory()->create(['is_super_admin' => false]);
    $user->businesses()->attach($mine->id);

    $this->actingAs($user)
        ->withSession(['current_business_id' => $mine->id])
        ->post(route('businesses.switch.store'), ['business_id' => $theirs->id])
        ->assertForbidden();

    expect(session('current_business_id'))->toBe($mine->id);
});
