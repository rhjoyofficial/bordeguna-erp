<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Couriers\Actions\SaveCourierAccount;
use App\Modules\Sales\Couriers\Locations\CourierDestination;
use App\Modules\Sales\Couriers\Locations\CourierLocationMatcher;
use App\Modules\Sales\Couriers\Locations\LocationSync;
use App\Modules\Sales\Couriers\Locations\ResolvedDestination;
use App\Modules\Sales\Couriers\Locations\SyncCourierLocations;
use App\Modules\Sales\Couriers\Locations\Unresolved;
use App\Modules\Sales\Couriers\Models\CourierAccount;
use App\Modules\Sales\Couriers\Models\CourierApiCall;
use App\Modules\Sales\Couriers\Models\CourierLocation;
use App\Modules\Sales\Couriers\Models\CourierLocationMap;
use App\Modules\Sales\Couriers\Models\CourierLocationSync;
use App\Modules\Sales\Couriers\Models\CourierZoneBand;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Courier hub slice 2 (11 Oct 2026): the ERP never books a parcel to a guessed
 * destination. Most tests here are about what must NOT happen: a zone applied
 * because its name merely looks like the thana, a place resolved with no map,
 * a map saved to a zone in another city.
 */
const LOC_CB = 'https://developers.carrybee.com';
const LOC_PATHAO = 'https://api-hermes.pathao.com';

beforeEach(function () {
    $this->business = Business::create(['name' => 'LaLaDia', 'slug' => 'laladia']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new SalesPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['owner' => 'Owner', 'manager' => 'Manager'] as $prop => $role) {
        $this->{$prop} = User::factory()->create();
        $this->{$prop}->businesses()->attach($this->business->id);
        $this->{$prop}->assignRole($role);
    }

    config(['couriers.location_sync_delay_ms' => 0]);
    Http::preventStrayRequests();
    $this->withoutVite();
});

/** An account that passed a test with what it holds now, switched on. */
function locActiveAccount(string $provider = 'carrybee'): CourierAccount
{
    $account = app(SaveCourierAccount::class)->handle(null, $provider === 'carrybee' ? [
        'provider' => 'carrybee', 'label' => 'LaLaDia', 'environment' => 'live', 'base_url' => LOC_CB,
        'credentials' => ['client_id' => 'cb-id', 'client_secret' => 'cb-SECRET-1234', 'client_context' => 'cb-CONTEXT-5678'],
    ] : [
        'provider' => 'pathao', 'label' => 'Bionic', 'environment' => 'live', 'base_url' => LOC_PATHAO,
        'credentials' => ['client_id' => 'p-id', 'client_secret' => 'p-SECRET-1234', 'username' => 'ops@bionic.garden', 'password' => 'p-PASSWORD-99'],
    ]);

    $account->forceFill(['last_test_at' => now(), 'last_test_ok' => true, 'tested_fingerprint' => $account->currentFingerprint()])->save();
    $account->forceFill(['is_active' => true, 'activated_at' => now()])->save();

    return $account->fresh();
}

/**
 * A small CarryBee world: four cities named the courier's way (Chittagong,
 * Bogra, Cumilla), zones that test every match strength, two areas.
 */
