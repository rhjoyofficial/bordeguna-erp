<?php

namespace App\Modules\Sales\Couriers\Fees;

use App\Modules\Sales\Couriers\Enums\CourierStatus;
use App\Modules\Sales\Couriers\Models\CourierFeeEstimate;
use App\Modules\Sales\Couriers\Models\CourierZoneBand;
use App\Modules\Sales\Enums\ConsignmentStatus;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\CourierStatementLine;
use App\Modules\Sales\Models\DeliveryLine;
use App\Modules\Sales\Support\CourierStatements\CourierStatementFormats;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Every parcel of the current business with its three facts side by side:
 * the estimate kept at booking, the courier's quote at booking, and what its
 * statement charged (net fee + COD fee). The history, the variance report and
 * calibration all read from here, so they never disagree about which parcels
 * count.
 *
 * ⚠⚠ WHAT THE ERP LEARNS FROM IS A CLEAN SAMPLE ONLY (plan E.4, ruling 9):
 * matched to a statement line, not cancelled, not returned, one statement line
 * only, a declared weight, and a hub weight within the tolerance of it
 * (CarryBee re-weighs; a parcel charged by another weight is a weight
 * correction, not a price). Every other parcel is shown with the reason it was
 * left out, never silently dropped.
 *
 * ⚠ Business-scoped by the models' global scope: one business learns from its
 * own parcels.
 */
class CourierFeeSamples
{
    public const NO_STATEMENT = 'no_statement';

    public const CANCELLED = 'cancelled';

    public const RETURNED = 'returned';

    public const DUPLICATE = 'duplicate';

    public const NO_WEIGHT = 'no_weight';

    public const REWEIGHED = 'reweighed';

    public const NO_BAND = 'no_band';

    public const REASONS = [
        self::NO_STATEMENT => 'No statement line yet',
        self::CANCELLED => 'Cancelled',
        self::RETURNED => 'Returned',
        self::DUPLICATE => 'On more than one statement line',
        self::NO_WEIGHT => 'No declared weight',
        self::REWEIGHED => 'Hub weight differs from ours',
        self::NO_BAND => 'Destination band not known',
    ];

    private const RETURN_WORDS = [
        CourierStatus::Returning, CourierStatus::ReturnedToMerchant, CourierStatus::PaidReturn, CourierStatus::Exchanged,
    ];

    /** @var array<string, Collection<int, array<string, mixed>>> */
    private array $memo = [];

    /**
     * @param  array{from?: ?string, to?: ?string, matched_only?: bool}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(array $filters = []): Collection
    {
        $key = json_encode($filters);

        return $this->memo[$key] ??= $this->build($filters);
    }

    /**
     * Clean samples of one provider: what the history and calibration learn from.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function clean(string $provider): Collection
    {
        return $this->rows(['matched_only' => true])
            ->filter(fn (array $row) => $row['excluded'] === null && $row['provider'] === $provider)
            ->values();
    }

    /**
     * @param  array{from?: ?string, to?: ?string, matched_only?: bool}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function build(array $filters): Collection
    {
        $query = Consignment::query()
            ->with([
                'statementLine.statement:id,provider_code,statement_date',
                'courierAccount.provider',
                'courier:id,provider',
                'delivery:id',
                'delivery.lines:id,delivery_id,sales_order_line_id',
                'delivery.lines.orderLine:id,sales_order_id',
                'delivery.lines.orderLine.order:id',
                'delivery.lines.orderLine.order.address',
            ])
            ->orderBy('id');

        if ($filters['matched_only'] ?? false) {
            $query->whereHas('statementLine');
        }

        if (filled($filters['from'] ?? null)) {
            $query->where('created_at', '>=', Carbon::parse((string) $filters['from'], 'Asia/Dhaka')->startOfDay()->utc()->toDateTimeString());
        }

        if (filled($filters['to'] ?? null)) {
            $query->where('created_at', '<=', Carbon::parse((string) $filters['to'], 'Asia/Dhaka')->endOfDay()->utc()->toDateTimeString());
        }

        $consignments = $query->get();

        if ($consignments->isEmpty()) {
            return collect();
        }

        $ids = $consignments->pluck('id')->map(fn ($id) => (int) $id)->all();

        $lineCounts = CourierStatementLine::query()
            ->whereIn('consignment_id', $ids)
            ->selectRaw('consignment_id, COUNT(*) as n')
            ->groupBy('consignment_id')
            ->pluck('n', 'consignment_id');

        // The latest estimate kept for each parcel (normally exactly one).
        $estimates = CourierFeeEstimate::query()->whereIn('consignment_id', $ids)->orderBy('id')->get()->keyBy('consignment_id');

        $bands = CourierZoneBand::query()->orderBy('sort_order')->get()->groupBy('provider');
        $tolerance = (float) config('couriers.reweigh_tolerance_percent', 20);

        return $consignments->map(function (Consignment $consignment) use ($lineCounts, $estimates, $bands, $tolerance) {
            $line = $consignment->statementLine;
            /** @var CourierFeeEstimate|null $estimate */
            $estimate = $estimates->get($consignment->id);
            $provider = $this->providerOf($consignment, $line);

