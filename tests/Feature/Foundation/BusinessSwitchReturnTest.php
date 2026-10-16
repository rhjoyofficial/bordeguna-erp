<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Hrm\Models\Shift;

/**
 * W5 - the smaller things. See BUSINESS-SWITCHING-ARCHITECTURE-REVIEW.md §14.
 *
 * Two of the four items in §6 were built (a switch discarding your place, and
 * "no business" being indistinguishable from "empty business"), one was declined
 * with a reason (§14.3), and one turned out to be ALREADY CORRECT - the review
 * was wrong about it, and the last test here is what establishes that.
 */
function switcherUser(Business ...$businesses): User
{
    $user = User::factory()->create(['is_super_admin' => true]);
    $user->businesses()->attach(collect($businesses)->pluck('id')->all());

    return $user;
}

it('returns to the same parameter-free page in the new business', function () {
    $from = Business::create(['name' => 'From Co', 'slug' => 'from-co']);
    $to = Business::create(['name' => 'To Co', 'slug' => 'to-co']);

    $user = switcherUser($from, $to);

    $this->actingAs($user)
        ->withSession(['current_business_id' => $from->id])
        ->from(route('hrm.shifts.index'))
        ->post(route('businesses.switch.store'), ['business_id' => $to->id])
        ->assertRedirect(route('hrm.shifts.index'));
});

it('falls back to the dashboard when the page carried a route parameter', function () {
    // ⚠ The safety rule. `/hrm/shifts` means the same thing in any tenant;
    // `/hrm/shifts/3` does not, because shift 3 belongs to one of them.
    $from = Business::create(['name' => 'From Co', 'slug' => 'from-co']);
    $to = Business::create(['name' => 'To Co', 'slug' => 'to-co']);

    $user = switcherUser($from, $to);

    $shift = Shift::withoutGlobalScopes()->create([
        'business_id' => $from->id,
        'name' => 'Office',
        'start_time' => '09:00',
        'end_time' => '18:00',
        'grace_minutes' => 0,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->withSession(['current_business_id' => $from->id])
        ->from(route('hrm.shifts.update', $shift))
        ->post(route('businesses.switch.store'), ['business_id' => $to->id])
        ->assertRedirect(route('dashboard'));
});

it('falls back to the dashboard for an off-site referer', function () {
    $from = Business::create(['name' => 'From Co', 'slug' => 'from-co']);
    $to = Business::create(['name' => 'To Co', 'slug' => 'to-co']);

    $user = switcherUser($from, $to);

    $this->actingAs($user)
        ->withSession(['current_business_id' => $from->id])
        ->from('https://example.com/somewhere')
        ->post(route('businesses.switch.store'), ['business_id' => $to->id])
        ->assertRedirect(route('dashboard'));
});

it('falls back to the dashboard rather than bouncing back to the switcher', function () {
    $from = Business::create(['name' => 'From Co', 'slug' => 'from-co']);
    $to = Business::create(['name' => 'To Co', 'slug' => 'to-co']);

    $user = switcherUser($from, $to);

    $this->actingAs($user)
        ->withSession(['current_business_id' => $from->id])
        ->from(route('businesses.switch'))
        ->post(route('businesses.switch.store'), ['business_id' => $to->id])
        ->assertRedirect(route('dashboard'));
});

it('tells a user with no business what is wrong instead of showing empty screens', function () {
    // ⚠ Without this they get BusinessScope's correct 1 = 0 on every screen:
    // empty, accurate and unexplained. The same misleading shape as W2.
    Business::create(['name' => 'Not Theirs', 'slug' => 'not-theirs']);

    $user = User::factory()->create(['is_super_admin' => false]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertRedirect(route('businesses.switch'));

    expect(session('error'))->toContain('not attached to any business');
});

it('does not loop: a user with no business can still reach the switcher and log out', function () {
    Business::create(['name' => 'Not Theirs', 'slug' => 'not-theirs']);

    $user = User::factory()->create(['is_super_admin' => false]);

    $this->actingAs($user)
        ->get(route('businesses.switch'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('business-switcher')->has('businesses', 0));

    $this->actingAs($user)->post(route('logout'))->assertRedirect();
    $this->assertGuest();
});

it('evicts a user from a business that has been deactivated', function () {
    // ⚠⚠ §6 of the review claimed this was BROKEN: "a session already holding
    // that id keeps working until it is re-resolved". It is not. findAccessible()
    // runs on EVERY request carrying a session business and its first statement
    // filters on is_active, so the user is moved out on their very next request.
    //
    // Proven here rather than by re-reading the code, because re-reading is how
    // the wrong claim got into the review. See §14.4.
    $closing = Business::create(['name' => 'Closing Co', 'slug' => 'closing-co']);
    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-co']);

    $user = switcherUser($closing, $other);

    $this->actingAs($user)
        ->withSession(['current_business_id' => $closing->id])
        ->get('/dashboard')
        ->assertOk();

    expect(session('current_business_id'))->toBe($closing->id);

    $closing->update(['is_active' => false]);

    $this->actingAs($user)
        ->withSession(['current_business_id' => $closing->id])
        ->get('/dashboard')
        ->assertOk();

    expect(session('current_business_id'))->toBe($other->id);
});

// ⚠ Bug hunt 16 Oct 2026 (03 M1): `?item=5` names a record of the business
// being left; carried across it opened a 404 or picked another business's record.
it('returns to the same page without the old business query string', function () {
    $from = Business::create(['name' => 'From Co', 'slug' => 'from-co']);
    $to = Business::create(['name' => 'To Co', 'slug' => 'to-co']);

    $this->actingAs(switcherUser($from, $to))
        ->withSession(['current_business_id' => $from->id])
        ->from(route('hrm.shifts.index').'?item=5')
        ->post(route('businesses.switch.store'), ['business_id' => $to->id])
        ->assertRedirect(route('hrm.shifts.index'));
});