function locFakeCarryBee(array $overrides = [], ?Closure $onRequest = null): void
{
    $defaultZones = [
        '14' => [
            ['id' => 101, 'name' => 'Dhanmondi'],
            ['id' => 102, 'name' => 'Mirpur 1'],
            ['id' => 103, 'name' => 'Mirpur 10'],
            ['id' => 104, 'name' => 'Savar Bazar'],
            ['id' => 105, 'name' => 'Keraniganj Sadar'],
            ['id' => 106, 'name' => 'Dhaka Cantonment'],
        ],
        '19' => [['id' => 201, 'name' => 'Kaliakair']],
        '8' => [['id' => 801, 'name' => 'Chattogram Sadar']],
        '6' => [['id' => 601, 'name' => 'Bogura Sadar']],
    ];
    // A closure is read on every request, so one test can change the list
    // between two syncs (a second Http::fake would sit behind the first).
    $zonesNow = fn (): array => isset($overrides['zones']) ? (is_callable($overrides['zones']) ? ($overrides['zones'])() : $overrides['zones']) : $defaultZones;
    $areas = $overrides['areas'] ?? ['101' => [['id' => 9001, 'name' => 'Jigatola'], ['id' => 9002, 'name' => 'Rayer Bazar']]];

    Http::fake(function (Request $request) use ($zonesNow, $areas, $onRequest) {
        $zones = $zonesNow();
        $onRequest?->__invoke($request);
        $path = parse_url($request->url(), PHP_URL_PATH);
        $ok = fn (array $data) => Http::response(['error' => false, 'message' => 'ok', 'data' => $data]);

        if ($path === '/api/v2/cities') {
            return $ok(['cities' => [
                ['id' => 6, 'name' => 'Bogra'], ['id' => 8, 'name' => 'Chittagong'], ['id' => 14, 'name' => 'Dhaka'],
                ['id' => 19, 'name' => 'Gazipur'], ['id' => 12, 'name' => 'Cumilla'],
            ]]);
        }

        if (preg_match('#^/api/v2/cities/(\d+)/zones$#', $path, $m)) {
            return $ok(['zones' => array_map(fn ($z) => $z + ['city_id' => (int) $m[1]], $zones[$m[1]] ?? [])]);
        }

        if (preg_match('#^/api/v2/cities/\d+/zones/(\d+)/areas$#', $path, $m)) {
            return $ok(['areas' => array_map(fn ($a) => $a + ['zone_id' => (int) $m[1]], $areas[$m[1]] ?? [])]);
        }

        if ($path === '/api/v2/area-suggestion') {
            return $ok(['items' => [['city_id' => 14, 'city_name' => 'Dhaka', 'zone_id' => 101, 'zone_name' => 'Dhanmondi', 'area_id' => 9001, 'area_name' => 'Jigatola']]]);
        }

        return Http::response(['error' => true, 'message' => 'not faked: '.$path], 404);
    });
}

function locSync(CourierAccount $account): CourierLocationSync
{
    $run = app(LocationSync::class)->start($account);
    SyncCourierLocations::dispatchSync($run->id);

    return $run->fresh();
}

function locMap(string $provider, string $district, string $thana): ?CourierLocationMap
{
    return CourierLocationMap::query()->where(compact('provider', 'district', 'thana'))->where('area', '')->first();
}

it('syncs the CarryBee lists into courier_locations, logged, and changes nothing on a second run', function () {
    locFakeCarryBee();
    $account = locActiveAccount();

    $run = locSync($account);

    expect($run->status)->toBe(CourierLocationSync::DONE)
        ->and($run->cities)->toBe(5)->and($run->zones)->toBe(9)->and($run->areas)->toBe(2)
        ->and(CourierLocation::where('level', 'city')->count())->toBe(5)
        ->and(CourierLocation::where('level', 'zone')->count())->toBe(9)
        ->and(CourierLocation::where('level', 'area')->where('parent_provider_id', '101')->count())->toBe(2)
        // One city call, five zone calls, nine area calls; every one logged by path only.
        ->and(CourierApiCall::count())->toBe(15)
        ->and(CourierApiCall::where('endpoint', 'like', '%area-suggestion%')->count())->toBe(0);

    $before = CourierLocation::orderBy('id')->get(['id', 'provider_location_id', 'name'])->toArray();

    $this->travel(2)->minutes();
    $second = locSync($account->fresh());

    expect($second->id)->not->toBe($run->id)
        ->and($second->status)->toBe(CourierLocationSync::DONE)
        ->and(CourierLocation::orderBy('id')->get(['id', 'provider_location_id', 'name'])->toArray())->toBe($before);
});

