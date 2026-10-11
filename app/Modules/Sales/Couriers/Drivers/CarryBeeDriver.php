<?php

namespace App\Modules\Sales\Couriers\Drivers;

use App\Modules\Sales\Couriers\Models\CourierAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * CarryBee API v2: three headers on every request, no token exchange.
 * Read-only in slice 1: GET /api/v2/stores and GET /api/v2/cities.
 * Slice 2 adds the coverage lists, the area suggestion and the address
 * resolver, all read-only (POST address-details changes nothing at CarryBee).
 */
class CarryBeeDriver implements CourierDriver, ListsLocations
{
    public function __construct(private readonly CourierApi $api) {}

    public function testConnection(CourierAccount $account): ConnectionResult
    {
        if (! $account->hasAllRequiredCredentials()) {
            return ConnectionResult::failure('Enter the client ID, client secret and client context first.');
        }

        $stores = $this->get($account, '/api/v2/stores');

        if (! $stores->ok()) {
            return ConnectionResult::failure((string) $stores->error);
        }

        $cities = $this->get($account, '/api/v2/cities');

        if (! $cities->ok()) {
            return ConnectionResult::failure('The stores answered but the city list did not: '.$cities->error);
        }

        $list = [];

        foreach ((array) data_get($stores->json(), 'data.stores', []) as $store) {
            if (! is_array($store) || ! isset($store['id'])) {
                continue;
            }

            $list[] = [
                'id' => (string) $store['id'],
                'name' => (string) ($store['name'] ?? $store['id']),
                'active' => (bool) ($store['is_active'] ?? false),
            ];
        }

        return ConnectionResult::success($list, count((array) data_get($cities->json(), 'data.cities', [])));
    }

    public function cities(CourierAccount $account): LocationList
    {
        return $this->list($account, '/api/v2/cities', 'data.cities');
    }

    public function zones(CourierAccount $account, string $cityId): LocationList
    {
        return $this->list($account, '/api/v2/cities/'.rawurlencode($cityId).'/zones', 'data.zones');
    }

    public function areas(CourierAccount $account, string $cityId, string $zoneId): LocationList
    {
        return $this->list($account, '/api/v2/cities/'.rawurlencode($cityId).'/zones/'.rawurlencode($zoneId).'/areas', 'data.areas');
    }

    /**
     * CarryBee's own guess at a place from a few letters (3 or more). A HINT for
     * the person reviewing a map, never an answer the ERP books on.
     *
     * @return array{0: list<array{city_id:string,city_name:string,zone_id:string,zone_name:string,area_id:string,area_name:string}>, 1: ?string}
     */
    public function suggestAreas(CourierAccount $account, string $search): array
    {
        $search = trim($search);

        if (mb_strlen($search) < 3) {
            return [[], 'Type at least 3 letters.'];
        }

        // ⚠ The search text rides in the query string, which CourierApi never
        // logs (it keeps the path only): it may be part of a customer address.
        $call = $this->get($account, '/api/v2/area-suggestion', ['search' => $search]);

        if (! $call->ok()) {
            return [[], $call->error];
        }

        $json = $call->json();
        $rows = data_get($json, 'data.items', data_get($json, 'items', data_get($json, 'data', [])));
        $items = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row) || ! isset($row['city_id'], $row['zone_id'])) {
                continue;
            }

            $items[] = [
                'city_id' => (string) $row['city_id'],
                'city_name' => (string) ($row['city_name'] ?? ''),
                'zone_id' => (string) $row['zone_id'],
                'zone_name' => (string) ($row['zone_name'] ?? ''),
                'area_id' => isset($row['area_id']) ? (string) $row['area_id'] : '',
                'area_name' => (string) ($row['area_name'] ?? ''),
            ];
        }

        return [$items, null];
    }

    /**
     * CarryBee's reading of a full address (10 characters or more) into a city
     * and a zone. Also only a hint.
     *
     * @return array{0: ?array{city_id:string,zone_id:string}, 1: ?string}
     */
    public function addressDetails(CourierAccount $account, string $query): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 10) {
            return [null, 'Give at least 10 characters of the address.'];
        }

        $path = '/api/v2/address-details';
        $call = $this->api->call($account, 'POST', $path, fn (string $base) => $this->client($account)->asJson()->post($base.$path, ['query' => $query]));

        if (! $call->ok()) {
            return [null, $call->error];
        }

        $data = data_get($call->json(), 'data', []);

        if (! is_array($data) || ! isset($data['city_id'], $data['zone_id'])) {
            return [null, 'CarryBee could not read a city and zone from that address.'];
        }

        return [['city_id' => (string) $data['city_id'], 'zone_id' => (string) $data['zone_id']], null];
    }

    private function list(CourierAccount $account, string $path, string $key): LocationList
    {
        $call = $this->get($account, $path);

        if (! $call->ok()) {
            return LocationList::failed((string) $call->error);
        }

        $rows = data_get($call->json(), $key);

        if (! is_array($rows)) {
            return LocationList::failed('CarryBee answered '.$path.' without a list.');
        }

        return LocationList::from($rows, 'id', 'name');
    }

    /**
     * @param  array<string,string>  $query
     */
    private function get(CourierAccount $account, string $path, array $query = []): CourierCall
    {
        return $this->api->call($account, 'GET', $path, fn (string $base) => $this->client($account)->get($base.$path, $query));
    }

    private function client(CourierAccount $account): PendingRequest
    {
        return Http::withHeaders([
            'Client-ID' => (string) $account->credential('client_id'),
            'Client-Secret' => (string) $account->credential('client_secret'),
            'Client-Context' => (string) $account->credential('client_context'),
        ])->acceptJson()->timeout(CourierApi::TIMEOUT_SECONDS);
    }
}
