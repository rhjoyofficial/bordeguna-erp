<?php

use App\Models\User;
use App\Modules\Foundation\Http\Middleware\EnsurePageBusinessMatches;
use App\Modules\Foundation\Models\Business;
use App\Modules\Hrm\Models\Shift;
use Symfony\Component\HttpFoundation\Response;

/**
 * W3 - the current business is a property of the SESSION, not the page.
 *
 * ⚠⚠ The hidden assumption in the whole design was one user, one tab. Switch
 * business in tab B, return to tab A which still shows the previous tenant's
 * figures, click a button, and the write lands in the tenant tab A was never
 * displaying. The request carried no business identity of its own, so nothing
 * could detect it: it failed silently, and it wrote.
 *
 * Joy chose explicit rejection over a silent wrong-tenant write (C-BS-2). The
 * page now asserts the context it was built for, exactly like a CSRF token.
 *
 * See BUSINESS-SWITCHING-ARCHITECTURE-REVIEW.md §13.
 */
function tabUser(Business ...$businesses): User
{
    $user = User::factory()->create(['is_super_admin' => true]);
    $user->businesses()->attach(collect($businesses)->pluck('id')->all());

    return $user;
}

function stamped(string $businessId): array
{
    return [EnsurePageBusinessMatches::HEADER => $businessId, 'X-Inertia' => 'true'];
}

it('refuses a write from a page built for a different business, and changes nothing', function () {
    // ⚠ THE DEFECT. This fails before the fix: the shift is renamed inside
    // LaLaDia by a click on a page that was showing Bor De Guna.
    $tabA = Business::create(['name' => 'Tab A Co', 'slug' => 'tab-a-co']);
    $tabB = Business::create(['name' => 'Tab B Co', 'slug' => 'tab-b-co']);

    $user = tabUser($tabA, $tabB);

    $shift = Shift::withoutGlobalScopes()->create([
        'business_id' => $tabB->id,
        'name' => 'Untouched',
        'start_time' => '09:00',
        'end_time' => '18:00',
        'grace_minutes' => 0,
        'is_active' => true,
    ]);

    // The session has already moved on to tab B's business; the page in hand was
    // built for tab A's.
    $this->actingAs($user)
        ->withSession(['current_business_id' => $tabB->id])
        ->withHeaders(stamped((string) $tabA->id))
        ->put(route('hrm.shifts.update', $shift), [
            'name' => 'Renamed by a stale tab',
            'start_time' => '09:00',
            'end_time' => '18:00',
            'grace_minutes' => 0,
            'break_minutes' => 0,
            'is_overnight' => false,
        ])
        ->assertRedirect()
        // ⚠⚠ 303, and this assertion exists because a BROWSER caught what this
        // file could not. With the default 302 the browser follows a rejected
        // PUT with another PUT, to the index route, and the person sees a raw
        // 405 exception page instead of the message. `assertRedirect` passed the
        // whole time. See §13.10.
        ->assertStatus(Response::HTTP_SEE_OTHER);

    expect($shift->fresh()->name)->toBe('Untouched');
    expect(session('error'))->toBe(EnsurePageBusinessMatches::MESSAGE);
});