it('marks a zone the courier stopped listing as removed, never deletes it, and never on a failed call', function () {
    $dhaka = [['id' => 101, 'name' => 'Dhanmondi'], ['id' => 102, 'name' => 'Mirpur 1'], ['id' => 103, 'name' => 'Mirpur 10']];
    $failDhaka = false;
    locFakeCarryBee(['zones' => function () use (&$dhaka) {
        return ['14' => $dhaka, '19' => [['id' => 201, 'name' => 'Kaliakair']]];
    }], function (Request $r) use (&$failDhaka) {
        if ($failDhaka && str_contains($r->url(), '/cities/14/zones')) {
            throw new ConnectionException('timed out');
        }
    });
    $account = locActiveAccount();
    locSync($account);

    // Mirpur 10 disappears from Dhaka's list.
    $dhaka = [['id' => 101, 'name' => 'Dhanmondi'], ['id' => 102, 'name' => 'Mirpur 1']];
    $this->travel(2)->minutes();
    locSync($account->fresh());

    $gone = CourierLocation::where('level', 'zone')->where('provider_location_id', '103')->first();
    expect($gone)->not->toBeNull()->and($gone->removed_at)->not->toBeNull()
        ->and(CourierLocation::where('provider_location_id', '102')->first()->removed_at)->toBeNull();

    // A failed call is not an empty list: nothing under Dhaka is marked removed.
    $failDhaka = true;
    $this->travel(2)->minutes();
    expect(locSync($account->fresh())->status)->toBe(CourierLocationSync::FAILED)
        ->and(CourierLocation::where('level', 'zone')->where('parent_provider_id', '14')->whereNull('removed_at')->count())->toBe(2);
});

it('resumes a sync that stopped instead of starting over', function () {
    $cityCalls = 0;
    $failGazipur = true;
    $account = locActiveAccount();

    locFakeCarryBee(onRequest: function (Request $r) use (&$cityCalls, &$failGazipur) {
        if (str_ends_with($r->url(), '/api/v2/cities')) {
            $cityCalls++;
        }

        if ($failGazipur && str_contains($r->url(), '/cities/19/zones')) {
            throw new ConnectionException('timed out');
        }
    });

    $first = locSync($account);
    expect($first->status)->toBe(CourierLocationSync::FAILED)
        ->and($first->last_error)->toContain('Gazipur');

    $failGazipur = false;
    $resumed = locSync($account->fresh());

    expect($resumed->id)->toBe($first->id)
        ->and($resumed->status)->toBe(CourierLocationSync::DONE)
        ->and($cityCalls)->toBe(1)
        ->and(CourierLocation::where('level', 'zone')->where('parent_provider_id', '19')->count())->toBe(1);
});

it('stops rather than move a zone whose id turns up under two cities in one sync', function () {
    locFakeCarryBee(['zones' => [
        '14' => [['id' => 101, 'name' => 'Dhanmondi']],
        '19' => [['id' => 101, 'name' => 'Kaliakair']],
    ]]);

    $run = locSync(locActiveAccount());

    expect($run->status)->toBe(CourierLocationSync::FAILED)
        ->and($run->last_error)->toContain('not unique')
        ->and(CourierLocation::where('level', 'zone')->where('provider_location_id', '101')->first()->parent_provider_id)->toBe('14');
});

it('splits a sync into batches that queue each other until done', function () {
    config(['couriers.location_sync_calls_per_job' => 2]);
    locFakeCarryBee();

    $run = locSync(locActiveAccount());

    expect($run->status)->toBe(CourierLocationSync::DONE)->and($run->calls)->toBe(15)
        ->and(CourierLocation::where('level', 'area')->count())->toBe(2);
});

it('refuses to sync through an account that is switched off', function () {
    locFakeCarryBee();
    $account = locActiveAccount();
    $account->forceFill(['is_active' => false])->save();

    $this->actingAs($this->owner)->post('/settings/couriers/locations/sync', ['provider' => 'carrybee'])
        ->assertSessionHasErrors('sync');

    expect(CourierLocationSync::count())->toBe(0);
    Http::assertNothingSent();
});

