<?php

namespace App\Modules\Sales\Couriers\Fees;

use App\Modules\Sales\Couriers\Drivers\PathaoDriver;
use App\Modules\Sales\Couriers\Locations\CourierDestination;
use App\Modules\Sales\Couriers\Locations\ResolvedDestination;
use App\Modules\Sales\Couriers\Locations\Unresolved;
use App\Modules\Sales\Couriers\Models\CourierAccount;
use App\Modules\Sales\Couriers\Models\CourierFeeEstimate;
use App\Modules\Sales\Couriers\Models\CourierRateCard;
use App\Modules\Sales\Couriers\Models\CourierRateCardLine;
use App\Modules\Sales\Couriers\Models\CourierZoneBand;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * What a parcel will cost us at the courier, before it is booked (courier hub
 * slice 5, plan E.2 and E.3).
 *
 * In order, the first that can answer:
 *   1. Pathao's own live price for this store, zone, weight and service (High).
 *   2. The owner-approved rate card in force on the date (Medium; Low for a
 *      list price with no known discount).
 *   3. The median of at least 10 clean past parcels of the same courier,
 *      band and weight bracket (Medium with 30 or more, else Low).
 *   4. "Insufficient data", with the reason. No number.
 *
 * ⚠⚠ A MISSING WEIGHT OR AN UNRESOLVED DESTINATION IS NEVER A ZERO OR A GUESS
 * (ruling 10, slice 2). The estimate says what is missing.
 *
 * ⚠ This is OUR COST. The customer's delivery charge (the storefront band
 * price) is revenue and is never worked out or shown here.
 */
class CourierFeeEstimator
{
    /** Ask Pathao when there is no fresh cached quote. */
    public const QUOTE_LIVE = 'live';

    /** Use a cached Pathao quote if there is one, never a new call (booking). */
    public const QUOTE_CACHED = 'cached';

    /** Never use a quote (tests, reports). */
    public const QUOTE_OFF = 'off';

    /** Our service words and each courier's delivery type for them. */
    private const DELIVERY_TYPES = [
        'pathao' => ['normal' => 48, 'same_day' => 12],
        'carrybee' => ['normal' => 1, 'express' => 2],
    ];

    public function __construct(private readonly CourierFeeSamples $samples) {}

    /**
     * The service word for a courier's own delivery type value (the booking
     * form sends the courier's value).
     */
    public static function serviceFor(string $provider, mixed $deliveryType): string
    {
        $map = array_flip(self::DELIVERY_TYPES[$provider] ?? []);

        return $map[(int) $deliveryType] ?? 'normal';
    }

