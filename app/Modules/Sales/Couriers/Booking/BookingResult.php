<?php

namespace App\Modules\Sales\Couriers\Booking;

/**
 * What came back from a booking request.
 *
 * - SUCCEEDED: the courier gave a consignment id.
 * - FAILED: the courier refused it (or it never left the ERP). Nothing exists
 *   at the courier, so it may be booked again once the reason is fixed.
 * - UNKNOWN: the request left and no clear answer came back (timeout, broken
 *   connection, server error). The parcel MAY exist at the courier. ⚠ Never
 *   retried blindly (ruling 13).
 */
final class BookingResult
{
    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const UNKNOWN = 'unknown';

    /**
     * @param  array<string, mixed>  $summary  no name, phone or address
     */
    public function __construct(
        public readonly string $outcome,
        public readonly ?string $consignmentId = null,
        public readonly ?string $deliveryFee = null,
        public readonly ?string $codFee = null,
        public readonly ?string $rawStatus = null,
        public readonly ?string $message = null,
        public readonly ?int $httpStatus = null,
        public readonly array $summary = [],
    ) {}

    public function succeeded(): bool
    {
        return $this->outcome === self::SUCCEEDED;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return array_filter([
            'http_status' => $this->httpStatus,
            'consignment_id' => $this->consignmentId,
            'delivery_fee' => $this->deliveryFee,
            'cod_fee' => $this->codFee,
            'status' => $this->rawStatus,
        ] + $this->summary, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }
}