it('applies only equal names, folds spelling variants, and leaves contained names as suggestions', function () {
    locFakeCarryBee();
    locSync(locActiveAccount());

    // Equal names, including renamed-city spellings on both sides.
    expect(locMap('carrybee', 'Dhaka', 'Dhanmondi'))->status->toBe('applied')->confidence->toBe('high')->source->toBe('auto_exact')->provider_zone_id->toBe('101')
        ->and(locMap('carrybee', 'Gazipur', 'Kaliakair'))->status->toBe('applied')
        ->and(locMap('carrybee', 'Dhaka', 'Dhaka Cantt.'))->status->toBe('applied')->provider_zone_id->toBe('106')
        // BdGeo "Chittagong Sadar" in district Chattogram, courier "Chattogram Sadar" in city "Chittagong".
        ->and(locMap('carrybee', 'Chattogram', 'Chittagong Sadar'))->status->toBe('applied')->provider_city_id->toBe('8')->provider_zone_id->toBe('801')
        // BdGeo "Bogra Sadar" in Bogura, courier "Bogura Sadar" in "Bogra".
        ->and(locMap('carrybee', 'Bogura', 'Bogra Sadar'))->status->toBe('applied')->provider_zone_id->toBe('601');

    // One zone that only contains the name: a good guess, still a guess.
    expect(locMap('carrybee', 'Dhaka', 'Savar'))->status->toBe('suggested')->confidence->toBe('medium')->provider_zone_id->toBe('104')
        ->and(locMap('carrybee', 'Dhaka', 'Keraniganj'))->status->toBe('suggested')->confidence->toBe('medium');

    // Two zones answer to Mirpur: no pick at all, both offered.
    $mirpur = locMap('carrybee', 'Dhaka', 'Mirpur');
    expect($mirpur->status)->toBe('suggested')->and($mirpur->confidence)->toBe('low')
        ->and($mirpur->provider_zone_id)->toBeNull()
        ->and(collect($mirpur->candidates)->pluck('name')->sort()->values()->all())->toBe(['Mirpur 1', 'Mirpur 10']);

    // Cumilla city, no zones: every Comilla thana is known to the city, unmatched.
    expect(locMap('carrybee', 'Comilla', 'Comilla Sadar'))->provider_city_id->toBe('12')->provider_zone_id->toBeNull()
        ->and(CourierLocationMap::where('provider', 'carrybee')->where('status', 'applied')->where('confidence', '!=', 'high')->count())->toBe(0);
});

it('never rewrites a map a person set when matching again', function () {
    locFakeCarryBee();
    locSync(locActiveAccount());

    $this->actingAs($this->owner)->put('/settings/couriers/locations/maps', [
        'provider' => 'carrybee', 'district' => 'Dhaka', 'thana' => 'Mirpur', 'city_id' => '14', 'zone_id' => '103',
    ])->assertSessionHasNoErrors();

    $counts = app(CourierLocationMatcher::class)->run('carrybee');

    expect($counts['kept'])->toBe(1)
        ->and(locMap('carrybee', 'Dhaka', 'Mirpur'))->provider_zone_id->toBe('103')->source->toBe('manual')->status->toBe('applied');
});

it('resolves only applied maps, with the band, and says why otherwise', function () {
    locFakeCarryBee();
    locSync(locActiveAccount());

    $ok = CourierDestination::resolve('carrybee', 'dhaka', 'dhanmondi');
    expect($ok)->toBeInstanceOf(ResolvedDestination::class)
        ->and($ok->cityId)->toBe('14')->and($ok->zoneId)->toBe('101')->and($ok->bandCode)->toBe('dhaka')
        ->and(CourierDestination::resolve('carrybee', 'Chittagong', 'Chittagong Sadar')->bandCode)->toBe('outside_dhaka');

    // Suggested, not applied.
    expect(CourierDestination::resolve('carrybee', 'Dhaka', 'Savar'))->toBeInstanceOf(Unresolved::class)
        ->reason->toBe(Unresolved::AWAITING_REVIEW);
    // Nothing synced for Pathao at all.
    expect(CourierDestination::resolve('pathao', 'Dhaka', 'Dhanmondi'))->reason->toBe(Unresolved::NOT_MAPPED);
    // Not a place on the list.
    expect(CourierDestination::resolve('carrybee', 'Dhaka', 'Atlantis'))->reason->toBe(Unresolved::UNKNOWN_PLACE);

    // The courier drops the zone: the map stops resolving.
    CourierLocation::where('provider_location_id', '101')->where('level', 'zone')->update(['removed_at' => now()]);
    expect(CourierDestination::resolve('carrybee', 'Dhaka', 'Dhanmondi'))->reason->toBe(Unresolved::LOCATION_REMOVED);
});