    /**
     * @param  array{district?: ?string, thana?: ?string, area?: ?string}  $destination
     * @param  array{items_weighed?: int, items_total?: int, typed?: bool}  $weightInfo
     */
    public function estimate(
        CourierAccount $account,
        int $businessId,
        array $destination,
        ?int $weightGrams,
        int|float|string|null $codAmount,
        string $service = 'normal',
        array $weightInfo = [],
        string $quote = self::QUOTE_LIVE,
        ?string $on = null,
    ): FeeEstimate {
        $definition = $account->definition();
        $provider = $definition->code();
        $name = $definition->name();
        $cod = max(0.0, (float) ($codAmount ?? 0));
        $service = in_array($service, CourierRateCardLine::SERVICES, true) ? $service : 'normal';
        $inputs = [
            'account_id' => $account->id,
            'district' => $destination['district'] ?? null,
            'thana' => $destination['thana'] ?? null,
            'area' => $destination['area'] ?? null,
            'weight_grams' => $weightGrams,
            'weight' => $weightInfo,
            'cod_amount' => $cod,
            'service' => $service,
            'on' => $on ?? now('Asia/Dhaka')->toDateString(),
        ];

        $insufficient = fn (string $reason, array $extra = []) => new FeeEstimate(
            method: CourierFeeEstimate::INSUFFICIENT,
            provider: $provider,
            providerName: $name,
            courierAccountId: $account->id,
            service: $service,
            weightGrams: $weightGrams,
            weightBracket: $weightGrams === null || $weightGrams <= 0 ? null : FeeBrackets::for($weightGrams),
            codAmount: $cod,
            deliveryFee: null,
            codFee: null,
            confidence: FeeEstimate::NONE,
            basis: 'Insufficient data. '.$reason,
            reason: $reason,
            fromBand: $extra['from_band'] ?? null,
            toBand: $extra['to_band'] ?? null,
            toBandName: $extra['to_band_name'] ?? null,
            sampleSize: $extra['samples'] ?? null,
            inputs: $inputs,
        );

        if ($weightGrams === null || $weightGrams <= 0) {
            $known = isset($weightInfo['items_total'])
                ? sprintf(' (%d of %d items weighed)', (int) ($weightInfo['items_weighed'] ?? 0), (int) $weightInfo['items_total'])
                : '';

            return $insufficient("No parcel weight{$known}. Weigh the parcel and type it.");
        }

        $place = CourierDestination::resolve($provider, $destination['district'] ?? null, $destination['thana'] ?? null, $destination['area'] ?? null);

        if ($place instanceof Unresolved) {
            return $insufficient($place->message);
        }

        $pickupDistrict = (string) config('couriers.pickup_district', 'Dhaka');
        $fromBand = CourierZoneBand::for($provider, $pickupDistrict, (string) config('couriers.pickup_thana', ''))?->code;
        $toBand = $place->bandCode;
        $bands = ['from_band' => $fromBand, 'to_band' => $toBand, 'to_band_name' => $place->bandName];

        if ($toBand === null || $fromBand === null) {
            return $insufficient("{$name} has no price bands set up. Add them in Settings > Couriers > Locations.", $bands);
        }

        $percent = $this->codPercent($account);
        $codFee = $this->codFee($account, $cod, $percent);
        $route = sprintf('%s -> %s (%s)', $pickupDistrict, $place->district, $place->bandName ?? $toBand);
        $weightText = $this->weightText($weightGrams, $weightInfo);
        $bracket = FeeBrackets::for($weightGrams);

        $make = function (string $method, float $delivery, ?float $codFeeUsed, ?float $codPercentUsed, string $source, string $confidence, array $extra = []) use ($provider, $name, $account, $service, $weightGrams, $bracket, $cod, $bands, $route, $weightText, $inputs) {
            $codPart = $cod > 0 ? sprintf('COD %s%% included', rtrim(rtrim(number_format((float) $codPercentUsed, 2, '.', ''), '0'), '.')) : 'no COD';
            $total = round($delivery + (float) $codFeeUsed, 2);
            $label = match ($confidence) {
                FeeEstimate::HIGH => 'High',
                FeeEstimate::MEDIUM => 'Medium',
                default => 'Low',
            };

            return new FeeEstimate(
                method: $method,
                provider: $provider,
                providerName: $name,
                courierAccountId: $account->id,
                service: $service,
                weightGrams: $weightGrams,
                weightBracket: $bracket,
                codAmount: $cod,
                deliveryFee: round($delivery, 2),
                codFee: $codFeeUsed === null ? null : round($codFeeUsed, 2),
                confidence: $confidence,
                basis: sprintf(
                    'Estimated charge %s. Basis: weight %s, %s, %s, %s. Confidence: %s',
                    FeeBrackets::taka($total), $weightText, $route, $source, $codPart, $label,
                ),
                rateCardId: $extra['card_id'] ?? null,
                rateCardVersion: $extra['version'] ?? null,
                fromBand: $bands['from_band'],
                toBand: $bands['to_band'],
                toBandName: $bands['to_band_name'],
                sampleSize: $extra['samples'] ?? null,
                returnFeeScenario: $extra['return'] ?? null,
                inputs: $inputs + ['resolved' => ['city_id' => $extra['city_id'] ?? null, 'zone_id' => $extra['zone_id'] ?? null]],
            );
        };

        // 1. Pathao's own price.
        if ($quote !== self::QUOTE_OFF && $provider === 'pathao' && $account->is_active) {
            $live = $this->pathaoQuote($account, $businessId, $place, $weightGrams, $service, $quote === self::QUOTE_LIVE);

            if ($live !== null) {
                $quotePercent = $live['cod_percent'] ?? $percent;
                $quoteCod = $this->codFee($account, $cod, $quotePercent);

                return $make(CourierFeeEstimate::PROVIDER_QUOTE, (float) $live['delivery_fee'], $quoteCod, $quotePercent, "{$name} live quote", FeeEstimate::HIGH, [
                    'city_id' => $place->cityId, 'zone_id' => $place->zoneId,
                ]);
            }
        }

        // 2. The approved card in force.
        $card = CourierRateCard::effectiveFor($provider, $on);

        if ($card !== null) {
            $line = $card->lines()
                ->where('from_band', (string) $fromBand)
                ->where('to_band', (string) $toBand)
                ->where('service', (string) $service)
                ->get()
                ->first(fn (CourierRateCardLine $l) => $l->covers($weightGrams));

            if ($line !== null) {
                $price = $line->priceFor($weightGrams);
                $discount = $line->expected_discount === null ? null : (float) $line->expected_discount;
                $delivery = max(0.0, $price - (float) $discount);
                $source = "{$name} rate card {$card->version}"
                    .($card->is_list_price ? ' (list price)' : '')
                    .($discount !== null && $discount > 0 ? ' less expected discount '.FeeBrackets::taka($discount) : '');
                $confidence = $card->is_list_price && $discount === null ? FeeEstimate::LOW : FeeEstimate::MEDIUM;

                return $make(CourierFeeEstimate::RATE_CARD, $delivery, $codFee, $percent, $source, $confidence, [
                    'card_id' => $card->id,
                    'version' => $card->version,
                    'return' => $line->return_fee_percent === null ? null : round($delivery * (float) $line->return_fee_percent / 100, 2),
                    'city_id' => $place->cityId, 'zone_id' => $place->zoneId,
                ]);
            }
        }

        // 3. What clean past parcels really cost.
        $history = $this->samples->clean($provider)
            ->filter(fn (array $row) => $row['to_band'] === $toBand && $row['service'] === $service && $row['bracket'] === $bracket);
        $count = $history->count();
        $minimum = (int) config('couriers.min_samples', 10);

        if ($count >= $minimum) {
            $median = (float) FeeBrackets::median($history->map(fn (array $row) => (float) $row['actual']['delivery'])->values()->all());

            return $make(CourierFeeEstimate::HISTORICAL, $median, $codFee, $percent, "median of {$count} past {$name} parcels", $count >= 30 ? FeeEstimate::MEDIUM : FeeEstimate::LOW, [
                'samples' => $count,
                'city_id' => $place->cityId, 'zone_id' => $place->zoneId,
            ]);
        }

        return $insufficient(sprintf(
            'No %s for %s at %s, and %d clean past parcels (at least %d are needed).',
            $card === null ? "approved {$name} rate card in force" : "line on {$name} rate card {$card->version}",
            $route,
            FeeBrackets::kg($weightGrams).($service === 'normal' ? '' : ', '.str_replace('_', ' ', $service)),
            $count,
            $minimum,
        ), $bands + ['samples' => $count]);
    }

