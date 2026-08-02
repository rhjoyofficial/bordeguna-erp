<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;

/**
 * W2 - which business a user lands in when the session carries none.
 *
 * ⚠⚠ This was decided by `ORDER BY name`: a DISPLAY STRING chose whose data
 * somebody saw. Live on production, the super admin landed in Azmion, which is
 * empty, so every HRM screen rendered blank and correct - the most misleading
 * possible failure. The latent half was worse: renaming a business relocated
 * every user whose session had expired, with no migration and no warning.
 *
 * ⚠ ALL SEVEN were run against the pre-fix code by putting `orderBy('name')`
 * back, and all seven FAILED. I expected only the first two to; every scenario
 * below is a case the old ordering got wrong, which is a fair measure of how
 * little the previous answer had to do with intent.
 *
 * They pin the ordering as TOTAL: the flag is the instruction and the id is the
 * floor, so no state of the table - none flagged, several flagged, the flagged
 * one inactive or inaccessible - can produce a name-dependent answer.
 * See BUSINESS-SWITCHING-ARCHITECTURE-REVIEW.md §11.
 *
 * `is_default` is set directly rather than mass-assigned because it is
 * deliberately not fillable (§11.5), and these tests would not catch that.
 */
function landingBusiness(string $name, string $slug, bool $default = false, bool $active = true): Business
{
    $business = Business::create(['name' => $name, 'slug' => $slug, 'is_active' => $active]);

    if ($default) {
        $business->is_default = true;
        $business->save();
    }

    return $business;
}

it('lands a super admin on the flagged business, not the alphabetically first one', function () {
    // Production's exact shape: the intended default sorts LAST by name and LAST
    // by id, so neither accident can make this pass.
    landingBusiness('Azmion', 'azmion');
    $borDeGuna = landingBusiness('Bor De Guna', 'bor-de-guna', default: true);

    $admin = User::factory()->create(['is_super_admin' => true]);

    $this->actingAs($admin)->get('/dashboard')->assertOk();

    expect(session('current_business_id'))->toBe($borDeGuna->id);
});

it('does not move anybody when a business is renamed', function () {
    // The regression that outlives the visible bug. Under `orderBy('name')` the
    // first resolution below was right by accident and the second one silently
    // moved the user into another tenant.
    $alpha = landingBusiness('Alpha Corp', 'alpha-corp', default: true);
    landingBusiness('Beta Corp', 'beta-corp');

    $admin = User::factory()->create(['is_super_admin' => true]);

    $this->actingAs($admin)->get('/dashboard')->assertOk();
    expect(session('current_business_id'))->toBe($alpha->id);

    $alpha->update(['name' => 'Zulu Corp']);
    session()->forget('current_business_id');

    $this->actingAs($admin)->get('/dashboard')->assertOk();

    expect(session('current_business_id'))->toBe($alpha->id);
});

it('lands a member on the flagged business when they belong to several', function () {
    // Also guards the join: `business_user` has its own `id`, so an unqualified
    // ORDER BY here is ambiguous SQL and this test would error rather than fail.
    $alphabeticallyFirst = landingBusiness('Aaa Trading', 'aaa-trading');
    $flagged = landingBusiness('Zulu Trading', 'zulu-trading', default: true);

    $user = User::factory()->create();
    $user->businesses()->attach([$alphabeticallyFirst->id, $flagged->id]);

    $this->actingAs($user)->get('/dashboard')->assertOk();

    expect(session('current_business_id'))->toBe($flagged->id);
});

it('falls back to the lowest id, not the first name, when the default is not theirs', function () {
    landingBusiness('Aaa Default', 'aaa-default', default: true);
    $zeta = landingBusiness('Zeta Co', 'zeta-co');
    $mid = landingBusiness('Mid Co', 'mid-co');

    $user = User::factory()->create();
    $user->businesses()->attach([$zeta->id, $mid->id]);

    $this->actingAs($user)->get('/dashboard')->assertOk();

    // Alphabetically this is "Mid Co". By id it is Zeta, and id is what decides.
    expect(session('current_business_id'))->toBe($zeta->id);
});

it('stays deterministic when nothing is flagged at all', function () {
    // The state a half-applied migration leaves behind. It must still not be
    // decided by the name.
    $zeta = landingBusiness('Zeta Holdings', 'zeta-holdings');
    landingBusiness('Alpha Holdings', 'alpha-holdings');

    $admin = User::factory()->create(['is_super_admin' => true]);

    $this->actingAs($admin)->get('/dashboard')->assertOk();

    expect(session('current_business_id'))->toBe($zeta->id);
});

it('resolves to the lowest flagged id when several rows are flagged', function () {
    // Bad data or a hand edit. The ordering is total precisely so this does not
    // need a database invariant to hold - see §11.3.
    $lowestFlagged = landingBusiness('Zulu One', 'zulu-one', default: true);
    landingBusiness('Alpha Two', 'alpha-two', default: true);

    $admin = User::factory()->create(['is_super_admin' => true]);

    $this->actingAs($admin)->get('/dashboard')->assertOk();

    expect(session('current_business_id'))->toBe($lowestFlagged->id);
});

it('does not strand a user on an inactive default', function () {
    landingBusiness('Zeta Closed', 'zeta-closed', default: true, active: false);
    $mango = landingBusiness('Mango Live', 'mango-live');
    landingBusiness('Alpha Live', 'alpha-live');

    $admin = User::factory()->create(['is_super_admin' => true]);

    $this->actingAs($admin)->get('/dashboard')->assertOk();

    // Not "Alpha Live": the flag is skipped with the inactive row, and the
    // fallback underneath it is the id, never the name.
    expect(session('current_business_id'))->toBe($mango->id);
});