it('uses an area map over the thana map when the order names that area', function () {
    locFakeCarryBee();
    locSync(locActiveAccount());

    $this->actingAs($this->owner)->put('/settings/couriers/locations/maps', [
        'provider' => 'carrybee', 'district' => 'Dhaka', 'thana' => 'Dhanmondi', 'area' => 'Jigatala TSO',
        'city_id' => '14', 'zone_id' => '101', 'area_id' => '9001',
    ])->assertSessionHasNoErrors();

    expect(CourierDestination::resolve('carrybee', 'Dhaka', 'Dhanmondi', 'Jigatala TSO')->areaId)->toBe('9001')
        ->and(CourierDestination::resolve('carrybee', 'Dhaka', 'Dhanmondi')->areaId)->toBeNull();
});

it('syncs Pathao lists and refuses an area without home delivery', function () {
    Http::fake([
        LOC_PATHAO.'/aladdin/api/v1/issue-token' => Http::response(['token_type' => 'Bearer', 'expires_in' => 432000, 'access_token' => 'TOKEN-loc']),
        LOC_PATHAO.'/aladdin/api/v1/city-list' => Http::response(['code' => 200, 'data' => ['data' => [['city_id' => 1, 'city_name' => 'Dhaka']]]]),
        LOC_PATHAO.'/aladdin/api/v1/cities/1/zone-list' => Http::response(['code' => 200, 'data' => ['data' => [['zone_id' => 52, 'zone_name' => 'Dhanmondi'], ['zone_id' => 60, 'zone_name' => 'Gulshan 1']]]]),
        LOC_PATHAO.'/aladdin/api/v1/zones/52/area-list' => Http::response(['code' => 200, 'data' => ['data' => [
            ['area_id' => 7001, 'area_name' => 'Jigatola', 'home_delivery_available' => false, 'pickup_available' => true],
        ]]]),
        LOC_PATHAO.'/aladdin/api/v1/zones/60/area-list' => Http::response(['code' => 200, 'data' => ['data' => []]]),
    ]);

    $run = locSync(locActiveAccount('pathao'));

    expect($run->status)->toBe(CourierLocationSync::DONE)
        ->and(CourierLocation::where('provider', 'pathao')->where('level', 'area')->first()->extra)->toBe(['home_delivery_available' => false, 'pickup_available' => true])
        ->and(locMap('pathao', 'Dhaka', 'Dhanmondi')->status)->toBe('applied')
        ->and(CourierDestination::resolve('pathao', 'Dhaka', 'Dhanmondi')->bandCode)->toBe('inside_dhaka');

    $this->actingAs($this->owner)->put('/settings/couriers/locations/maps', [
        'provider' => 'pathao', 'district' => 'Dhaka', 'thana' => 'Dhanmondi', 'city_id' => '1', 'zone_id' => '52', 'area_id' => '7001',
    ])->assertSessionHasNoErrors();

    expect(CourierDestination::resolve('pathao', 'Dhaka', 'Dhanmondi'))->reason->toBe(Unresolved::NO_HOME_DELIVERY);
});

it('stamps manual and the reviewer when a suggestion is approved', function () {
    locFakeCarryBee();
    locSync(locActiveAccount());
    $savar = locMap('carrybee', 'Dhaka', 'Savar');

    $this->actingAs($this->owner)->post("/settings/couriers/locations/maps/{$savar->id}/approve")->assertSessionHasNoErrors();

    $savar->refresh();
    expect($savar->status)->toBe('applied')->and($savar->source)->toBe('manual')
        ->and($savar->reviewed_by)->toBe($this->owner->id)->and($savar->reviewed_at)->not->toBeNull()
        ->and(CourierDestination::resolve('carrybee', 'Dhaka', 'Savar'))->toBeInstanceOf(ResolvedDestination::class);

    // A low suggestion with no zone picked cannot be approved as it stands.
    $mirpur = locMap('carrybee', 'Dhaka', 'Mirpur');
    $this->actingAs($this->owner)->post("/settings/couriers/locations/maps/{$mirpur->id}/approve")->assertSessionHasErrors('map');
    expect($mirpur->fresh()->status)->toBe('suggested');
});

