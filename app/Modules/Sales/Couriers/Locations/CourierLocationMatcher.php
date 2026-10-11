<?php

namespace App\Modules\Sales\Couriers\Locations;

use App\Modules\Sales\Couriers\Models\CourierLocation;
use App\Modules\Sales\Couriers\Models\CourierLocationMap;
use App\Support\BdGeo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Proposes, for every thana in Bangladesh, which of one courier's zones it is.
 *
 * ⭐ Ported in spirit from the LaLaDia shop's PathaoGeoMap: the district is
 * found first and the zone is only ever looked for INSIDE that city, so a zone
 * can never be paired with a city it does not belong to.
 *
 * ⚠⚠ ONLY `high` IS APPLIED, AND `high` MEANS EQUAL NAMES. Equal after
 * PlaceName::key() (case, punctuation, renamed-city spellings), and the only
 * zone in the city with that name. Everything else (a loose spelling, a
 * "Sadar" dropped, a zone that merely contains the thana's name, two zones
 * with the same name) is written as a suggestion and waits for a person.
 * Pathao splits Mirpur into twenty-odd zones; no rule can say which one an
 * order means, and that is exactly the guess the ERP must not make.
 *
 * ⚠ A map a person wrote or reviewed is never touched by a later run.
 * Areas are not matched automatically: an area map is added by hand.
 */
class CourierLocationMatcher
{
    private const MAX_CANDIDATES = 12;

    private const SCORE_EXACT = 4;

    private const SCORE_LOOSE = 3;

    private const SCORE_PREFIX = 2;

    private const SCORE_CONTAINED = 1;

    /**
     * @return array{applied:int, suggested:int, unmatched:int, kept:int}
     */
    public function run(string $provider): array
    {
        $cities = CourierLocation::query()->listed()
            ->where('provider', $provider)->where('level', CourierLocation::CITY)
            ->get(['provider_location_id', 'name']);

        $zonesByCity = CourierLocation::query()->listed()
            ->where('provider', $provider)->where('level', CourierLocation::ZONE)
            ->get(['provider_location_id', 'parent_provider_id', 'name'])
            ->groupBy('parent_provider_id');

        $existing = CourierLocationMap::query()
            ->where('provider', $provider)->where('area', CourierLocationMap::WHOLE_THANA)
            ->get()->keyBy(fn (CourierLocationMap $m) => $m->district.'|'.$m->thana);

        $counts = ['applied' => 0, 'suggested' => 0, 'unmatched' => 0, 'kept' => 0];

        DB::transaction(function () use ($provider, $cities, $zonesByCity, $existing, &$counts) {
            foreach (BdGeo::districts() as $district) {
                $city = $this->cityFor($district['name'], $cities);
                $zones = $city === null ? collect() : ($zonesByCity->get($city->provider_location_id) ?? collect());

                foreach (BdGeo::thanas($district['name']) as $thana) {
                    $map = $existing->get($district['name'].'|'.$thana['name']);

                    if ($map !== null && $map->isHumanOwned()) {
                        $counts['kept']++;

                        continue;
                    }

                    $proposal = $this->propose($city, $zones, $thana['name']);
                    $counts[match (true) {
                        $proposal['status'] === CourierLocationMap::APPLIED => 'applied',
                        $proposal['provider_zone_id'] !== null || $proposal['candidates'] !== null => 'suggested',
                        default => 'unmatched',
                    }]++;

                    $map ??= new CourierLocationMap([
                        'provider' => $provider,
                        'district' => $district['name'],
                        'thana' => $thana['name'],
                        'area' => CourierLocationMap::WHOLE_THANA,
                    ]);

                    $map->fill($proposal)->save();
                }
            }
        });

        return $counts;
    }

    /**
     * The courier's city for one BdGeo district. BdGeo resolves the courier's
     * spelling (aliases, then a loose key proven collision free across the 64
     * districts); PlaceName's variants catch the rest. Two cities answering to
     * one district is ambiguous, and ambiguity resolves to nothing.
     *
     * @param  Collection<int, CourierLocation>  $cities
     */
    public function cityFor(string $district, Collection $cities): ?CourierLocation
    {
        $matches = $cities->filter(function (CourierLocation $city) use ($district) {
            $standard = BdGeo::districtNamed($city->name)
                ?? BdGeo::districtNamed(PlaceName::phrase($city->name));

            return $standard === $district || PlaceName::key($city->name) === PlaceName::key($district);
        })->values();

        if ($matches->count() === 1) {
            return $matches->first();
        }

        // Several: only an exactly equal name may break the tie.
        $exact = $matches->filter(fn (CourierLocation $c) => PlaceName::key($c->name) === PlaceName::key($district))->values();

        return $exact->count() === 1 ? $exact->first() : null;
    }

