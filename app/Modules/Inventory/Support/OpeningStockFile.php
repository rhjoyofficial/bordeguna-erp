<?php

namespace App\Modules\Inventory\Support;

use Normalizer;
use RuntimeException;

/**
 * Reads the opening stock count, and refuses anything it cannot read
 * unambiguously.
 *
 * ⚠⚠ THIS FILE DECIDES EVERY MARGIN THE BUSINESS REPORTS until the opening
 * stock is sold through, because sales cost of goods sold is computed from the
 * batches it creates. It is also written ONCE PER WAREHOUSE, ever. So the
 * reader is strict on purpose: an unknown column is fatal, exactly as it is for
 * the catalogue, because a column headed `qty` instead of `quantity` would
 * import a file of zero-quantity lines and the document could never be redone.
 *
 * ⚠ NFC normalisation on every value: the ERP stores decomposed য + nukta and
 * spreadsheets write the precomposed য়. Matching is on SKU and warehouse code,
 * both ASCII, but names are shown to a human for verification.
 */
class OpeningStockFile
{
    public const MANDATORY = ['sku', 'warehouse', 'quantity', 'unit_cost'];

    /**
     * ⚠ `unit` is optional but CHECKED when present, against the product's own
     * unit of measure. A count sheet that says "kg" for a product held in
     * pieces is a real and expensive mistake, and the column exists so the
     * counter's intent can be compared with the system's.
     */
    public const OPTIONAL = ['unit', 'batch_number', 'expiry_date', 'note', 'product_name'];

    /**
     * @return list<array{line:int, values:array<string,string>}>
     */
    public static function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Cannot read opening stock file: {$path}");
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Cannot open opening stock file: {$path}");
        }

        try {
            $header = fgetcsv($handle);

            if ($header === false || $header === [null]) {
                throw new RuntimeException('The opening stock file is empty.');
            }

            $header = self::normaliseHeader($header);
            self::assertHeaderIsUsable($header);

            $rows = [];
            $line = 1;

            while (($values = fgetcsv($handle)) !== false) {
                $line++;

                if ($values === [null] || self::isBlankRow($values)) {
                    continue;
                }

                if (count($values) !== count($header)) {
                    throw new RuntimeException(
                        "Line {$line} has ".count($values).' values but the header declares '.count($header).
                        '. Fix the file rather than guessing which column is which.'
                    );
                }

                $rows[] = [
                    'line' => $line,
                    'values' => array_map(self::clean(...), array_combine($header, $values)),
                ];
            }

            if ($rows === []) {
                throw new RuntimeException('The opening stock file has a header and no rows.');
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    public static function clean(?string $value): string
    {
        $value = trim((string) $value);

        return Normalizer::normalize($value, Normalizer::FORM_C) ?: $value;
    }

    /**
     * @param  list<string|null>  $header
     * @return list<string>
     */
    private static function normaliseHeader(array $header): array
    {
        $clean = [];

        foreach ($header as $index => $name) {
            $name = (string) $name;

            // ⚠⚠ Excel writes a UTF-8 BOM; without stripping it the first column
            // reads as "\u{FEFF}sku" and the failure message names two strings
            // that look identical in every terminal.
            if ($index === 0) {
                $name = preg_replace('/^\x{FEFF}/u', '', $name) ?? $name;
            }

            $clean[] = strtolower(trim($name));
        }

        return $clean;
    }

    /**
     * @param  list<string>  $header
     */
    private static function assertHeaderIsUsable(array $header): void
    {
        $missing = array_diff(self::MANDATORY, $header);

        if ($missing !== []) {
            throw new RuntimeException('The file is missing mandatory column(s): '.implode(', ', $missing).'.');
        }

        $duplicates = array_keys(array_filter(array_count_values($header), fn ($n) => $n > 1));

        if ($duplicates !== []) {
            throw new RuntimeException('The file repeats column(s): '.implode(', ', $duplicates).'.');
        }

        // ⚠⚠ FATAL, not a warning - and unlike the price file there is no
        // tolerance here. A price list can be re-run tomorrow; an opening stock
        // document exists once per warehouse for the life of the business.
        $unknown = array_diff($header, self::MANDATORY, self::OPTIONAL);

        if ($unknown !== []) {
            throw new RuntimeException(
                'The file has unrecognised column(s): '.implode(', ', $unknown).
                '. Expected only: '.implode(', ', array_merge(self::MANDATORY, self::OPTIONAL)).'.'
            );
        }
    }

    /**
     * @param  list<string|null>  $values
     */
    private static function isBlankRow(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
