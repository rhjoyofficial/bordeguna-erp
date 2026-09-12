<?php

use App\Modules\Commerce\Enums\PageType;
use App\Modules\Commerce\Models\Page;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StoreDomain;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Support\BdGeo;

/**
 * Website Phase 2, slice 2.2: where the customer lives.
 *
 * ⭐ The dataset is a fact about Bangladesh rather than a courier's coverage
 * list, and these tests are what stop it quietly becoming one again. The two
 * that matter most are the CITY THANAS test - a plain upazila list gives Dhaka
 * five thanas and no Gulshan, which silently breaks every city order - and the
 * CONTAINMENT test, which is what stops a form pairing Gulshan with Bagerhat.
 *
 * ⚠ The postcode rules had NO test of their own in the implementation this was
 * ported from. They are the most intricate part of the class, so every one of
 * the three rules and both documented exceptions is pinned here against the
 * real shipped dataset.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Bor de Guna', 'slug' => 'bor-de-guna']);
    app(BusinessContext::class)->set($this->business->id);

    $this->store = Store::create([
        'key' => 'bionic', 'name' => 'Bionic Garden', 'order_prefix' => 'BNC',
        'theme_key' => 'bionic-classic',
    ]);
    StoreDomain::create(['store_id' => $this->store->id, 'hostname' => 'shop.test', 'is_primary' => true]);
    $this->store->forceFill(['status' => 'live'])->save();

    $home = Page::create([
        'store_id' => $this->store->id, 'type' => PageType::Home, 'slug' => 'home', 'title' => 'Welcome',
    ]);
    $home->forceFill(['published_at' => now()])->save();
});

/* -------------------------------------------------------------------------
 | The tree itself
 | ---------------------------------------------------------------------- */

it('carries every district of Bangladesh, each with thanas under it', function () {
    $districts = BdGeo::districts();

    expect($districts)->toHaveCount(64);

    foreach ($districts as $district) {
        expect(BdGeo::thanas($district['name']))
            ->not->toBeEmpty("{$district['name']} has no thanas");
    }
});

it('gives Dhaka its city thanas and not only its upazilas', function () {
    // ⭐⭐ THE TEST THAT PROTECTS THE CHOICE OF DATASET. A plain upazila list
    // gives Dhaka district five rural thanas and no Gulshan at all, so every
    // order from the city it mostly sells to would have nowhere to go.
    $thanas = array_column(BdGeo::thanas('Dhaka'), 'name');

    expect($thanas)->toContain('Gulshan')
        ->and($thanas)->toContain('Dhanmondi')
        ->and(count($thanas))->toBeGreaterThan(20);
});

it('gives a rural district its upazilas', function () {
    $thanas = array_column(BdGeo::thanas('Barguna'), 'name');

    expect($thanas)->toContain('Betagi')->and($thanas)->toContain('Amtali');
});

it('only ever finds a place inside its own parent', function () {
    // ⭐ Containment. Whatever a form posts, the pair has to describe one real
    // place: Gulshan is a Dhaka thana and is not reachable under Bagerhat.
    expect(BdGeo::thanaNamed('Dhaka', 'Gulshan'))->toBe('Gulshan')
        ->and(BdGeo::thanaNamed('Bagerhat', 'Gulshan'))->toBeNull();
});

it('answers null for a place it does not carry, rather than guessing', function () {
    expect(BdGeo::districtNamed('Atlantis'))->toBeNull()
        ->and(BdGeo::thanas('Atlantis'))->toBe([])
        ->and(BdGeo::thanaNamed('Dhaka', 'Nowhere'))->toBeNull()
        ->and(BdGeo::districtNamed(null))->toBeNull()
        ->and(BdGeo::districtNamed(''))->toBeNull();
});

it('never lets two districts share a match key', function () {
    // ⚠ districtKey strips vowels and doubled letters, which is lossy enough to
    // match two different places on a longer list. It is only safe because the
    // 64 district names happen not to collide - so that has to be proven, not
    // assumed, and this fails the day a dataset adds a colliding name.
    $seen = [];

    foreach (BdGeo::districts() as $district) {
        $key = BdGeo::districtKey($district['name']);
        expect($seen)->not->toHaveKey($key, "{$district['name']} collides with ".($seen[$key] ?? ''));
        $seen[$key] = $district['name'];
    }
});

