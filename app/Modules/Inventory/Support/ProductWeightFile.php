<?php

namespace App\Modules\Inventory\Support;

use RuntimeException;

/**
 * Reads the weights sheet (courier hub slice 3, 11 Oct 2026): one row per
 * product, matched on SKU, with its weight in whole grams.
 *
 * ⚠ Strict about columns for the catalogue's reason: a column headed `weight`
 * instead of `weight_grams` would read as a file of blanks and change nothing,
 * silently. `name` and `net_content` are printed on the template to help the
 * person filling it in, and are never matched on or written.
 */
class ProductWeightFile
{
    public const MANDATORY = ['sku', 'weight_grams'];

    public const OPTIONAL = ['name', 'net_content'];

    /** The template's own order. */
    public const TEMPLATE = ['sku', 'name', 'net_content', 'weight_grams'];

    /**
     * @return list<array{line:int, values:array<string,string>}>
     */
    public static function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Cannot read the weights file: {$path}");
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Cannot open the weights file: {$path}");
        }

        try {
            $header = fgetcsv($handle);

            if ($header === false || $header === [null]) {
                throw new RuntimeException('The weights file is empty.');
            }

            $clean = [];

            foreach ($header as $index => $name) {
                $name = (string) $name;

                // ⚠⚠ Excel writes a UTF-8 BOM in front of the first column.
                if ($index === 0) {
                    $name = preg_replace('/^\x{FEFF}/u', '', $name) ?? $name;
                }

                $clean[] = strtolower(CatalogueText::clean($name));
            }

            $header = $clean;
            $missing = array_diff(self::MANDATORY, $header);

            if ($missing !== []) {
                throw new RuntimeException('The file is missing column(s): '.implode(', ', $missing).'. Download the template and fill that in.');
            }

            $duplicates = array_keys(array_filter(array_count_values($header), fn ($n) => $n > 1));

            if ($duplicates !== []) {
                throw new RuntimeException('The file repeats column(s): '.implode(', ', $duplicates).'.');
            }

            $unknown = array_diff($header, self::MANDATORY, self::OPTIONAL);

            if ($unknown !== []) {
                throw new RuntimeException(
                    'The file has unrecognised column(s): '.implode(', ', $unknown).'. Expected only: '.implode(', ', self::TEMPLATE).'.'
                );
            }

            $rows = [];
            $line = 1;

            while (($values = fgetcsv($handle)) !== false) {
                $line++;

                if ($values === [null]) {
                    continue;
                }

                $values = array_map(fn ($value) => CatalogueText::clean((string) $value), $values);

                // A row of nothing but separators, left under the last real row.
                if (implode('', $values) === '') {
                    continue;
                }

                if (count($values) !== count($header)) {
                    throw new RuntimeException(
                        "Line {$line} has ".count($values).' values but the header declares '.count($header).
                        '. Fix the file rather than guessing which column is which.'
                    );
                }

                $rows[] = ['line' => $line, 'values' => array_combine($header, $values)];
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }
}
