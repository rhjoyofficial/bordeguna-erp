<?php

namespace App\Modules\Sales\Couriers\Drivers;

use App\Modules\Sales\Couriers\Models\CourierAccount;
use Illuminate\Support\Facades\Http;

/**
 * CarryBee API v2: three headers on every request, no token exchange.
 * Read-only in slice 1: GET /api/v2/stores and GET /api/v2/cities.
 */
class CarryBeeDriver implements CourierDriver
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

    private function get(CourierAccount $account, string $path): CourierCall
    {
        return $this->api->call($account, 'GET', $path, fn (string $base) => Http::withHeaders([
            'Client-ID' => (string) $account->credential('client_id'),
            'Client-Secret' => (string) $account->credential('client_secret'),
            'Client-Context' => (string) $account->credential('client_context'),
        ])->acceptJson()->timeout(CourierApi::TIMEOUT_SECONDS)->get($base.$path));
    }
}
