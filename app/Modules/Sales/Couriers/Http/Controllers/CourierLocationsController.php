<?php

namespace App\Modules\Sales\Couriers\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Couriers\Drivers\CarryBeeDriver;
use App\Modules\Sales\Couriers\Drivers\ListsLocations;
use App\Modules\Sales\Couriers\Locations\CourierLocationMatcher;
use App\Modules\Sales\Couriers\Locations\LocationSync;
use App\Modules\Sales\Couriers\Locations\SyncCourierLocations;
use App\Modules\Sales\Couriers\Models\CourierAccount;
use App\Modules\Sales\Couriers\Models\CourierLocation;
use App\Modules\Sales\Couriers\Models\CourierLocationMap;
use App\Modules\Sales\Couriers\Models\CourierLocationSync;
use App\Modules\Sales\Couriers\Models\CourierZoneBand;
use App\Modules\Sales\Couriers\Providers\CourierProviderDefinition;
use App\Modules\Sales\Couriers\Providers\CourierProviderRegistry;
use App\Support\BdGeo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings > Couriers > Locations (courier hub slice 2). Which of each
 * courier's zones every thana is, with a person approving anything that is
 * not an equal name, and each courier's own price bands.
 *
 * ⚠⚠ THE ERP NEVER BOOKS TO A GUESSED DESTINATION. Everything a person saves
 * here is checked against the courier's own synced list: the zone must belong
 * to the city and the area to the zone, so a form post cannot pair Mirpur 10
 * with Bagerhat whatever it sends.
 *
 * ⚠ Same gate as the rest of courier settings (`couriers.settings.manage`).
 * Provider-wide data: no business scope, by design.
 */
class CourierLocationsController extends Controller
{
    private const FILTERS = ['review', 'unmapped', 'mapped', 'all'];