            $address = $consignment->delivery?->lines
                ->map(fn (DeliveryLine $l) => $l->orderLine?->order?->address)
                ->filter()->first();

            $toBand = $estimate?->to_band;
            $toBandName = null;

            if ($toBand === null && $address !== null && filled($address->district)) {
                $toBand = CourierZoneBand::for($provider, (string) $address->district, (string) $address->thana)?->code;
            }

            if ($toBand !== null) {
                $toBandName = optional(($bands->get($provider) ?? collect())->firstWhere('code', $toBand))->name;
            }

            $declared = $consignment->declared_weight_grams;
            $hub = $line?->hub_weight_grams ?? $consignment->hub_weight_grams;
            $quoteFee = $consignment->provider_fee_quoted;
            $quoteCod = $consignment->provider_cod_fee_quoted;

            $actual = null;

            if ($line !== null) {
                $net = $line->net_fee !== null ? (float) $line->net_fee : (float) $line->delivery_fee - (float) $line->discount;
                $actual = [
                    'delivery' => round($net, 2),
                    'gross' => round((float) $line->delivery_fee, 2),
                    'discount' => round((float) $line->discount, 2),
                    'cod' => round((float) $line->cod_fee, 2),
                    'total' => round($net + (float) $line->cod_fee, 2),
                ];
            }

            $returned = $consignment->status === ConsignmentStatus::Returned
                || in_array($consignment->courier_status, self::RETURN_WORDS, true)
                || ($line !== null && str_contains(strtolower((string) $line->delivery_status), 'return'));

            $excluded = match (true) {
                $line === null => self::NO_STATEMENT,
                $consignment->courier_status === CourierStatus::Cancelled => self::CANCELLED,
                $returned => self::RETURNED,
                (int) ($lineCounts[$consignment->id] ?? 0) > 1 => self::DUPLICATE,
                $declared === null || $declared <= 0 => self::NO_WEIGHT,
                $hub !== null && abs($hub - $declared) / $declared * 100 > $tolerance => self::REWEIGHED,
                $toBand === null => self::NO_BAND,
                default => null,
            };

            $booked = $consignment->created_at?->copy()->timezone('Asia/Dhaka');

            return [
                'consignment_id' => $consignment->id,
                'consignment_number' => (string) $consignment->consignment_number,
                'delivery_id' => (int) $consignment->delivery_id,
                'provider' => $provider,
                'booked_on' => $booked?->toDateString(),
                'week' => $booked?->copy()->startOfWeek()->toDateString(),
                'district' => $address?->district,
                'from_band' => $estimate?->from_band,
                'to_band' => $toBand,
                'to_band_name' => $toBandName,
                'service' => $estimate?->service ?? 'normal',
                'declared_grams' => $declared,
                'auto_grams' => $consignment->declared_weight_auto_grams,
                // Joy, 11 Oct 2026: a typed weight is kept silently beside the
                // auto one, so the analysis can explain it.
                'overridden' => $declared !== null && $consignment->declared_weight_auto_grams !== null && $declared !== $consignment->declared_weight_auto_grams,
                'hub_grams' => $hub,
                'bracket' => $declared === null ? null : FeeBrackets::for((int) $declared),
                'estimate' => $estimate === null ? null : [
                    'method' => $estimate->method,
                    'version' => $estimate->rate_card_version,
                    'confidence' => $estimate->confidence,
                    'delivery' => $estimate->delivery_fee === null ? null : (float) $estimate->delivery_fee,
                    'cod' => $estimate->cod_fee === null ? null : (float) $estimate->cod_fee,
                    'total' => $estimate->total === null ? null : (float) $estimate->total,
                ],
                'quote' => $quoteFee === null ? null : [
                    'delivery' => round((float) $quoteFee, 2),
                    'cod' => $quoteCod === null ? null : round((float) $quoteCod, 2),
                    'total' => round((float) $quoteFee + (float) $quoteCod, 2),
                ],
                'actual' => $actual,
                'excluded' => $excluded,
                'excluded_label' => $excluded === null ? null : self::REASONS[$excluded],
            ];
        })->values();
    }

    private function providerOf(Consignment $consignment, ?CourierStatementLine $line): string
    {
        if ($consignment->courierAccount !== null) {
            return $consignment->courierAccount->definition()->code();
        }

        $raw = $line?->statement?->provider_code ?? $consignment->courier?->provider ?? '';

        return CourierStatementFormats::normalise((string) $raw);
    }
}
