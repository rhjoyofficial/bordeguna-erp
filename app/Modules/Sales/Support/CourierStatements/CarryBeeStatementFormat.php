<?php

namespace App\Modules\Sales\Support\CourierStatements;

use App\Modules\Accounting\Support\Money;

/**
 * CarryBee's invoice CSV: merchant portal > Invoices > an invoice > Export > CSV
 * (verified 11 Oct 2026 on invoice I0510263Y2AMK, six parcels).
 *
 * ⭐ The arithmetic every row must satisfy, held on every sample row:
 *   Total Fee      = Delivery_fee - Discount + COD_fee + Additional_charge
 *   Billing_amount = Collected Amount - Total Fee + Compensation + Adjustment
 * ⚠ Compensation and Adjustment were zero on every sample row, so their sign is
 * taken as "paid to us" (they add to the billing amount). A file where they
 * move the other way does not add up and is a row error, never a guess.
 *
 * ⭐ The per-parcel Discount is the "hidden" gap on CarryBee's invoice screen:
 * list fee less discount is the real charge (ChatGPT ruling 4: kept separately).
 *
 * ⭐ Weight is the HUB's weight in kg (the fee follows it), stored in grams.
 *
 * ⚠⚠ Recipient Name and Recipient Phone are never read.
 */
final class CarryBeeStatementFormat extends CsvStatementFormat
{
    public function name(): string
    {
        return 'CarryBee invoice CSV';
    }

    public function code(): string
    {
        return 'carrybee_invoice_csv_v1';
    }

    protected function required(): array
    {
        return [
            'Consignment_id', 'merchant_order_id', 'Collectable Amount', 'Collected Amount', 'COD_fee',
            'Delivery_fee', 'Additional_charge', 'Discount', 'Total Fee', 'Compensation', 'Adjustment',
            'Billing_amount', 'Weight', 'Delivery_status', 'Created_date', 'Delivered_date', 'Invoice_number',
        ];
    }

    protected function consignmentColumn(): string
    {
        return 'Consignment_id';
    }

    protected function referenceColumn(): ?string
    {
        return 'Invoice_number';
    }

    protected function line(int $rowNumber, string $consignment, callable $cell): ParsedStatementLine
    {
        $collected = self::money($cell, 'Collected Amount');
        $fee = self::money($cell, 'Delivery_fee');
        $discount = self::money($cell, 'Discount');
        $cod = self::money($cell, 'COD_fee');
        $additional = self::money($cell, 'Additional_charge');
        $total = self::money($cell, 'Total Fee');
        $compensation = self::money($cell, 'Compensation');
        $adjustment = self::money($cell, 'Adjustment');
        $billing = self::money($cell, 'Billing_amount');

        if ($total->minor !== $fee->minor - $discount->minor + $cod->minor + $additional->minor) {
            throw new StatementRowException("Consignment {$consignment} does not add up: Total Fee is not Delivery_fee - Discount + COD_fee + Additional_charge.");
        }

        if ($billing->minor !== $collected->minor - $total->minor + $compensation->minor + $adjustment->minor) {
            throw new StatementRowException("Consignment {$consignment} does not add up: Billing_amount is not Collected Amount - Total Fee + Compensation + Adjustment.");
        }

        return new ParsedStatementLine(
            rowNumber: $rowNumber,
            consignmentNumber: $consignment,
            merchantReference: self::nullable($cell('merchant_order_id')),
            bookedAt: self::nullable($cell('Created_date')),
            collectable: self::money($cell, 'Collectable Amount'),
            collected: $collected,
            deliveryFee: $fee,
            discount: $discount,
            codFee: $cod,
            additionalCharge: $additional,
            finalFee: $total,
            payout: $billing,
            promoDiscount: Money::zero(),
            compensation: $compensation,
            adjustment: $adjustment,
            hubWeightGrams: self::grams($cell('Weight')),
            deliveryStatus: self::nullable(strtolower($cell('Delivery_status'))),
            deliveredOn: self::nullable($cell('Delivered_date')),
        );
    }

    private static function grams(string $kilograms): ?int
    {
        if (self::nullable($kilograms) === null) {
            return null;
        }

        if (! is_numeric($kilograms) || (float) $kilograms < 0) {
            throw new StatementRowException("Weight \"{$kilograms}\" is not a weight in kg.");
        }

        return (int) round((float) $kilograms * 1000);
    }
}
