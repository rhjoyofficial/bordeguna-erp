<?php

use App\Models\User;
use App\Modules\Foundation\Models\ActivityLogEntry;
use App\Modules\Foundation\Models\Business;
use Illuminate\Support\Collection;

/**
 * W4 - switching tenant was the one privileged action with no audit trail, while
 * every report run, approval, correction and salary revision was logged. For a
 * super admin it is a cross-tenant move.
 *
 * ⚠⚠ The hard part is not writing the entry, it is deciding WHOSE log it lands
 * in. `ActivityLogEntry` uses BelongsToBusiness, so business_id is stamped from
 * BusinessContext on create and filtered by BusinessScope on read. Two traps
 * follow, and the tests below exist for them rather than for the happy path:
 *
 *  1. Left to inherit, the entry lands in the business being LEFT, because the
 *     session key only takes effect on the next request. How somebody entered a
 *     tenant would be recorded only in the tenant they came from.
 *  2. A NULL business_id is invisible from inside EVERY tenant, not visible from
 *     all of them, because the scope compares `business_id = ?`. Only one path
 *     reaches that state, and it is pinned below.
 *
 * ⚠ §12.2 also argued that a first switch has no source and would therefore be
 * lost. That was WRONG, and the tests found it: ResolveCurrentBusiness always
 * resolves one first. The correction is §12.7, kept rather than deleted. The
 * decision stands on its first argument alone - the entry belongs to the tenant
 * being ENTERED, because that is the one whose data is now exposed.
 *
 * See BUSINESS-SWITCHING-ARCHITECTURE-REVIEW.md §12.
 */
function switchEntries(?int $businessId = null): Collection
{
    return ActivityLogEntry::withoutGlobalScopes()
        ->where('log_name', 'foundation.business_switch')
        ->when($businessId !== null, fn ($q) => $q->where('business_id', $businessId))
        ->get();
}

it('records a switch against the destination business, with both sides in its properties', function () {
    $from = Business::create(['name' => 'From Co', 'slug' => 'from-co']);
    $to = Business::create(['name' => 'To Co', 'slug' => 'to-co']);

    $user = User::factory()->create(['is_super_admin' => true]);
    $user->businesses()->attach([$from->id, $to->id]);

    $this->actingAs($user)
        ->withSession(['current_business_id' => $from->id])
        ->post(route('businesses.switch.store'), ['business_id' => $to->id])
        ->assertRedirect();

    $entries = switchEntries();
    expect($entries)->toHaveCount(1);

    $entry = $entries->first();
    expect($entry->business_id)->toBe($to->id)
        ->and($entry->causer_id)->toBe($user->id)
        ->and($entry->description)->toBe('Switched into To Co from From Co');

    expect($entry->properties->all())->toMatchArray([
        'result' => 'switched',
        'from_business_id' => $from->id,
        'from_business_name' => 'From Co',
        'to_business_id' => $to->id,
        'to_business_name' => 'To Co',
        'is_super_admin' => true,
    ]);
});

it('shows the switch on the destination dashboard and not on the source one', function () {
    // ⚠ The ownership decision proven from the READER's side. This is the test
    // that fails if somebody later removes the explicit business_id as
    // redundant: the entry would still be written, still be findable in SQL,
    // and appear on exactly the wrong dashboard.
    $from = Business::create(['name' => 'From Co', 'slug' => 'from-co']);
    $to = Business::create(['name' => 'To Co', 'slug' => 'to-co']);

    $user = User::factory()->create(['is_super_admin' => true]);
    $user->businesses()->attach([$from->id, $to->id]);

    $this->actingAs($user)
        ->withSession(['current_business_id' => $from->id])
        ->post(route('businesses.switch.store'), ['business_id' => $to->id]);

    // Now sitting in the destination.
    $this->actingAs($user)
        ->withSession(['current_business_id' => $to->id])
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where(
            'recent_activity',
            fn ($activity) => collect($activity)->contains(
                fn ($row) => str_contains($row['description'], 'Switched into To Co'),
            ),
        ));

    // Back in the source, the same entry must not be there.
    $this->actingAs($user)
        ->withSession(['current_business_id' => $from->id])
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where(
            'recent_activity',
            fn ($activity) => collect($activity)->doesntContain(
                fn ($row) => str_contains($row['description'], 'Switched into To Co'),
            ),
        ));
});