it('resolves a courier spelling to the standard district name', function (string $written, string $standard) {
    expect(BdGeo::districtNamed($written))->toBe($standard);
})->with([
    ['Chittagong', 'Chattogram'],
    ['B. Baria', 'Brahmanbaria'],
    ['Bramanbaria', 'Brahmanbaria'],
    // Spelling stops mattering only after an exact match fails, so ordinary
    // sloppiness resolves without needing an alias of its own.
    ['  dhaka  ', 'Dhaka'],
    ['Jessore', 'Jashore'],
]);

it('puts every district in exactly one division', function () {
    // ⚠ DIVISIONS is hand-written, because the dataset carries no division
    // field. A typo there is invisible until a delivery-price screen quietly
    // leaves a district out, so every name is checked both ways.
    $mapped = array_merge(...array_values(BdGeo::DIVISIONS));

    expect($mapped)->toHaveCount(64)
        ->and(array_unique($mapped))->toHaveCount(64);

    foreach (BdGeo::districts() as $district) {
        expect(BdGeo::divisionOf($district['name']))
            ->not->toBeNull("{$district['name']} belongs to no division");
    }
});

it('matches an area exactly and never loosely', function () {
    // ⚠ A thana holds twenty areas whose names differ by a syllable, and the
    // whole point of an area is to be more precise than the thana. The loose
    // key that is safe across 64 districts would be reckless here.
    expect(BdGeo::areaNamed('Dhaka', 'Gulshan', 'Banani TSO'))->toBe('Banani TSO')
        ->and(BdGeo::areaNamed('Dhaka', 'Gulshan', 'Bnni TSO'))->toBeNull();
});

it('carries the post office designation as part of the area name', function () {
    // ⚠⚠ A CONSEQUENCE OF EXACT MATCHING, RECORDED RATHER THAN HIDDEN. The
    // dataset calls it "Banani TSO", so the name everybody actually says -
    // "Banani" - matches nothing.
    //
    // That is correct for the way the customer meets this: they PICK from the
    // list the cascade serves, so they pick the dataset's own spelling and it
    // matches by construction. It only bites free text, and free text is
    // deliberately kept as the note it is rather than being promoted to a place
    // the shop then acts on.
    //
    // ⛔ Do not "fix" this by loosening the area match. Two areas in one thana
    // differ by a syllable, so a loose match here buys a wrong address rather
    // than a missing one, and a wrong address is the expensive kind.
    expect(BdGeo::areaNamed('Dhaka', 'Gulshan', 'Banani'))->toBeNull();
});

it('says nothing at all rather than breaking when the dataset is switched off', function () {
    config()->set('geo.dataset', '');

    expect(BdGeo::districts())->toBe([])
        ->and(BdGeo::thanas('Dhaka'))->toBe([])
        ->and(BdGeo::districtNamed('Dhaka'))->toBeNull()
        ->and(BdGeo::postcodeFor('Dhaka', 'Gulshan', 'Banani'))->toBeNull();
});

/* -------------------------------------------------------------------------
 | The postcode, derived and never invented
 | ---------------------------------------------------------------------- */

it('takes the area own postcode when the dataset carries one', function () {
    // Rule 1. Saroatoli is one of six coded areas in a thana whose codes differ,
    // so only its own stated code can produce this.
    expect(BdGeo::postcodeFor('Chattogram', 'Boalkhali', 'Saroatoli'))->toBe('4364');
});

it('falls through to the thana code when the chosen area has none of its own', function () {
    // Rule 2, and the fall-through is the part worth pinning: Barobaria carries
    // no code, and every coded area in Chitalmari agrees on 9361. Stopping at
    // the matched area would answer null and throw away a stated fact.
    expect(BdGeo::postcodeFor('Bagerhat', 'Chitalmari', 'Barobaria'))->toBe('9361')
        // The same code with no area named at all, for the same reason.
        ->and(BdGeo::postcodeFor('Bagerhat', 'Chitalmari'))->toBe('9361');
});