    public function index(Request $request, CourierProviderRegistry $registry): Response
    {
        $providers = $this->listingProviders($registry);
        $provider = in_array($request->query('provider'), array_keys($providers), true) ? (string) $request->query('provider') : (string) array_key_first($providers);
        $filter = in_array($request->query('filter'), self::FILTERS, true) ? (string) $request->query('filter') : 'review';
        $search = trim((string) $request->query('q', ''));

        $maps = $this->filtered(CourierLocationMap::query()->where('provider', $provider), $filter)
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('district', 'like', "%{$search}%")
                ->orWhere('thana', 'like', "%{$search}%")
                ->orWhere('area', 'like', "%{$search}%")
                ->orWhere('provider_zone_name', 'like', "%{$search}%")))
            ->with('reviewer:id,name')
            ->orderBy('district')->orderBy('thana')->orderBy('area')
            ->paginate(50)->withQueryString()
            ->through(fn (CourierLocationMap $m) => [
                'id' => $m->id,
                'district' => $m->district,
                'thana' => $m->thana,
                'area' => $m->area === '' ? null : $m->area,
                'city_id' => $m->provider_city_id,
                'city_name' => $m->provider_city_name,
                'zone_id' => $m->provider_zone_id,
                'zone_name' => $m->provider_zone_name,
                'area_id' => $m->provider_area_id,
                'area_name' => $m->provider_area_name,
                'status' => $m->status,
                'source' => $m->source,
                'confidence' => $m->confidence,
                'candidates' => array_values($m->candidates ?? []),
                'note' => $m->note,
                'reviewed_by' => $m->reviewer?->name,
                'reviewed_at' => $m->reviewed_at?->toDateTimeString(),
            ]);

        $lastSync = CourierLocationSync::query()->where('provider', $provider)->latest('id')->first();

        return Inertia::render('settings/courier-locations', [
            'providers' => array_values(array_map(fn (array $p) => $p, $providers)),
            'provider' => $provider,
            'filter' => $filter,
            'search' => $search,
            'stats' => $this->stats($provider),
            'lastSync' => $lastSync === null ? null : [
                'id' => $lastSync->id,
                'status' => $lastSync->status,
                'cities' => $lastSync->cities,
                'zones' => $lastSync->zones,
                'areas' => $lastSync->areas,
                'calls' => $lastSync->calls,
                'last_error' => $lastSync->last_error,
                'started_at' => $lastSync->started_at?->toDateTimeString(),
                'finished_at' => $lastSync->finished_at?->toDateTimeString(),
            ],
            'maps' => $maps,
            'cities' => CourierLocation::query()->listed()->where('provider', $provider)->where('level', CourierLocation::CITY)
                ->orderBy('name')->get(['provider_location_id', 'name'])
                ->map(fn (CourierLocation $c) => ['id' => $c->provider_location_id, 'name' => $c->name])->values(),
            'bands' => CourierZoneBand::query()->where('provider', $provider)->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (CourierZoneBand $b) => [
                    'id' => $b->id,
                    'code' => $b->code,
                    'name' => $b->name,
                    'districts' => array_values($b->districts ?? []),
                    'thanas' => array_values($b->thanas ?? []),
                    'is_default' => $b->is_default,
                ])->values(),
            'districts' => array_column(BdGeo::districts(), 'name'),
        ]);
    }

    /** The zones of a city, or the areas of a zone, for the picker. */
    public function options(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', 'max:40'],
            'level' => ['required', Rule::in([CourierLocation::ZONE, CourierLocation::AREA])],
            'parent' => ['required', 'string', 'max:40'],
        ]);

        $rows = CourierLocation::query()->listed()
            ->where('provider', $data['provider'])->where('level', $data['level'])->where('parent_provider_id', $data['parent'])
            ->orderBy('name')->get(['provider_location_id', 'name', 'extra'])
            ->map(fn (CourierLocation $l) => [
                'id' => $l->provider_location_id,
                'name' => $l->name,
                'home_delivery' => $l->extra['home_delivery_available'] ?? null,
            ])->values();

        return response()->json(['items' => $rows]);
    }

    /**
     * CarryBee's own area suggestion for a few letters, as a HINT beside the
     * picker. Read-only, through an active CarryBee account.
     */
    public function suggest(Request $request, CarryBeeDriver $driver): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:3', 'max:100']]);

        $account = $this->activeAccount('carrybee');

        if ($account === null) {
            return response()->json(['items' => [], 'error' => 'No active CarryBee account to ask.']);
        }

        [$items, $error] = $driver->suggestAreas($account, $data['q']);

        return response()->json(['items' => $items, 'error' => $error]);
    }

    public function sync(Request $request, CourierProviderRegistry $registry, LocationSync $sync): RedirectResponse
    {
        $provider = $this->providerFrom($request, $registry);
        $account = $this->activeAccount($provider);

        if ($account === null) {
            throw ValidationException::withMessages(['sync' => 'No active '.$registry->get($provider)->name().' account. Add one, test it and activate it on Settings > Couriers first.']);
        }

        $run = $sync->start($account, $request->user());
        SyncCourierLocations::dispatch($run->id);

        $run->refresh();

        return back()->with('success', $run->status === CourierLocationSync::DONE
            ? sprintf('Synced %d cities, %d zones and %d areas, and matched them to the thanas.', $run->cities, $run->zones, $run->areas)
            : ($run->status === CourierLocationSync::FAILED
                ? 'The sync stopped: '.$run->last_error.' Press Sync now again to carry on from there.'
                : 'Sync started. It reads the lists slowly so the courier is not flooded; this page shows the progress.'));
    }

    public function match(Request $request, CourierProviderRegistry $registry, CourierLocationMatcher $matcher): RedirectResponse
    {
        $provider = $this->providerFrom($request, $registry);
        $counts = $matcher->run($provider);

        return back()->with('success', sprintf(
            'Matched again: %d applied by equal name, %d suggestions to review, %d with no match. %d maps a person set were left alone.',
            $counts['applied'], $counts['suggested'], $counts['unmatched'], $counts['kept'],
        ));
    }

    /** A person accepts the map as it stands. */
    public function approve(Request $request, CourierLocationMap $map): RedirectResponse
    {
        if ($map->provider_city_id === null || $map->provider_zone_id === null) {
            throw ValidationException::withMessages(['map' => "{$map->thana}, {$map->district} has no zone picked yet. Pick one, then save."]);
        }

        $this->checkContainment($map->provider, $map->provider_city_id, $map->provider_zone_id, $map->provider_area_id);

        $map->forceFill([
            'status' => CourierLocationMap::APPLIED,
            'source' => CourierLocationMap::MANUAL,
            'confidence' => CourierLocationMap::HIGH,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ])->save();

        return back()->with('success', "{$map->thana}, {$map->district} approved: {$map->provider_zone_name}.");
    }

    /**
     * Confirms every map that matched by equal name and that nobody has looked
     * at yet. They already resolve; this records that a person agreed.
     */
    public function approveHigh(Request $request, CourierProviderRegistry $registry): RedirectResponse
    {
        $provider = $this->providerFrom($request, $registry);

        $count = $this->unreviewedHigh($provider)
            ->get()
            ->each(fn (CourierLocationMap $m) => $m->forceFill([
                'status' => CourierLocationMap::APPLIED,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ])->save())
            ->count();

        return back()->with('success', $count === 0 ? 'No unreviewed equal-name matches left.' : "{$count} equal-name matches confirmed.");
    }

    /**
     * Writes any map: a thana, or one area inside it. Always checked against
     * the courier's list, always applied, always stamped with who did it.
     */
    public function save(Request $request, CourierProviderRegistry $registry): RedirectResponse
    {
        $provider = $this->providerFrom($request, $registry);
        $data = $request->validate([
            'district' => ['required', 'string', 'max:80'],
            'thana' => ['required', 'string', 'max:120'],
            'area' => ['nullable', 'string', 'max:150'],
            'city_id' => ['required', 'string', 'max:40'],
            'zone_id' => ['required', 'string', 'max:40'],
            'area_id' => ['nullable', 'string', 'max:40'],
            'source' => ['nullable', Rule::in([CourierLocationMap::MANUAL, CourierLocationMap::API_SUGGESTION])],
        ]);

        $district = BdGeo::districtNamed($data['district']);
        $thana = $district === null ? null : BdGeo::thanaNamed($district, $data['thana']);

        if ($thana === null) {
            throw ValidationException::withMessages(['thana' => 'That district and thana are not on the ERP list.']);
        }

        $area = '';

        if (filled($data['area'] ?? null)) {
            $area = BdGeo::areaNamed($district, $thana, $data['area']) ?? throw ValidationException::withMessages(['area' => 'That area is not in '.$thana.' on the ERP list.']);
        }

        [$city, $zone, $areaRow] = $this->checkContainment($provider, $data['city_id'], $data['zone_id'], $data['area_id'] ?? null);

        $map = CourierLocationMap::query()->firstOrNew(['provider' => $provider, 'district' => $district, 'thana' => $thana, 'area' => $area]);
        $map->fill([
            'provider_city_id' => $city->provider_location_id,
            'provider_city_name' => $city->name,
            'provider_zone_id' => $zone->provider_location_id,
            'provider_zone_name' => $zone->name,
            'provider_area_id' => $areaRow?->provider_location_id,
            'provider_area_name' => $areaRow?->name,
            'status' => CourierLocationMap::APPLIED,
            'source' => $data['source'] ?? CourierLocationMap::MANUAL,
            'confidence' => CourierLocationMap::HIGH,
            'note' => null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ])->save();

        $where = $area === '' ? "{$thana}, {$district}" : "{$area}, {$thana}, {$district}";

        return back()->with('success', "{$where} now goes to {$zone->name}".($areaRow ? " / {$areaRow->name}" : '').'.');
    }

    public function storeBand(Request $request, CourierProviderRegistry $registry): RedirectResponse
    {
        $provider = $this->providerFrom($request, $registry);
        $data = $this->validBand($request);
        $code = Str::slug($data['name'], '_');

        if ($code === '' || CourierZoneBand::query()->where('provider', $provider)->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['name' => 'This courier already has a band by that name.']);
        }

        CourierZoneBand::create([
            'provider' => $provider,
            'code' => $code,
            'name' => $data['name'],
            'districts' => $data['districts'],
            'thanas' => $data['thanas'],
            'is_default' => false,
            'sort_order' => (int) CourierZoneBand::query()->where('provider', $provider)->where('is_default', false)->max('sort_order') + 5,
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('success', "Band {$data['name']} added.");
    }

    public function updateBand(Request $request, CourierZoneBand $band): RedirectResponse
    {
        $data = $this->validBand($request, $band);

        $band->fill([
            'name' => $data['name'],
            // ⚠ The default band is "everywhere else": rules on it would mean
            // nothing, so it keeps none.
            'districts' => $band->is_default ? [] : $data['districts'],
            'thanas' => $band->is_default ? [] : $data['thanas'],
            'updated_by' => $request->user()->id,
        ])->save();

        return back()->with('success', "Band {$band->name} saved.");
    }

    public function destroyBand(CourierZoneBand $band): RedirectResponse
    {
        if ($band->is_default) {
            throw ValidationException::withMessages(['band' => 'The band for everywhere else cannot be removed; rename it instead.']);
        }

        $band->delete();

        return back()->with('success', "Band {$band->name} removed. Its places fall back to the next rule.");
    }

    /**
     * @return array{name:string, districts:list<string>, thanas:list<string>}
     */
    private function validBand(Request $request, ?CourierZoneBand $band = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'districts' => ['array'],
            'districts.*' => ['string', 'max:80'],
            'thanas' => ['array'],
            'thanas.*' => ['string', 'max:200'],
        ]);

        $districts = [];

        foreach ($data['districts'] ?? [] as $i => $name) {
            $districts[] = BdGeo::districtNamed($name) ?? throw ValidationException::withMessages(["districts.{$i}" => "\"{$name}\" is not a district on the ERP list."]);
        }

        $thanas = [];

        foreach ($data['thanas'] ?? [] as $i => $entry) {
            [$d, $t] = array_pad(array_map('trim', explode('/', (string) $entry, 2)), 2, '');
            $district = BdGeo::districtNamed($d);
            $thana = $district === null ? null : BdGeo::thanaNamed($district, $t);

            if ($thana === null) {
                throw ValidationException::withMessages(["thanas.{$i}" => "\"{$entry}\" is not a District/Thana on the ERP list."]);
            }

            $thanas[] = "{$district}/{$thana}";
        }

        return ['name' => trim($data['name']), 'districts' => array_values(array_unique($districts)), 'thanas' => array_values(array_unique($thanas))];
    }

    /**
     * The city, zone and (optional) area rows, proven to nest inside each other
     * on the courier's current list.
     *
     * @return array{0: CourierLocation, 1: CourierLocation, 2: ?CourierLocation}
     */
    private function checkContainment(string $provider, string $cityId, string $zoneId, ?string $areaId): array
    {
        $city = CourierLocation::lookup($provider, CourierLocation::CITY, $cityId);
        $zone = CourierLocation::lookup($provider, CourierLocation::ZONE, $zoneId);
        $area = filled($areaId) ? CourierLocation::lookup($provider, CourierLocation::AREA, $areaId) : null;

        if ($city === null || $city->removed_at !== null) {
            throw ValidationException::withMessages(['city_id' => 'That city is not on the courier\'s current list. Sync first.']);
        }

        if ($zone === null || $zone->removed_at !== null || $zone->parent_provider_id !== $city->provider_location_id) {
            throw ValidationException::withMessages(['zone_id' => "That zone is not in {$city->name} on the courier's current list."]);
        }

        if (filled($areaId) && ($area === null || $area->removed_at !== null || $area->parent_provider_id !== $zone->provider_location_id)) {
            throw ValidationException::withMessages(['area_id' => "That area is not in {$zone->name} on the courier's current list."]);
        }

        return [$city, $zone, $area];
    }

    private function filtered(Builder $query, string $filter): Builder
    {
        return match ($filter) {
            'mapped' => $query->where('status', CourierLocationMap::APPLIED),
            'review' => $query->where('status', CourierLocationMap::SUGGESTED)
                ->where(fn (Builder $q) => $q->whereNotNull('provider_zone_id')->orWhereNotNull('candidates')),
            'unmapped' => $query->where('status', CourierLocationMap::SUGGESTED)->whereNull('provider_zone_id')
                ->whereNull('candidates'),
            default => $query,
        };
    }

    /**
     * What "Approve all high" confirms, and so what its count says: equal-name
     * matches the system made, with a zone, that nobody has looked at. A map a
     * person typed is already theirs (QA walk, 11 Oct 2026: the count once
     * included those and the button then confirmed fewer than it promised).
     *
     * @return Builder<CourierLocationMap>
     */
    private function unreviewedHigh(string $provider): Builder
    {
        return CourierLocationMap::query()->where('provider', $provider)
            ->where('confidence', CourierLocationMap::HIGH)
            ->where('source', '!=', CourierLocationMap::MANUAL)
            ->whereNull('reviewed_at')
            ->whereNotNull('provider_zone_id');
    }

    /**
     * @return array<string, int|array<string,int>>
     */
    private function stats(string $provider): array
    {
        $synced = CourierLocation::query()->listed()->where('provider', $provider)
            ->selectRaw('level, count(*) as n')->groupBy('level')->pluck('n', 'level');

        $thanaMaps = CourierLocationMap::query()->where('provider', $provider)->where('area', CourierLocationMap::WHOLE_THANA);
        $totalThanas = array_sum(array_map(fn ($d) => count(BdGeo::thanas($d['name'])), BdGeo::districts()));
        $mapped = (clone $thanaMaps)->where('status', CourierLocationMap::APPLIED)->count();
        $review = $this->filtered(clone $thanaMaps, 'review')->count();

        return [
            'synced' => [
                'city' => (int) ($synced[CourierLocation::CITY] ?? 0),
                'zone' => (int) ($synced[CourierLocation::ZONE] ?? 0),
                'area' => (int) ($synced[CourierLocation::AREA] ?? 0),
            ],
            'thanas' => $totalThanas,
            'mapped' => $mapped,
            'review' => $review,
            'unmapped' => max(0, $totalThanas - $mapped - $review),
            'unreviewed_high' => $this->unreviewedHigh($provider)->count(),
            'area_maps' => CourierLocationMap::query()->where('provider', $provider)->where('area', '!=', CourierLocationMap::WHOLE_THANA)->count(),
        ];
    }

    /**
     * Couriers whose driver can list locations, with their active accounts.
     *
     * @return array<string, array{code:string,name:string,accounts:list<array{id:int,label:string}>}>
     */
    private function listingProviders(CourierProviderRegistry $registry): array
    {
        $out = [];

        foreach ($registry->all() as $code => $definition) {
            if (! $this->canList($definition)) {
                continue;
            }

            $out[$code] = [
                'code' => $code,
                'name' => $definition->name(),
                'accounts' => CourierAccount::query()->where('is_active', true)
                    ->whereHas('provider', fn (Builder $q) => $q->where('code', $code))
                    ->orderBy('label')->get(['id', 'label'])
                    ->map(fn (CourierAccount $a) => ['id' => $a->id, 'label' => $a->label])->values()->all(),
            ];
        }

        return $out;
    }

    private function canList(CourierProviderDefinition $definition): bool
    {
        $class = $definition->driverClass();

        return $class !== null && is_subclass_of($class, ListsLocations::class);
    }

    private function providerFrom(Request $request, CourierProviderRegistry $registry): string
    {
        $code = (string) $request->input('provider', '');

        if (! $registry->has($code) || ! $this->canList($registry->get($code))) {
            throw ValidationException::withMessages(['provider' => 'Choose a courier whose locations the ERP can read.']);
        }

        return $code;
    }

    private function activeAccount(string $provider): ?CourierAccount
    {
        return CourierAccount::query()->with('provider')->where('is_active', true)
            ->whereHas('provider', fn (Builder $q) => $q->where('code', $provider))
            ->orderBy('id')->get()
            // ⚠ The flag alone is not trusted: the test must still be current.
            ->first(fn (CourierAccount $a) => $a->testIsCurrent());
    }
}
