<?php

namespace App\Modules\Inventory\Support;

use Normalizer;
use RuntimeException;

/**
 * Reads the combined catalogue + price file for the SKU migration.
 *
 * ⚠ Follows `PriceListFile`'s tolerance rather than `ProductCatalogueFile`'s
 * strictness: this is Joy's one spreadsheet carrying products AND price tiers,
 * so an unrecognised column cannot be fatal - it would reject the very file
 * this command exists to read. The price columns are somebody else's business
 * (the price importer's); they are ignored here and reported once, so a typo
 * is visible in the review rather than absent from the result.
 *
 * ⚠⚠ The matching signals ARE mandatory. The migration pairs file rows to live
 * products by base name, brand, grade and pack, so a file missing
 * `product_name`, `variant_title` or `pack_size` cannot be matched - and a
 * header typo on one of them would silently degrade every pairing to "new
 * product". Missing any of them stops the run before a single row is read.
 *
 * ⚠⚠ NFC normalisation on every value. The ERP stores decomposed য + nukta
 * while spreadsheets write precomposed য়; identical on screen, never equal as
 * strings. Base-name identity is decided on normalised text on BOTH sides.
 */
class CatalogueMigrationFile
{
    public const MANDATORY = [
        'sku', 'brand', 'name', 'product_name', 'variant_title', 'pack_size',
        'category', 'type', 'unit', 'unit_abbr', 'is_active',
    ];

    /** Carried through to the import planner when present. */
    public const PASSTHROUGH = ['barcode', 'description', 'low_stock_threshold'];

    /**
     * @return array{rows: list<array{line:int, values:array<string,string>}>, ignored: list<string>}
     */
    public static function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Cannot read catalogue file: {$path}");
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Cannot open catalogue file: {$path}");
        }

        try {
            $header = fgetcsv($handle);

            if ($header === false || $header === [null]) {
                throw new RuntimeException('The catalogue file is empty.');
            }

            $header = self::normaliseHeader($header);

            $missing = array_diff(self::MANDATORY, $header);

            if ($missing !== []) {
                throw new RuntimeException(
                    'The file is missing column(s) the migration matches on: '.implode(', ', $missing).'.'
                );
            }

            $duplicates = array_keys(array_filter(array_count_values($header), fn ($n) => $n > 1));

            if ($duplicates !== []) {
                throw new RuntimeException('The file repeats column(s): '.implode(', ', $duplicates).'.');
            }

            $ignored = array_values(array_diff($header, self::MANDATORY, self::PASSTHROUGH));

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

                $rows[] = ['line' => $line, 'values' => array_combine($header, self::normalise($values))];
            }

            return ['rows' => $rows, 'ignored' => $ignored];
        } finally {
            fclose($handle);
        }
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

            // ⚠⚠ Excel writes a UTF-8 BOM; without stripping it the first
            // column reads as "\u{FEFF}sku" and the mandatory check fails with
            // a message that looks identical to a passing header.
            if ($index === 0) {
                $name = preg_replace('/^\x{FEFF}/u', '', $name) ?? $name;
            }

            $clean[] = strtolower(CatalogueText::clean($name));
        }

        return $clean;
    }

    /**
     * @param  list<string|null>  $values
     * @return list<string>
     */
    private static function normalise(array $values): array
    {
        return array_map(
            fn ($v) => Normalizer::normalize((string) $v, Normalizer::FORM_C) ?: (string) $v,
            $values,
        );
    }

    /**
     * @param  list<string|null>  $values
     */
    private static function isBlankRow(array $values): bool
    {
        foreach ($values as $value) {
            if (CatalogueText::clean((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