it('picks the head office by name, not the lowest number', function (string $district, string $thana, string $head, string $lowest) {
    // ⭐ Rule 3, and these are the only two thanas in the country where the two
    // rules disagree. Bangladesh usually gives the upazila head office the round
    // number, so the lowest code is nearly always right - but in these two the
    // lowest belongs to a sub-office, and the named office is the real head.
    // Sorting by number would silently route both to the wrong post office.
    expect(BdGeo::postcodeFor($district, $thana))->toBe($head)
        ->and(BdGeo::postcodeFor($district, $thana))->not->toBe($lowest);
})->with([
    ['Chattogram', 'Boalkhali', '4366', '4363'],
    // ⚠ The thana is spelled Lohajong and its head-office area is spelled
    // Lohajang. That one vowel is why the name match uses the loose key.
    ['Munshiganj', 'Lohajong', '1530', '1333'],
]);

it('answers null for a thana the dataset holds no postcode for anywhere', function () {
    // 172 thanas are in this state, and an honest null is the right answer:
    // there is nothing to derive from. A guess here would hash to a value
    // matching nobody and route a parcel to the wrong upazila.
    expect(BdGeo::postcodeFor('Bagerhat', 'Kachua'))->toBeNull();
});

/* -------------------------------------------------------------------------
 | The cascade over HTTP
 | ---------------------------------------------------------------------- */

it('serves the districts to a shop', function () {
    $response = $this->get('http://shop.test/geo/districts');

    $response->assertOk();

    expect($response->json('data'))->toHaveCount(64)
        // The Bengali name travels with it, because the shop is read in Bangla.
        ->and($response->json('data.0'))->toHaveKeys(['name', 'bn_name']);
});

it('serves only the thanas of the district that was asked for', function () {
    $names = array_column($this->get('http://shop.test/geo/thanas?district=Dhaka')->assertOk()->json('data'), 'name');

    expect($names)->toContain('Gulshan')->and($names)->not->toContain('Betagi');
});

it('answers an empty list rather than an error for a place it does not know', function () {
    // ⚠ Nothing is wrong: the shop has no opinion about that name. A 404 would
    // make a mistyped district look like a broken endpoint.
    $this->get('http://shop.test/geo/thanas?district=Atlantis')->assertOk()->assertJson(['data' => []]);
    $this->get('http://shop.test/geo/areas?district=Dhaka&thana=Nowhere')->assertOk()->assertJson(['data' => []]);
    $this->get('http://shop.test/geo/thanas')->assertOk()->assertJson(['data' => []]);
});

it('never tells the browser a postcode', function () {
    // ⛔ The dataset holds one for Banani and the shop keeps it to itself. A
    // postcode sent out is a postcode a form can post back, and at that point
    // the shop is trusting a client about an address it knows better itself.
    $response = $this->get('http://shop.test/geo/areas?district=Dhaka&thana=Gulshan');

    $response->assertOk();

    expect(BdGeo::postcodeFor('Dhaka', 'Gulshan', 'Banani TSO'))->toBe('1213');

    foreach ($response->json('data') as $area) {
        expect($area)->toHaveKeys(['name', 'bn_name'])->and($area)->not->toHaveKey('postcode');
    }
});

it('lets the answer be cached, because it is a fact about the country', function () {
    // ⚠ Asserted as directives rather than as one string: Symfony normalises
    // Cache-Control into alphabetical order, so the exact header text is not
    // what was written.
    $header = $this->get('http://shop.test/geo/districts')->assertOk()->headers->get('Cache-Control');

    expect($header)->toContain('public')->and($header)->toContain('max-age=86400');
});

it('has no geography on the ERP own hostname', function () {
    // ⚠ The answer would be identical for every tenant, so this is not about
    // leaking anything. It is that a storefront endpoint answering on the ERP's
    // own domain is the beginning of the shop existing in two places.
    $this->get('http://erp.test/geo/districts')->assertNotFound();
    $this->get('http://erp.test/geo/thanas?district=Dhaka')->assertNotFound();
    $this->get('http://erp.test/geo/areas?district=Dhaka&thana=Gulshan')->assertNotFound();
});
