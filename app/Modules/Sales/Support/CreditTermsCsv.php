<?php

namespace App\Modules\Sales\Support;

use App\Modules\Sales\Models\Customer;
use RuntimeException;

/**
 * The credit terms file: one row per credit customer, what they have and what
 * is proposed (Joy, 26 Sep 2026, Q16).
 *
 * ⚠ Downloaded FILLED, not blank: the person granting terms is told who exists
 * and what each one has today, and only types the two `new_` columns. The
 * `current_` columns are there to be read, never to be imported; the plan reads
 * the customer's real figures at the moment of review.
 */
final class CreditTermsCsv
{
    public const COLUMNS = ['code', 'name', 'type', 'current_limit', 'current_days', 'new_limit', 'new_days'];

    public const DESCRIPTIONS = [
        'code' => 'The customer code, as the Customers screen shows it. Do not change it.',
        'name' => 'For reading only.',
        'type' => 'For reading only. Only shops, dealers, distributors and corporate buyers may carry terms.',
        'current_limit' => 'For reading only: the limit in force when the file was made.',
        'current_days' => 'For reading only: the days in force when the file was made.',
        'new_limit' => 'The credit limit in BDT, digits only. Blank keeps the current limit; 0 means no credit.',
        'new_days' => 'Days after the invoice date it falls due, 0 to 365. Blank keeps the current days.',
    ];

    /**
     * @param  iterable<Customer>  $customers
     */
    public static function template(iterable $customers): string
    {
        $rows = [];

        foreach ($customers as $customer) {
            $rows[] = [
                'code' => (string) ($customer->code ?? ''),
                'name' => $customer->name,
                'type' => $customer->type?->label() ?? '',
                'current_limit' => $customer->credit_limit === null ? '' : (string) (float) $customer->credit_limit,
                'current_days' => $customer->credit_days === null ? '' : (string) $customer->credit_days,
                'new_limit' => '',
                'new_days' => '',
            ];
        }

        return self::toCsv($rows);
    }

    /**
     * @param  list<array<string,string>>  $rows
     */
    public static function toCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        // A UTF-8 BOM so Excel reads Bangla names, the same as the customer file.
        fwrite($handle, "\u{FEFF}");
        fputcsv($handle, self::COLUMNS);

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $c) => $row[$c] ?? '', self::COLUMNS));
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * @param  resource  $handle
     * @return list<array{line:int, values:array<string,string>}>
     */
    public static function readHandle($handle): array
    {
        $header = fgetcsv($handle);

        if ($header === false || $header === [null]) {
            throw new RuntimeException('The credit terms file is empty.');
        }

        $header = array_map(fn ($name) => strtolower(trim(str_replace("\u{FEFF}", '', (string) $name))), $header);

        foreach (['code', 'new_limit', 'new_days'] as $required) {
            if (! in_array($required, $header, true)) {
                throw new RuntimeException("The credit terms file needs a \"{$required}\" column. Download the file from the Credit terms screen and fill the new_ columns.");
            }
        }

        $rows = [];
        $line = 1;

        while (($values = fgetcsv($handle)) !== false) {
            $line++;

            if ($values === [null] || trim(implode('', array_map(fn ($v) => (string) $v, $values))) === '') {
                continue;
            }

            if (count($values) > count($header)) {
                throw new RuntimeException("Line {$line} has ".count($values).' values but the header declares '.count($header).'. Fix the file rather than guessing which column is which.');
            }

            $values = array_pad($values, count($header), '');
            $rows[] = ['line' => $line, 'values' => array_map(fn ($v) => trim((string) $v), array_combine($header, $values))];
        }

        return $rows;
    }
}
