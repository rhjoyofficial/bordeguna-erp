<?php

namespace App\Modules\Sales\Couriers\Booking;

/**
 * What a courier says about one parcel when asked (CarryBee GET details,
 * Pathao GET info). Only the fields the ERP keeps: ids, the raw status, fees,
 * what it collected, the hub weight, the courier's invoice. ⚠ No recipient
 * data is carried out of the driver.
 */
final class ParcelDetails
{
    public const FOUND = 'found';

    public const NOT_FOUND = 'not_found';

    public const ERROR = 'error';

    public function __construct(
        public readonly string $state,
        public readonly ?string $consignmentId = null,
        public readonly ?string $merchantOrderId = null,
        public readonly ?string $rawStatus = null,
        public readonly ?string $updatedAt = null,
        public readonly ?string $deliveryFee = null,
        public readonly ?string $codFee = null,
        public readonly ?string $collectedAmount = null,
        public readonly ?int $hubWeightGrams = null,
        public readonly ?string $invoiceRef = null,
        public readonly ?string $message = null,
    ) {}

    public static function error(string $message): self
    {
        return new self(self::ERROR, message: $message);
    }

    public static function notFound(string $message): self
    {
        return new self(self::NOT_FOUND, message: $message);
    }

    public function found(): bool
    {
        return $this->state === self::FOUND;
    }

    /**
     * The non-PII fields an event row keeps.
     *
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return array_filter([
            'delivery_fee' => $this->deliveryFee,
            'cod_fee' => $this->codFee,
            'collected_amount' => $this->collectedAmount,
            'hub_weight_grams' => $this->hubWeightGrams,
            'invoice_id' => $this->invoiceRef,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