it('records the middleware-resolved landing business as the source when the session had none', function () {
    // ⚠ Written for a case that turned out not to exist. §12.2 argued that a
    // first switch has no source; ResolveCurrentBusiness runs BEFORE this
    // controller and writes a resolved business into the session, so on the HTTP
    // path there is always one. See §12.7 - the note was corrected rather than
    // the test quietly reshaped to agree with the code.
    //
    // What is worth pinning is the interaction itself: the source recorded here
    // is chosen by the middleware, and nothing in the controller says so.
    $landing = Business::create(['name' => 'Landing Co', 'slug' => 'landing-co']);
    $to = Business::create(['name' => 'To Co', 'slug' => 'to-co']);

    $user = User::factory()->create(['is_super_admin' => true]);
    $user->businesses()->attach([$landing->id, $to->id]);

    // No current_business_id in session at all.
    $this->actingAs($user)
        ->post(route('businesses.switch.store'), ['business_id' => $to->id])
        ->assertRedirect();

    $entry = switchEntries()->sole();

    expect($entry->business_id)->toBe($to->id)
        ->and($entry->business_id)->not->toBeNull()
        ->and($entry->properties['from_business_id'])->toBe($landing->id)
        ->and($entry->description)->toBe('Switched into To Co from Landing Co');
});

it('still records a denied switch by a user who belongs to no business at all', function () {
    // The only path on which the source is genuinely null, and the residual
    // accepted in §12.4: such a user has no tenant for the entry to belong to,
    // so it is written with a null business_id and is visible only in SQL.
    // Inventing an owner would be worse than the gap; dropping it silently
    // would be worse still.
    $theirs = Business::create(['name' => 'Theirs Co', 'slug' => 'theirs-co']);

    $user = User::factory()->create(['is_super_admin' => false]);

    $this->actingAs($user)
        ->post(route('businesses.switch.store'), ['business_id' => $theirs->id])
        ->assertForbidden();

    $entry = switchEntries()->sole();

    expect($entry->business_id)->toBeNull()
        ->and($entry->description)->toBe('Denied switch into Theirs Co')
        ->and($entry->properties['result'])->toBe('denied');

    expect(switchEntries($theirs->id))->toHaveCount(0);
});

it('records a denied switch against where the actor is, never against the target', function () {
    $mine = Business::create(['name' => 'Mine Co', 'slug' => 'mine-co']);
    $theirs = Business::create(['name' => 'Theirs Co', 'slug' => 'theirs-co']);

    $user = User::factory()->create(['is_super_admin' => false]);
    $user->businesses()->attach($mine->id);

    $this->actingAs($user)
        ->withSession(['current_business_id' => $mine->id])
        ->post(route('businesses.switch.store'), ['business_id' => $theirs->id])
        ->assertForbidden();

    // ⚠ A denied action must not be able to write into the tenant that denied
    // it. The dashboard feed is short, so that would let a rejected user push a
    // tenant's visible history off screen without ever getting in.
    expect(switchEntries($theirs->id))->toHaveCount(0);

    $entry = switchEntries($mine->id)->sole();
    expect($entry->description)->toBe('Denied switch into Theirs Co')
        ->and($entry->properties->all())->toMatchArray([
            'result' => 'denied',
            'target_business_id' => $theirs->id,
            'target_business_name' => 'Theirs Co',
        ]);

    expect(session('current_business_id'))->toBe($mine->id);
});

it('writes nothing when the chosen business is the one already active', function () {
    $here = Business::create(['name' => 'Here Co', 'slug' => 'here-co']);

    $user = User::factory()->create(['is_super_admin' => true]);
    $user->businesses()->attach($here->id);

    $this->actingAs($user)
        ->withSession(['current_business_id' => $here->id])
        ->post(route('businesses.switch.store'), ['business_id' => $here->id])
        ->assertRedirect();

    expect(switchEntries())->toHaveCount(0);
});

it('writes nothing when the request carries no business_id', function () {
    $here = Business::create(['name' => 'Here Co', 'slug' => 'here-co']);

    $user = User::factory()->create(['is_super_admin' => true]);
    $user->businesses()->attach($here->id);

    $this->actingAs($user)
        ->withSession(['current_business_id' => $here->id])
        ->post(route('businesses.switch.store'), [])
        ->assertSessionHasErrors('business_id');

    expect(switchEntries())->toHaveCount(0);
});