    public function codPercent(CourierAccount $account): float
    {
        $value = $account->options['cod_percent'] ?? null;

        if (is_numeric($value)) {
            return (float) $value;
        }

        foreach ($account->definition()->optionFields() as $field) {
            if ($field['key'] === 'cod_percent') {
                return (float) $field['default'];
            }
        }

        return 0.0;
    }

    /**
     * Collectable x the percent; a minimum only when the account carries one
     * (`cod_min_fee`). Nothing to collect, nothing charged.
     */
    public function codFee(CourierAccount $account, float $cod, float $percent): float
    {
        if ($cod <= 0) {
            return 0.0;
        }

        $fee = round($cod * $percent / 100, 2);
        $minimum = $account->options['cod_min_fee'] ?? null;

        return is_numeric($minimum) && (float) $minimum > 0 ? max($fee, (float) $minimum) : $fee;
    }

    public static function quoteCacheKey(CourierAccount $account, string $cityId, string $zoneId, int $grams, string $service): string
    {
        // Pathao prices by bracket up to 2 kg, then by started kilogram.
        $bracket = $grams <= 2000 ? FeeBrackets::for($grams) : 'kg'.(int) ceil($grams / 1000);

        return 'couriers:quote:'.$account->id.':'.$cityId.':'.$zoneId.':'.$bracket.':'.$service;
    }

    /**
     * @return array{delivery_fee: float, cod_percent: ?float}|null
     */
    private function pathaoQuote(CourierAccount $account, int $businessId, ResolvedDestination $place, int $grams, string $service, bool $mayCall): ?array
    {
        $deliveryType = self::DELIVERY_TYPES['pathao'][$service] ?? null;
        $store = $account->linkFor($businessId)?->provider_store_id;

        if ($deliveryType === null || blank($store)) {
            return null;
        }

        $key = self::quoteCacheKey($account, $place->cityId, $place->zoneId, $grams, $service);
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        if (! $mayCall) {
            return null;
        }

        $driver = app((string) $account->definition()->driverClass());

        if (! $driver instanceof PathaoDriver) {
            return null;
        }

        try {
            [$quote] = $driver->pricePlan(
                $account,
                (string) $store,
                (int) ($account->options['item_type'] ?? 2),
                $deliveryType,
                $grams,
                $place->cityId,
                $place->zoneId,
            );
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        if ($quote === null) {
            return null;
        }

        $kept = ['delivery_fee' => (float) $quote['delivery_fee'], 'cod_percent' => $quote['cod_percent']];
        Cache::put($key, $kept, (int) config('couriers.quote_cache_seconds', 6 * 3600));

        return $kept;
    }

    /**
     * @param  array{items_weighed?: int, items_total?: int, typed?: bool}  $info
     */
    private function weightText(int $grams, array $info): string
    {
        $text = FeeBrackets::kg($grams);

        if (($info['typed'] ?? false) === true) {
            return $text.' (typed)';
        }

        if (isset($info['items_total'])) {
            return sprintf('%s (%d of %d items weighed)', $text, (int) ($info['items_weighed'] ?? 0), (int) $info['items_total']);
        }

        return $text;
    }
}
