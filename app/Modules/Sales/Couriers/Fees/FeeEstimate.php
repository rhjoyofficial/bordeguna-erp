<?php

namespace App\Modules\Sales\Couriers\Fees;

use App\Modules\Sales\Couriers\Models\CourierFeeEstimate;

/**
 * What the estimator says about one parcel: a figure with its basis and how
 * sure it is, or "Insufficient data" with the reason. Never a zero standing in
 * for "not known".
 */
final class FeeEstimate
{
    public const HIGH = 'high';

    public const MEDIUM = 'medium';

    public const LOW = 'low';

    public const NONE = 'none';

    /**
     * @param  array<string, mixed>  $inputs
     */
    public function __construct(
        public readonly string $method,
        public readonly string $provider,
        public readonly string $providerName,
        public readonly ?int $courierAccountId,
        public readonly string $service,
        public readonly ?int $weightGrams,
        public readonly ?string $weightBracket,
        public readonly float $codAmount,
        public readonly ?float $deliveryFee,
        public readonly ?float $codFee,
        public readonly string $confidence,
        public readonly string $basis,
        public readonly ?string $reason = null,
        public readonly ?int $rateCardId = null,
        public readonly ?string $rateCardVersion = null,
        public readonly ?string $fromBand = null,
        public readonly ?string $toBand = null,
        public readonly ?string $toBandName = null,
        public readonly ?int $sampleSize = null,
        public readonly ?float $returnFeeScenario = null,
        public readonly array $inputs = [],
    ) {}

    public function sufficient(): bool
    {
        return $this->method !== CourierFeeEstimate::INSUFFICIENT;
    }

    public function total(): ?float
    {
        return $this->deliveryFee === null ? null : round($this->deliveryFee + (float) $this->codFee, 2);
    }

    public function confidenceLabel(): string
    {
        return match ($this->confidence) {
            self::HIGH => 'High',
            self::MEDIUM => 'Medium',
            self::LOW => 'Low',
            default => 'None',
        };
    }

    /**
     * The snapshot row. Written once (the model refuses any change).
     */
    public function record(int $businessId, int $deliveryId, ?int $consignmentId, ?int $userId): CourierFeeEstimate
    {
        return CourierFeeEstimate::create([
            'business_id' => $businessId,
            'delivery_id' => $deliveryId,
            'consignment_id' => $consignmentId,
            'courier_account_id' => $this->courierAccountId,
            'provider' => $this->provider,
            'method' => $this->method,
            'courier_rate_card_id' => $this->rateCardId,
            'rate_card_version' => $this->rateCardVersion,
            'from_band' => $this->fromBand,
            'to_band' => $this->toBand,
            'service' => $this->service,
            'weight_grams' => $this->weightGrams,
            'weight_bracket' => $this->weightBracket,
            'cod_amount' => number_format($this->codAmount, 4, '.', ''),
            'delivery_fee' => $this->deliveryFee === null ? null : number_format($this->deliveryFee, 2, '.', ''),
            'cod_fee' => $this->codFee === null ? null : number_format($this->codFee, 2, '.', ''),
            'total' => $this->total() === null ? null : number_format((float) $this->total(), 2, '.', ''),
            'confidence' => $this->confidence,
            'sample_size' => $this->sampleSize,
            'basis' => mb_substr($this->basis, 0, 500),
            'inputs' => $this->inputs,
            'created_by' => $userId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sufficient' => $this->sufficient(),
            'method' => $this->method,
            'provider' => $this->provider,
            'provider_name' => $this->providerName,
            'service' => $this->service,
            'weight_grams' => $this->weightGrams,
            'weight_bracket' => $this->weightBracket,
            'cod_amount' => $this->codAmount,
            'delivery_fee' => $this->deliveryFee,
            'cod_fee' => $this->codFee,
            'total' => $this->total(),
            'confidence' => $this->confidence,
            'confidence_label' => $this->confidenceLabel(),
            'basis' => $this->basis,
            'reason' => $this->reason,
            'rate_card_id' => $this->rateCardId,
            'rate_card_version' => $this->rateCardVersion,
            'from_band' => $this->fromBand,
            'to_band' => $this->toBand,
            'to_band_name' => $this->toBandName,
            'sample_size' => $this->sampleSize,
            'return_fee_scenario' => $this->returnFeeScenario,
        ];
    }
}
