<?php

namespace App\Modules\Sales\Couriers\Drivers;

use App\Modules\Sales\Couriers\Models\CourierAccount;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * Pathao merchant API (aladdin). OAuth 2.0 password grant, then Bearer.
 * Read-only in slice 1: issue-token, stores, city-list. Slice 2 adds the
 * coverage lists: city-list, zone-list, area-list (each area says whether
 * home delivery and pickup are available there).
 *
 * ⚠ The token is cached PER ACCOUNT AND ENVIRONMENT, encrypted, until shortly
 * before it expires (Pathao gives 5 days and asks for it to be kept). The key
 * also carries part of the credentials fingerprint, so a new password never
 * rides on a token the old one earned. One global token (the shop's way) would
 * hand one merchant's token to another account.
 */
class PathaoDriver implements CourierDriver, ListsLocations
{
    private const TOKEN_PATH = '/aladdin/api/v1/issue-token';

    public function __construct(private readonly CourierApi $api) {}

    public function testConnection(CourierAccount $account): ConnectionResult
    {
        if (! $account->hasAllRequiredCredentials()) {
            return ConnectionResult::failure('Enter the client ID, client secret, merchant login email and password first.');
        }

        $stores = $this->authorisedGet($account, '/aladdin/api/v1/stores');

        if (! $stores->ok()) {
            return ConnectionResult::failure((string) $stores->error);
        }

        $cities = $this->authorisedGet($account, '/aladdin/api/v1/city-list');

        if (! $cities->ok()) {
            return ConnectionResult::failure('The stores answered but the city list did not: '.$cities->error);
        }

        $list = [];

        foreach (self::rows($stores->json()) as $store) {
            if (! is_array($store) || ! isset($store['store_id'])) {
                continue;
            }

            $list[] = [
                'id' => (string) $store['store_id'],
                'name' => (string) ($store['store_name'] ?? $store['store_id']),
                'active' => (bool) ($store['is_active'] ?? false),
            ];
        }

        return ConnectionResult::success($list, count(self::rows($cities->json())));
    }

    public function cities(CourierAccount $account): LocationList
    {
        return $this->list($account, '/aladdin/api/v1/city-list', 'city_id', 'city_name');
    }

    public function zones(CourierAccount $account, string $cityId): LocationList
    {
        return $this->list($account, '/aladdin/api/v1/cities/'.rawurlencode($cityId).'/zone-list', 'zone_id', 'zone_name');
    }

    public function areas(CourierAccount $account, string $cityId, string $zoneId): LocationList
    {
        return $this->list($account, '/aladdin/api/v1/zones/'.rawurlencode($zoneId).'/area-list', 'area_id', 'area_name');
    }

    private function list(CourierAccount $account, string $path, string $idKey, string $nameKey): LocationList
    {
        $call = $this->authorisedGet($account, $path);

        if (! $call->ok()) {
            return LocationList::failed((string) $call->error);
        }

        $json = $call->json();

        if (! array_key_exists('data', $json)) {
            return LocationList::failed('Pathao answered '.$path.' without a list.');
        }

        return LocationList::from(self::rows($json), $idKey, $nameKey);
    }

    public static function tokenCacheKey(CourierAccount $account): string
    {
        return 'couriers:pathao:token:'.$account->id.':'.$account->environment.':'.substr($account->currentFingerprint(), 0, 16);
    }

    /**
     * A cached token, or a fresh one. Null with the reason when Pathao refused.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function token(CourierAccount $account): array
    {
        $key = self::tokenCacheKey($account);
        $cached = Cache::get($key);

        if (is_string($cached)) {
            try {
                return [Crypt::decryptString($cached), null];
            } catch (DecryptException) {
                Cache::forget($key);
            }
        }

        $call = $this->api->call($account, 'POST', self::TOKEN_PATH, fn (string $base) => Http::acceptJson()
            ->timeout(CourierApi::TIMEOUT_SECONDS)
            ->post($base.self::TOKEN_PATH, [
                'client_id' => (string) $account->credential('client_id'),
                'client_secret' => (string) $account->credential('client_secret'),
                'grant_type' => 'password',
                'username' => (string) $account->credential('username'),
                'password' => (string) $account->credential('password'),
            ]));

        $token = $call->json()['access_token'] ?? null;

        if (! $call->ok() || ! is_string($token) || $token === '') {
            return [null, $call->error ?? 'Pathao answered without a token.'];
        }

        $expiresIn = (int) ($call->json()['expires_in'] ?? 3600);
        // Renew a little early so a call never carries a token that dies mid-way.
        $ttl = max(60, $expiresIn - 300);

        Cache::put($key, Crypt::encryptString($token), $ttl);

        return [$token, null];
    }

    private function authorisedGet(CourierAccount $account, string $path, bool $retried = false): CourierCall
    {
        [$token, $error] = $this->token($account);

        if ($token === null) {
            return new CourierCall(null, 'Pathao refused to sign in: '.$error);
        }

        $call = $this->api->call($account, 'GET', $path, fn (string $base) => Http::withToken($token)
            ->acceptJson()->timeout(CourierApi::TIMEOUT_SECONDS)->get($base.$path));

        // A token revoked on Pathao's side: forget it and sign in once more.
        if (! $retried && $call->response?->status() === 401) {
            Cache::forget(self::tokenCacheKey($account));

            return $this->authorisedGet($account, $path, true);
        }

        return $call;
    }

    /**
     * Pathao lists come as data.data (paginated) or data (plain).
     *
     * @param  array<mixed>  $json
     * @return array<mixed>
     */
    private static function rows(array $json): array
    {
        $data = $json['data'] ?? [];

        if (is_array($data) && array_key_exists('data', $data) && is_array($data['data'])) {
            return $data['data'];
        }

        return is_array($data) && array_is_list($data) ? $data : [];
    }
}
