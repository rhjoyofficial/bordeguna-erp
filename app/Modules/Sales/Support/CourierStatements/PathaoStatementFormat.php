<?php

namespace App\Modules\Sales\Support\CourierStatements;

use App\Modules\Accounting\Support\Money;

/**
 * Pathao's invoice CSV, as downloaded from its merchant panel (one real file read
 * on 13 September 2026, research doc §5).
 *
 * ⭐ The arithmetic every row must satisfy, checked against the real file and the
 * panel's totals: Final_Fee = Delivery_Fee - Discount + COD_fee + Additional_Charge,
 * and Payout = Collected_Amount - Final_Fee.
 *
 * ⭐ Promo_Discount and Compensation_Cost (v2, 11 Oct 2026). Zero on every row
 * seen so far, so which way they move the money is not proven. A row is read
 * when it adds up EITHER with them (the promo comes off the fee, the
 * compensation is paid on top of the payout) OR without them (the columns are
 * only information). Whichever equation holds is what is stored; a row that adds
 * up neither way is a row error. Never a guess that leaves money unexplained.
 *
 * ⚠ Recipient_Name and Recipient_Phone are never read.
 */
final class PathaoStatementFormat extends CsvStatementFormat
{
    public function name(): string
    {
        return 'Pathao invoice CSV';
    }

    public function code(): string
    {
        return 'pathao_invoice_csv_v2';
    }

    protected function required(): array
    {
        return [
            'Consignment_ID', 'Created_Date', 'Collected_Amount', 'Collectable_Amount', 'COD_fee',
            'Delivery_Fee', 'Final_Fee', 'Discount', 'Additional_Charge', 'Compensation_Cost',
            'Promo_Discount', 'Payout', 'Merchant_Order_ID',
        ];
    }

    protected function consignmentColumn(): string
    {
        return 'Consignment_ID';
    }

    protected function line(int $rowNumber, string $consignment, callable $cell): ParsedStatementLine
    {
        $collected = self::money($cell, 'Collected_Amount');
        $fee = self::money($cell, 'Delivery_Fee');
        $discount = self::money($cell, 'Discount');
        $promo = self::money($cell, 'Promo_Discount');
        $cod = self::money($cell, 'COD_fee');
        $additional = self::money($cell, 'Additional_Charge');
        $final = self::money($cell, 'Final_Fee');
        $compensation = self::money($cell, 'Compensation_Cost');
        $payout = self::money($cell, 'Payout');

        $finalWithout = $fee->minor - $discount->minor + $cod->minor + $additional->minor;
        $promoApplied = match (true) {
            $final->minor === $finalWithout => false,
            $final->minor === $finalWithout - $promo->minor => true,
            default => null,
        };

        $payoutWithout = $collected->minor - $final->minor;
        $compensationApplied = match (true) {
            $payout->minor === $payoutWithout => false,
            $payout->minor === $payoutWithout + $compensation->minor => true,
            default => null,
        };

        if ($promoApplied === null || $compensationApplied === null) {
            throw new StatementRowException("Consignment {$consignment} does not add up: its fees or payout disagree with its own columns.");
        }

        return new ParsedStatementLine(
            rowNumber: $rowNumber,
            consignmentNumber: $consignment,
            merchantReference: self::nullable($cell('Merchant_Order_ID')),
            bookedAt: self::nullable($cell('Created_Date')),
            collectable: self::money($cell, 'Collectable_Amount'),
            collected: $collected,
            deliveryFee: $fee,
            discount: $promoApplied ? Money::ofMinor($discount->minor + $promo->minor) : $discount,
            codFee: $cod,
            additionalCharge: $additional,
            finalFee: $final,
            payout: $payout,
            promoDiscount: $promo,
            compensation: $compensationApplied ? $compensation : Money::zero(),
            adjustment: Money::zero(),
        );
    }
}
