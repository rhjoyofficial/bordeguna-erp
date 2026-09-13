<?php

namespace App\Modules\Sales\Support\CourierStatements;

use App\Modules\Accounting\Support\Money;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Pathao's invoice CSV, as downloaded from its merchant panel (one real file read
 * on 13 September 2026, research doc §5).
 *
 * ⭐ The arithmetic every row must satisfy, checked against the real file and the
 * panel's totals: Final_Fee = Delivery_Fee - Discount + COD_fee + Additional_Charge,
 * and Payout = Collected_Amount - Final_Fee. A row that breaks either is a file
 * that has been edited or misread, and the whole file is refused naming it.
 *
 * ⚠ Promo_Discount and Compensation_Cost were zero on every row seen, so which
 * way they move the money is unknown. A row carrying either is refused rather
 * than booked on a guess.
 *
 * ⚠ Recipient_Name and Recipient_Phone are read past and never kept.
 */
final class PathaoStatementFormat implements CourierStatementFormat
{
    private const REQUIRED = [
        'Consignment_ID', 'Created_Date', 'Collected_Amount', 'Collectable_Amount', 'COD_fee',
        'Delivery_Fee', 'Final_Fee', 'Discount', 'Additional_Charge', 'Compensation_Cost',
        'Promo_Discount', 'Payout', 'Merchant_Order_ID',
    ];

    public function name(): string
    {
        return 'Pathao invoice CSV';
    }

    public function parse(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? '';
        $rows = array_values(array_filter(
            array_map('str_getcsv', preg_split('/\r\n|\n|\r/', $contents) ?: []),
            fn (array $row) => ! (count($row) === 1 && trim((string) $row[0]) === ''),
        ));

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'The file is empty.']);
        }

        $header = array_map(fn ($cell) => trim((string) $cell), array_shift($rows));
        $missing = array_diff(self::REQUIRED, $header);

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'file' => 'This is not a Pathao invoice CSV: it has no '.implode(', ', $missing).' column.',
            ]);
        }

        $index = array_flip($header);
        $lines = [];
        $seen = [];

        foreach ($rows as $offset => $row) {
            $rowNumber = $offset + 2;
            $cell = fn (string $column) => trim((string) ($row[$index[$column]] ?? ''));

            $number = $cell('Consignment_ID');

            if ($number === '') {
                throw ValidationException::withMessages(['file' => "Row {$rowNumber} has no consignment id."]);
            }

            if (isset($seen[strtoupper($number)])) {
                throw ValidationException::withMessages(['file' => "Consignment {$number} appears twice in the file (rows {$seen[strtoupper($number)]} and {$rowNumber})."]);
            }

            $seen[strtoupper($number)] = $rowNumber;

            try {
                $money = fn (string $column) => Money::ofTaka($cell($column));

                $line = new ParsedStatementLine(
                    rowNumber: $rowNumber,
                    consignmentNumber: $number,
                    merchantReference: in_array(strtoupper($cell('Merchant_Order_ID')), ['', 'N/A'], true) ? null : $cell('Merchant_Order_ID'),
                    bookedAt: $cell('Created_Date') ?: null,
                    collectable: $money('Collectable_Amount'),
                    collected: $money('Collected_Amount'),
                    deliveryFee: $money('Delivery_Fee'),
                    discount: $money('Discount'),
                    codFee: $money('COD_fee'),
                    additionalCharge: $money('Additional_Charge'),
                    finalFee: $money('Final_Fee'),
                    payout: $money('Payout'),
                );

                $promo = $money('Promo_Discount');
                $compensation = $money('Compensation_Cost');
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(['file' => "Row {$rowNumber} (consignment {$number}): {$e->getMessage()}"]);
            }

            if (! $promo->isZero() || ! $compensation->isZero()) {
                throw ValidationException::withMessages([
                    'file' => "Row {$rowNumber} (consignment {$number}) carries a promo discount or compensation, which is not handled yet. Nothing was imported.",
                ]);
            }

            $expectedFinal = $line->deliveryFee->minor - $line->discount->minor + $line->codFee->minor + $line->additionalCharge->minor;
            $expectedPayout = $line->collected->minor - $line->finalFee->minor;

            if ($expectedFinal !== $line->finalFee->minor || $expectedPayout !== $line->payout->minor) {
                throw ValidationException::withMessages([
                    'file' => "Row {$rowNumber} (consignment {$number}) does not add up: its fees or payout disagree with its own columns. Nothing was imported.",
                ]);
            }

            $lines[] = $line;
        }

        if ($lines === []) {
            throw ValidationException::withMessages(['file' => 'The file has a header and no parcels.']);
        }

        return $lines;
    }
}
