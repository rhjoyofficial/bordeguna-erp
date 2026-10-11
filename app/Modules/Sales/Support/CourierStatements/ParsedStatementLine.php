<?php

namespace App\Modules\Sales\Support\CourierStatements;

use App\Modules\Accounting\Support\Money;

/**
 * One parcel as a courier's file describes it, in the ERP's own words, whichever
 * courier wrote the file.
 *
 * ⭐ Every format normalises to ONE equation, so posting never needs to know who
 * wrote the file:
 *   finalFee = deliveryFee - discount + codFee + additionalCharge
 *   payout   = collected - finalFee + compensation + adjustment
 * `discount` is every discount the courier took off its fee (a Pathao promo it
 * deducted is included); `promoDiscount` is kept beside it as the file said.
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
        public ?Money $promoDiscount = null,
        public ?Money $compensation = null,
        public ?Money $adjustment = null,
        public ?int $hubWeightGrams = null,
        public ?string $deliveryStatus = null,
        public ?string $deliveredOn = null,
    ) {}

    /** The courier's charge to carry it, net of its discount. */
    public function netFee(): Money
    {
        return Money::ofMinor($this->deliveryFee->minor - $this->discount->minor);
    }
}
