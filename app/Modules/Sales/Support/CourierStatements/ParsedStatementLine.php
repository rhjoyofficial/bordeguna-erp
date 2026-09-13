<?php

namespace App\Modules\Sales\Support\CourierStatements;

use App\Modules\Accounting\Support\Money;

/**
 * One parcel as a courier's file describes it, in the ERP's own words, whichever
 * courier wrote the file.
 */
final readonly class ParsedStatementLine
{
    public function __construct(
        public int $rowNumber,
        public string $consignmentNumber,
        public ?string $merchantReference,
        public ?string $bookedAt,
        public Money $collectable,
        public Money $collected,
        public Money $deliveryFee,
        public Money $discount,
        public Money $codFee,
        public Money $additionalCharge,
        public Money $finalFee,
        public Money $payout,
    ) {}
}