it('lets a write through when the page and the session agree', function () {
    $business = Business::create(['name' => 'Only Co', 'slug' => 'only-co']);
    $user = tabUser($business);

    $shift = Shift::withoutGlobalScopes()->create([
        'business_id' => $business->id,
        'name' => 'Before',
        'start_time' => '09:00',
        'end_time' => '18:00',
        'grace_minutes' => 0,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->withSession(['current_business_id' => $business->id])
        ->withHeaders(stamped((string) $business->id))
        ->put(route('hrm.shifts.update', $shift), [
            'name' => 'After',
            'start_time' => '09:00',
            'end_time' => '18:00',
            'grace_minutes' => 0,
            'break_minutes' => 0,
            'is_overnight' => false,
        ]);

    expect($shift->fresh()->name)->toBe('After');
});

it('still lets a stale tab switch business, which is the one way out', function () {
    // ⚠⚠ Not optional. Tab A's switcher page asserts the OLD business, so
    // guarding this route would make the single action that resolves the
    // problem the one action refused.
    $tabA = Business::create(['name' => 'Tab A Co', 'slug' => 'tab-a-co']);
    $tabB = Business::create(['name' => 'Tab B Co', 'slug' => 'tab-b-co']);

    $user = tabUser($tabA, $tabB);

    $this->actingAs($user)
        ->withSession(['current_business_id' => $tabB->id])
        ->withHeaders(stamped((string) $tabA->id))
        ->post(route('businesses.switch.store'), ['business_id' => $tabA->id])
        ->assertRedirect(route('dashboard'));

    expect(session('current_business_id'))->toBe($tabA->id);
});

it('does not interfere with reading a page from a stale tab', function () {
    // A stale GET shows stale data and writes nothing. The next navigation
    // corrects it, and refusing reads would make the app unusable mid-switch.
    //
    // ⚠ No X-Inertia header here, deliberately. Sending one on a GET without a
    // version makes INERTIA itself answer 409 to force a full reload, which has
    // nothing to do with this guard - and is precisely why the rejection below
    // is a redirect rather than a 409 that Inertia would reinterpret.
    $tabA = Business::create(['name' => 'Tab A Co', 'slug' => 'tab-a-co']);
    $tabB = Business::create(['name' => 'Tab B Co', 'slug' => 'tab-b-co']);

    $user = tabUser($tabA, $tabB);

    $this->actingAs($user)
        ->withSession(['current_business_id' => $tabB->id])
        ->withHeaders([EnsurePageBusinessMatches::HEADER => (string) $tabA->id])
        ->get('/dashboard')
        ->assertOk();
});

it('refuses an Inertia write that carries no stamp at all', function () {
    // §13.6: fail closed for our own front end. An Inertia request with no stamp
    // means the client did not do its job, and nothing else in this app writes.
    $business = Business::create(['name' => 'Only Co', 'slug' => 'only-co']);
    $user = tabUser($business);

    $shift = Shift::withoutGlobalScopes()->create([
        'business_id' => $business->id,
        'name' => 'Untouched',
        'start_time' => '09:00',
        'end_time' => '18:00',
        'grace_minutes' => 0,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->withSession(['current_business_id' => $business->id])
        ->withHeaders(['X-Inertia' => 'true'])
        ->put(route('hrm.shifts.update', $shift), [
            'name' => 'Renamed without a stamp',
            'start_time' => '09:00',
            'end_time' => '18:00',
            'grace_minutes' => 0,
            'break_minutes' => 0,
            'is_overnight' => false,
        ])
        ->assertRedirect();

    expect($shift->fresh()->name)->toBe('Untouched');
});

it('leaves a non-Inertia write alone, so tooling and tests keep working', function () {
    // The other half of §13.6. Failing these closed would break every caller
    // that is not our own front end, for no protection: the stale-tab problem
    // only exists inside a browser.
    $business = Business::create(['name' => 'Only Co', 'slug' => 'only-co']);
    $user = tabUser($business);

    $shift = Shift::withoutGlobalScopes()->create([
        'business_id' => $business->id,
        'name' => 'Before',
        'start_time' => '09:00',
        'end_time' => '18:00',
        'grace_minutes' => 0,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->withSession(['current_business_id' => $business->id])
        ->put(route('hrm.shifts.update', $shift), [
            'name' => 'After',
            'start_time' => '09:00',
            'end_time' => '18:00',
            'grace_minutes' => 0,
            'break_minutes' => 0,
            'is_overnight' => false,
        ]);

    expect($shift->fresh()->name)->toBe('After');
});

it('lets a stale tab log out', function () {
    $tabA = Business::create(['name' => 'Tab A Co', 'slug' => 'tab-a-co']);
    $tabB = Business::create(['name' => 'Tab B Co', 'slug' => 'tab-b-co']);

    $user = tabUser($tabA, $tabB);

    $this->actingAs($user)
        ->withSession(['current_business_id' => $tabB->id])
        ->withHeaders(stamped((string) $tabA->id))
        ->post(route('logout'))
        ->assertRedirect();

    $this->assertGuest();
});

it('shares the page business so the client has something to stamp with', function () {
    $business = Business::create(['name' => 'Only Co', 'slug' => 'only-co']);
    $user = tabUser($business);

    $this->actingAs($user)
        ->withSession(['current_business_id' => $business->id])
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('current_business_id', $business->id));
});
