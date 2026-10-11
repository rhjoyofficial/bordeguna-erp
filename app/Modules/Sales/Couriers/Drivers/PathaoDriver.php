<?php

namespace App\Modules\Sales\Couriers\Drivers;

use App\Modules\Sales\Couriers\Booking\BookingResult;
use App\Modules\Sales\Couriers\Booking\CancelResult;
use App\Modules\Sales\Couriers\Booking\ParcelBooking;
use App\Modules\Sales\Couriers\Booking\ParcelDetails;
use App\Modules\Sales\Couriers\Models\CourierAccount;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * Pathao merchant API (aladdin). OAuth 2.0 password grant, then Bearer.
 * Read-only in slice 1: issue-token, stores, city-list. Slice 2 adds the
 * coverage lists: city-list, zone-list, area-list (each area says whether
 * home delivery and pickup are available there). Slice 4 books (POST orders),
 * asks (GET orders/{id}/info) and tries to cancel.
 *
 * ⚠⚠ NO LOOKUP BY OUR REFERENCE. Pathao documents none (ruling 13: do not
 * assume it), so a timed-out booking waits for a person who looks in the
 * Pathao panel. This class deliberately does not implement
 * LooksUpMerchantReference.
 *
 * ⚠ The token is cached PER ACCOUNT AND ENVIRONMENT, encrypted, until shortly
 * before it expires (Pathao gives 5 days and asks for it to be kept). The key
 * also carries part of the credentials fingerprint, so a new password never
 * rides on a token the old one earned. One global token (the shop's way) would
 * hand one merchant's token to another account.
 */
class PathaoDriver implements BooksParcels, CourierDriver, ListsLocations
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

    private function authorisedGet(CourierAccount $account, string $path): CourierCall
    {
        return $this->authorised($account, 'GET', $path, fn (PendingRequest $http, string $url) => $http->get($url));
    }

    /**
     * @param  Closure(PendingRequest, string): Response  $send
     */
    private function authorised(CourierAccount $account, string $method, string $path, Closure $send, bool $retried = false): CourierCall
    {
        [$token, $error] = $this->token($account);

        if ($token === null) {
            // ⚠ The request itself never left the ERP: nothing can have
            // happened at Pathao, so a booking stopped here simply failed.
            return new CourierCall(null, 'Pathao refused to sign in: '.$error, sent: false);
        }

        $call = $this->api->call($account, $method, $path, fn (string $base) => $send(
            Http::withToken($token)->acceptJson()->timeout(CourierApi::TIMEOUT_SECONDS),
            $base.$path,
        ));

        // A token revoked on Pathao's side: forget it and sign in once more.
        // ⚠ Safe for a booking too: a 401 is a refusal, nothing was created.
        if (! $retried && $call->response?->status() === 401) {
            Cache::forget(self::tokenCacheKey($account));

            return $this->authorised($account, $method, $path, $send, true);
        }

        return $call;
    }

    /**
     * ⚠ Units as Pathao wants them: the weight in KILOGRAMS, rounded UP to the
     * next 0.1 and never under Pathao's 0.5 floor; whole taka; ids as numbers.
     * The ids are always sent: left out, Pathao reads the address text itself,
     * which misrouted 6 of 11 real orders.
     */
    public function book(CourierAccount $account, ParcelBooking $parcel): BookingResult
    {
        $payload = array_filter([
            'store_id' => (int) $parcel->storeId,
            'merchant_order_id' => $parcel->merchantOrderId,
            'recipient_name' => $parcel->recipientName,
            'recipient_phone' => $parcel->recipientPhone,
            'recipient_address' => $parcel->recipientAddress,
            'recipient_city' => (int) $parcel->cityId,
            'recipient_zone' => (int) $parcel->zoneId,
            'recipient_area' => $parcel->areaId === null ? null : (int) $parcel->areaId,
            'delivery_type' => $parcel->deliveryType,
            'item_type' => $parcel->itemType,
            'special_instruction' => $parcel->specialInstruction,
            'item_quantity' => $parcel->quantity,
            'item_weight' => self::kilograms($parcel->weightGrams),
            'amount_to_collect' => $parcel->codTaka,
            'item_description' => $parcel->description,
        ], fn ($v) => $v !== null && $v !== '');

        $path = '/aladdin/api/v1/orders';
        $call = $this->authorised($account, 'POST', $path, fn (PendingRequest $http, string $url) => $http->asJson()->post($url, $payload));

        if ($call->outcomeUnknown()) {
            return new BookingResult(BookingResult::UNKNOWN, message: (string) $call->error, httpStatus: $call->status());
        }

        if (! $call->ok()) {
            return new BookingResult(
                BookingResult::FAILED,
                message: (string) $call->error,
                httpStatus: $call->status(),
                summary: ['fields' => array_keys((array) data_get($call->json(), 'errors', []))],
            );
        }

        $data = (array) data_get($call->json(), 'data', []);
        $id = $data['consignment_id'] ?? null;

        if (! is_scalar($id) || (string) $id === '') {
            return new BookingResult(BookingResult::UNKNOWN, message: 'Pathao answered without a consignment id.', httpStatus: $call->status());
        }

        return new BookingResult(
            BookingResult::SUCCEEDED,
            consignmentId: (string) $id,
            deliveryFee: is_numeric($data['delivery_fee'] ?? null) ? (string) $data['delivery_fee'] : null,
            rawStatus: isset($data['order_status']) ? (string) $data['order_status'] : null,
            httpStatus: $call->status(),
        );
    }

    public function details(CourierAccount $account, string $consignmentId): ParcelDetails
    {
        $call = $this->authorisedGet($account, '/aladdin/api/v1/orders/'.rawurlencode($consignmentId).'/info');

        if ($call->status() === 404) {
            return ParcelDetails::notFound("Pathao has no parcel {$consignmentId}.");
        }

        if (! $call->ok()) {
            return ParcelDetails::error((string) $call->error);
        }

        $data = (array) data_get($call->json(), 'data', []);

        if (! isset($data['consignment_id'])) {
            return ParcelDetails::error('Pathao answered without the parcel.');
        }

        $raw = $data['order_status_slug'] ?? $data['order_status'] ?? null;

        return new ParcelDetails(
            ParcelDetails::FOUND,
            consignmentId: (string) $data['consignment_id'],
            merchantOrderId: isset($data['merchant_order_id']) ? (string) $data['merchant_order_id'] : null,
            rawStatus: $raw === null ? null : (string) $raw,
            updatedAt: isset($data['updated_at']) ? (string) $data['updated_at'] : null,
            invoiceRef: isset($data['invoice_id']) && $data['invoice_id'] !== '' ? (string) $data['invoice_id'] : null,
        );
    }

    /**
     * ⚠ UNVERIFIED: not on Pathao's documentation page (11 Oct 2026); the shop
     * calls it. Tried only while `couriers.pathao_api_cancel` is on, and any
     * failure says to cancel in the Pathao panel. Nothing in the ERP changes
     * unless Pathao clearly accepted it.
     */
    public function cancel(CourierAccount $account, string $consignmentId, string $reason): CancelResult
    {
        $panel = 'Cancel it in the Pathao merchant panel, then press Refresh status.';

        if (! config('couriers.pathao_api_cancel', true)) {
            return new CancelResult(false, 'Pathao has no documented cancel in its API. '.$panel);
        }

        $path = '/aladdin/api/v1/orders/'.rawurlencode($consignmentId).'/cancel';
        $call = $this->authorised($account, 'POST', $path, fn (PendingRequest $http, string $url) => $http->asJson()->post($url, ['reason' => mb_substr($reason, 0, 199)]));

        return $call->ok()
            ? new CancelResult(true, 'Pathao accepted the cancellation.')
            : new CancelResult(false, 'Pathao did not accept the cancel through the API ('.$call->error.'). '.$panel);
    }

    /** Grams to Pathao's kilograms: up to the next 0.1, at least 0.5. */
    public static function kilograms(int $grams): float
    {
        return max(0.5, ceil($grams / 100) / 10);
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
