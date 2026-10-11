<?php

namespace App\Modules\Sales\Couriers\Drivers;

use App\Modules\Sales\Couriers\Booking\BookingResult;
use App\Modules\Sales\Couriers\Booking\CancelResult;
use App\Modules\Sales\Couriers\Booking\ParcelBooking;
use App\Modules\Sales\Couriers\Booking\ParcelDetails;
use App\Modules\Sales\Couriers\Models\CourierAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * CarryBee API v2: three headers on every request, no token exchange.
 * Read-only in slice 1: GET /api/v2/stores and GET /api/v2/cities.
 * Slice 2 adds the coverage lists, the area suggestion and the address
 * resolver, all read-only (POST address-details changes nothing at CarryBee).
 * Slice 4 books (POST /api/v2/orders), asks (GET details, by consignment id or
 * by our merchant_order_id) and cancels.
 */
class CarryBeeDriver implements BooksParcels, CourierDriver, ListsLocations, LooksUpMerchantReference
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

    /**
     * ⚠ Units as CarryBee wants them: grams as a whole number (1 to 25,000),
     * whole taka, ids as numbers. Area left out when the map has none.
     */
    public function book(CourierAccount $account, ParcelBooking $parcel): BookingResult
    {
        $payload = array_filter([
            'store_id' => $parcel->storeId,
            'merchant_order_id' => $parcel->merchantOrderId,
            'delivery_type' => $parcel->deliveryType,
            'product_type' => $parcel->itemType,
            'recipient_phone' => $parcel->recipientPhone,
            'recipient_name' => $parcel->recipientName,
            'recipient_address' => $parcel->recipientAddress,
            'city_id' => (int) $parcel->cityId,
            'zone_id' => (int) $parcel->zoneId,
            'area_id' => $parcel->areaId === null ? null : (int) $parcel->areaId,
            'special_instruction' => $parcel->specialInstruction,
            'product_description' => $parcel->description,
            'item_weight' => $parcel->weightGrams,
            'item_quantity' => $parcel->quantity,
            'collectable_amount' => $parcel->codTaka,
        ], fn ($v) => $v !== null && $v !== '');

        $path = '/api/v2/orders';
        $call = $this->api->call($account, 'POST', $path, fn (string $base) => $this->client($account)->asJson()->post($base.$path, $payload));

        if ($call->outcomeUnknown()) {
            return new BookingResult(BookingResult::UNKNOWN, message: (string) $call->error, httpStatus: $call->status());
        }

        if (! $call->ok()) {
            return new BookingResult(
                BookingResult::FAILED,
                message: (string) $call->error,
                httpStatus: $call->status(),
                summary: ['fields' => array_keys((array) data_get($call->json(), 'causes', []))],
            );
        }

        $order = (array) data_get($call->json(), 'data.order', data_get($call->json(), 'data', []));
        $id = $order['consignment_id'] ?? null;

        // A success with no id is not a booking anybody can follow. The parcel
        // may still exist, so it is UNKNOWN and checked, never sent again.
        if (! is_scalar($id) || (string) $id === '') {
            return new BookingResult(BookingResult::UNKNOWN, message: 'CarryBee answered without a consignment id.', httpStatus: $call->status());
        }

        return new BookingResult(
            BookingResult::SUCCEEDED,
            consignmentId: (string) $id,
            deliveryFee: self::amount($order['delivery_fee'] ?? null),
            codFee: self::amount($order['cod_fee'] ?? null),
            httpStatus: $call->status(),
        );
    }

    public function details(CourierAccount $account, string $consignmentId): ParcelDetails
    {
        return $this->detailsAt($account, $consignmentId);
    }

    /**
     * GET details accepts our merchant_order_id as well as CarryBee's id: the
     * check made before anybody may book a timed-out parcel again.
     */
    public function lookupMerchantReference(CourierAccount $account, string $merchantOrderId): ParcelDetails
    {
        return $this->detailsAt($account, $merchantOrderId);
    }

    public function cancel(CourierAccount $account, string $consignmentId, string $reason): CancelResult
    {
        $path = '/api/v2/orders/'.rawurlencode($consignmentId).'/cancel';
        $call = $this->api->call($account, 'POST', $path, fn (string $base) => $this->client($account)->asJson()
            ->post($base.$path, ['cancellation_reason' => mb_substr($reason, 0, 199)]));

        return $call->ok()
            ? new CancelResult(true, 'CarryBee accepted the cancellation.')
            : new CancelResult(false, 'CarryBee did not cancel it: '.$call->error);
    }

    private function detailsAt(CourierAccount $account, string $reference): ParcelDetails
    {
        $call = $this->get($account, '/api/v2/orders/'.rawurlencode($reference).'/details');

        if ($call->status() === 404) {
            return ParcelDetails::notFound("CarryBee has no parcel under {$reference}.");
        }

        if (! $call->ok()) {
            return ParcelDetails::error((string) $call->error);
        }

        $data = (array) data_get($call->json(), 'data.order', data_get($call->json(), 'data', []));

        if (! isset($data['consignment_id'])) {
            return ParcelDetails::error('CarryBee answered without the parcel.');
        }

        $weight = $data['item_weight'] ?? null;

        return new ParcelDetails(
            ParcelDetails::FOUND,
            consignmentId: (string) $data['consignment_id'],
            merchantOrderId: isset($data['merchant_order_id']) ? (string) $data['merchant_order_id'] : null,
            rawStatus: isset($data['transfer_status']) ? (string) $data['transfer_status'] : null,
            updatedAt: isset($data['updated_at']) ? (string) $data['updated_at'] : null,
            deliveryFee: self::amount($data['delivery_fee'] ?? null),
            codFee: self::amount($data['cod_fee'] ?? null),
            collectedAmount: self::amount($data['collected_amount'] ?? null),
            hubWeightGrams: is_numeric($weight) && (int) $weight > 0 ? (int) $weight : null,
            invoiceRef: isset($data['invoice_id']) && $data['invoice_id'] !== '' ? (string) $data['invoice_id'] : null,
        );
    }

    /** CarryBee sends the delivery fee as a string and the COD fee as a number. */
    private static function amount(mixed $value): ?string
    {
        return is_numeric($value) ? (string) $value : null;
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