it('confirms every unreviewed equal-name match at once without touching suggestions', function () {
    locFakeCarryBee();
    locSync(locActiveAccount());

    $this->actingAs($this->owner)->post('/settings/couriers/locations/approve-high', ['provider' => 'carrybee'])->assertSessionHasNoErrors();

    expect(CourierLocationMap::where('provider', 'carrybee')->where('confidence', 'high')->whereNull('reviewed_at')->count())->toBe(0)
        ->and(locMap('carrybee', 'Dhaka', 'Dhanmondi')->reviewed_by)->toBe($this->owner->id)
        ->and(locMap('carrybee', 'Dhaka', 'Savar')->status)->toBe('suggested');
});

it('counts on Approve all high only what the button would confirm', function () {
    $base = ['provider' => 'carrybee', 'district' => 'Dhaka', 'provider_city_id' => '14', 'provider_city_name' => 'Dhaka', 'confidence' => 'high'];
    // Would be confirmed: a machine match with a zone, unseen.
    CourierLocationMap::create($base + ['thana' => 'Dhanmondi', 'provider_zone_id' => '101', 'provider_zone_name' => 'Dhanmondi', 'status' => 'applied', 'source' => 'auto_exact']);
    // Would not: typed by a person, or without a zone.
    CourierLocationMap::create($base + ['thana' => 'Mirpur', 'provider_zone_id' => '102', 'provider_zone_name' => 'Mirpur', 'status' => 'applied', 'source' => 'manual']);
    CourierLocationMap::create($base + ['thana' => 'Savar', 'status' => 'suggested', 'source' => 'auto_exact']);

    $this->actingAs($this->owner)->get('/settings/couriers/locations?provider=carrybee')
        ->assertInertia(fn ($page) => $page->where('stats.unreviewed_high', 1));

    $this->actingAs($this->owner)->post('/settings/couriers/locations/approve-high', ['provider' => 'carrybee'])
        ->assertSessionHas('success', '1 equal-name matches confirmed.');

    $this->actingAs($this->owner)->get('/settings/couriers/locations?provider=carrybee')
        ->assertInertia(fn ($page) => $page->where('stats.unreviewed_high', 0));
});

it('refuses a map to a zone outside the chosen city or a place not on the list', function () {
    locFakeCarryBee();
    locSync(locActiveAccount());

    // Kaliakair (Gazipur's zone) under city Dhaka.
    $this->actingAs($this->owner)->put('/settings/couriers/locations/maps', [
        'provider' => 'carrybee', 'district' => 'Dhaka', 'thana' => 'Mirpur', 'city_id' => '14', 'zone_id' => '201',
    ])->assertSessionHasErrors('zone_id');

    // An area of another zone.
    $this->actingAs($this->owner)->put('/settings/couriers/locations/maps', [
        'provider' => 'carrybee', 'district' => 'Dhaka', 'thana' => 'Mirpur', 'city_id' => '14', 'zone_id' => '102', 'area_id' => '9001',
    ])->assertSessionHasErrors('area_id');

    $this->actingAs($this->owner)->put('/settings/couriers/locations/maps', [
        'provider' => 'carrybee', 'district' => 'Dhaka', 'thana' => 'Atlantis', 'city_id' => '14', 'zone_id' => '102',
    ])->assertSessionHasErrors('thana');

    expect(locMap('carrybee', 'Dhaka', 'Mirpur')->status)->toBe('suggested');
});

it('seeds each courier\'s own bands and puts Keraniganj and Savar in Pathao suburbs only', function () {
    expect(CourierZoneBand::where('provider', 'pathao')->orderBy('sort_order')->pluck('code')->all())->toBe(['inside_dhaka', 'suburbs', 'outside_dhaka'])
        ->and(CourierZoneBand::where('provider', 'carrybee')->orderBy('sort_order')->pluck('code')->all())->toBe(['dhaka', 'gazipur_narayanganj', 'outside_dhaka'])
        ->and(CourierZoneBand::for('pathao', 'Dhaka', 'Keraniganj')->code)->toBe('suburbs')
        ->and(CourierZoneBand::for('pathao', 'Dhaka', 'Savar')->code)->toBe('suburbs')
        ->and(CourierZoneBand::for('pathao', 'Dhaka', 'Dhanmondi')->code)->toBe('inside_dhaka')
        ->and(CourierZoneBand::for('pathao', 'Gazipur', 'Kaliakair')->code)->toBe('suburbs')
        ->and(CourierZoneBand::for('pathao', 'Bagerhat', 'Mongla')->code)->toBe('outside_dhaka')
        ->and(CourierZoneBand::for('carrybee', 'Dhaka', 'Keraniganj')->code)->toBe('dhaka')
        ->and(CourierZoneBand::for('carrybee', 'Narayanganj', 'Bandar')->code)->toBe('gazipur_narayanganj');
});

