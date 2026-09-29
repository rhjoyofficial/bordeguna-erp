<?php

namespace App\Modules\Inventory\Support;

use RuntimeException;

/**
 * Reads the catalogue CSV into rows, and refuses anything it cannot read
 * unambiguously.
 *
 * ⚠ Header problems are fatal here rather than collected as row errors,
 * because a file whose columns are not understood produces meaningless row
 * errors - hundreds of them, all saying the same thing badly.
 */
class ProductCatalogueFile
{
    public const MANDATORY = ['sku', 'name', 'category', 'unit', 'unit_abbr', 'type'];

    // ⚠ `brand` is OPTIONAL because raw materials have no brand (D-5). A
    // finished good without one is a warning, not an error (D-3).
    public const OPTIONAL = ['brand', 'barcode', 'description', 'low_stock_threshold', 'is_active', ...self::PRODUCT_DETAILS];

    /**
     * ⭐ Product details a catalogue file may carry (29 Sep 2026), so a whole
     * shop catalogue loads without opening every product dialog. Each is
     * applied ONLY when its column is present: an older file without them
     * never blanks what somebody set by hand.
     *
     *   tracks_expiry            yes/no
     *   shelf_life_warning_days  whole days
     *   content_quantity         the net content, e.g. 500
     *   content_unit             its unit abbreviation, e.g. g, mL (must exist)
     */
    public const PRODUCT_DETAILS = ['tracks_expiry', 'shelf_life_warning_days', 'content_quantity', 'content_unit'];

    /**
     * @return list<array{line:int, values:array<string,string>}>
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
            self::assertHeaderIsUsable($header);

            $rows = [];
            $line = 1;

            while (($values = fgetcsv($handle)) !== false) {
                $line++;

                // A trailing newline yields a single null field. Not a row.
                if ($values === [null]) {
                    continue;
                }

                // ⚠ A row of nothing but separators is what a spreadsheet
                // leaves behind under the last real row. Skipping it silently
                // is right; treating it as a product with no SKU is not.
                if (self::isBlankRow($values)) {
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

    /**
     * @param  list<string|null>  $header
     * @return list<string>
     */
    private static function normaliseHeader(array $header): array
    {
        $clean = [];

        foreach ($header as $index => $name) {
            $name = (string) $name;

            // ⚠⚠ Excel writes a UTF-8 BOM. Without stripping it the first
            // column reads as "\u{FEFF}sku", the mandatory-header check fails,
            // and the message gives no hint why - the two strings look
            // identical in every terminal.
            if ($index === 0) {
                $name = preg_replace('/^\x{FEFF}/u', '', $name) ?? $name;
            }

            $clean[] = strtolower(CatalogueText::clean($name));
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

        // ⚠⚠ An unknown column is an ERROR, not a warning. A file headed
        // `barcodes` instead of `barcode` imports perfectly and silently drops
        // every barcode - and nothing downstream can ever tell that it did.
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
            if (CatalogueText::clean((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