    /**
     * What the map for one thana should say, from the zones of its city.
     *
     * @param  Collection<int, CourierLocation>  $zones
     * @return array<string, mixed>
     */
    public function propose(?CourierLocation $city, Collection $zones, string $thana): array
    {
        $base = [
            'provider_city_id' => $city?->provider_location_id,
            'provider_city_name' => $city?->name,
            'provider_zone_id' => null,
            'provider_zone_name' => null,
            'provider_area_id' => null,
            'provider_area_name' => null,
            'status' => CourierLocationMap::SUGGESTED,
            'source' => CourierLocationMap::AUTO_SUGGESTED,
            'confidence' => CourierLocationMap::LOW,
            // ⚠ NULL, never an empty JSON list, so "has candidates" is a plain
            // IS NOT NULL on every database.
            'candidates' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ];

        if ($city === null) {
            return [...$base, 'note' => 'The courier lists no city for this district.'];
        }

        $candidates = $this->candidates($zones, $thana);

        if ($candidates === []) {
            return [...$base, 'note' => 'No zone in '.$city->name.' carries this name. Pick one by hand.'];
        }

        $best = $candidates[0];
        $tied = count(array_filter($candidates, fn ($c) => $c['score'] === $best['score']));
        $shortlist = array_map(fn ($c) => ['id' => $c['id'], 'name' => $c['name']], array_slice($candidates, 0, self::MAX_CANDIDATES));

        if ($best['score'] === self::SCORE_EXACT && $tied === 1) {
            return [
                ...$base,
                'provider_zone_id' => $best['id'],
                'provider_zone_name' => $best['name'],
                'status' => CourierLocationMap::APPLIED,
                'source' => CourierLocationMap::AUTO_EXACT,
                'confidence' => CourierLocationMap::HIGH,
                'candidates' => count($candidates) > 1 ? $shortlist : null,
                'note' => 'The zone has the same name as the thana.',
            ];
        }

        // One clear best that is not an equal name, or the only zone that
        // contains the name: a good guess, still a guess.
        if ($tied === 1 && ($best['score'] >= self::SCORE_LOOSE || count($candidates) === 1)) {
            return [
                ...$base,
                'provider_zone_id' => $best['id'],
                'provider_zone_name' => $best['name'],
                'confidence' => CourierLocationMap::MEDIUM,
                'candidates' => $shortlist,
                'note' => $best['why'],
            ];
        }

        return [
            ...$base,
            'candidates' => $shortlist,
            'note' => $tied > 1 && $best['score'] === self::SCORE_EXACT
                ? 'Several zones in '.$city->name.' carry exactly this name. Pick the right one.'
                : 'The courier splits this thana into '.count($candidates).' zones. Pick the right one.',
        ];
    }

    /**
     * Every zone that answers to the thana's name, best first.
     *
     * @param  Collection<int, CourierLocation>  $zones
     * @return list<array{id:string,name:string,score:int,why:string}>
     */
    private function candidates(Collection $zones, string $thana): array
    {
        $key = PlaceName::key($thana);
        $phrase = PlaceName::phrase($thana);
        $loose = PlaceName::looseKey($thana);
        $noSadar = PlaceName::keyWithoutSadar($thana);

        if ($key === '') {
            return [];
        }

        $found = [];

        foreach ($zones as $zone) {
            $zoneKey = PlaceName::key($zone->name);
            $zonePhrase = PlaceName::phrase($zone->name);

            [$score, $why] = match (true) {
                $zoneKey === $key => [self::SCORE_EXACT, 'The zone has the same name as the thana.'],
                PlaceName::looseKey($zone->name) === $loose && strlen($loose) >= 3 => [self::SCORE_LOOSE, 'The names differ only in spelling.'],
                $noSadar !== $key && PlaceName::keyWithoutSadar($zone->name) === $noSadar => [self::SCORE_LOOSE, 'The names differ only by "Sadar".'],
                $noSadar === $key && PlaceName::keyWithoutSadar($zone->name) === $key => [self::SCORE_LOOSE, 'The names differ only by "Sadar".'],
                str_starts_with($zonePhrase.' ', $phrase.' ') => [self::SCORE_PREFIX, 'The zone name starts with the thana name.'],
                PlaceName::containsWords($zonePhrase, $phrase) => [self::SCORE_CONTAINED, 'The zone name contains the thana name.'],
                default => [0, ''],
            };

            if ($score > 0) {
                $found[] = ['id' => (string) $zone->provider_location_id, 'name' => (string) $zone->name, 'score' => $score, 'why' => $why];
            }
        }

        usort($found, fn ($a, $b) => [$b['score'], strlen($a['name']), $a['name']] <=> [$a['score'], strlen($b['name']), $b['name']]);

        return $found;
    }
}