it('lets the owner edit, add and remove bands, checked against the place list', function () {
    $suburbs = CourierZoneBand::where('provider', 'pathao')->where('code', 'suburbs')->first();

    $this->actingAs($this->owner)->put("/settings/couriers/locations/bands/{$suburbs->id}", [
        'name' => 'Suburbs', 'districts' => ['gazipur', 'Narayanganj', 'Narsingdi'], 'thanas' => ['Dhaka/Keraniganj', 'Dhaka/Savar', 'Dhaka/Dhamrai'],
    ])->assertSessionHasNoErrors();

    expect($suburbs->fresh()->districts)->toBe(['Gazipur', 'Narayanganj', 'Narsingdi'])
        ->and(CourierZoneBand::for('pathao', 'Dhaka', 'Dhamrai')->code)->toBe('suburbs');

    $this->actingAs($this->owner)->put("/settings/couriers/locations/bands/{$suburbs->id}", [
        'name' => 'Suburbs', 'districts' => ['Gotham'], 'thanas' => [],
    ])->assertSessionHasErrors('districts.0');

    $this->actingAs($this->owner)->post('/settings/couriers/locations/bands', [
        'provider' => 'pathao', 'name' => 'Same day metro', 'districts' => [], 'thanas' => ['Dhaka/Gulshan'],
    ])->assertSessionHasNoErrors();
    expect(CourierZoneBand::where('provider', 'pathao')->where('code', 'same_day_metro')->exists())->toBeTrue();

    $default = CourierZoneBand::where('provider', 'pathao')->where('is_default', true)->first();
    $this->actingAs($this->owner)->delete("/settings/couriers/locations/bands/{$default->id}")->assertSessionHasErrors('band');
    expect($default->fresh())->not->toBeNull();
});

it('keeps everyone without couriers.settings.manage out of the locations screen', function () {
    $band = CourierZoneBand::first();

    $this->actingAs($this->manager)->get('/settings/couriers/locations')->assertForbidden();
    $this->actingAs($this->manager)->post('/settings/couriers/locations/sync', ['provider' => 'carrybee'])->assertForbidden();
    $this->actingAs($this->manager)->post('/settings/couriers/locations/match', ['provider' => 'carrybee'])->assertForbidden();
    $this->actingAs($this->manager)->put('/settings/couriers/locations/maps', ['provider' => 'carrybee'])->assertForbidden();
    $this->actingAs($this->manager)->put("/settings/couriers/locations/bands/{$band->id}", ['name' => 'x'])->assertForbidden();
    $this->actingAs($this->manager)->get('/settings/couriers/locations/options?provider=carrybee&level=zone&parent=14')->assertForbidden();
});

it('shows the owner the counts, the review list and the bands', function () {
    locFakeCarryBee();
    locSync(locActiveAccount());

    $this->actingAs($this->owner)->get('/settings/couriers/locations?provider=carrybee')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/courier-locations')
            ->where('provider', 'carrybee')
            ->where('stats.synced.zone', 9)
            ->where('stats.mapped', 5)
            ->where('stats.review', 3)
            ->has('maps.data', 3)
            ->has('bands', 3)
            ->has('cities', 5));

    $this->actingAs($this->owner)->get('/settings/couriers/locations/options?provider=carrybee&level=zone&parent=14')
        ->assertOk()->assertJsonCount(6, 'items');

    $this->actingAs($this->owner)->get('/settings/couriers/locations/suggest?q=jigatola')
        ->assertOk()->assertJsonPath('items.0.area_id', '9001');
});

it('queues a sync from the console through an active account', function () {
    locFakeCarryBee();
    $account = locActiveAccount();

    $this->artisan('couriers:sync-locations', ['account' => $account->id])->assertSuccessful();

    expect(CourierLocationSync::first()->status)->toBe(CourierLocationSync::DONE)
        ->and(locMap('carrybee', 'Dhaka', 'Dhanmondi')->status)->toBe('applied');
});
