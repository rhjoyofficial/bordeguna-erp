<?php

namespace App\Modules\Sales\Support;

use Normalizer;
use RuntimeException;

/**
 * Reads a selling-price CSV, and refuses anything it cannot read unambiguously.
 *
 * ⚠⚠ THIS FILE IS ALSO THE CATALOGUE FILE. Joy maintains one spreadsheet
 * carrying both the products and their four price tiers, so unlike
 * `ProductCatalogueFile` this reader cannot treat an unrecognised column as
 * fatal - it would reject the very file it exists to read.
 *
 * ⚠ But the lesson that rule was built from still applies: a column headed
 * `retail` instead of `retail_price` would import perfectly and silently drop
 * every retail price. So the compromise is two-sided:
 *   - every column this reader does not use is REPORTED in the plan, so a
 *     typo is visible in the review rather than absent from the result;
 *   - any unused column whose name contains "price" is a hard ERROR, because
 *     that is a price the operator believes is being imported and is not.
 *
 * ⚠⚠ NFC normalisation is applied to every value. The ERP stores decomposed
 * য + nukta (U+09AF U+09BC) while spreadsheets write the precomposed য়
 * (U+09DF); they are visually identical and never compare equal. Matching is
 * on SKU, which is ASCII, but names are shown to a human for verification and
 * must not differ from what the screen shows.
 */
class PriceListFile
{
    /** The only column this importer truly requires. Everything else is optional. */
    public const REQUIRED = ['sku'];

    /**
     * Price columns, mapped to the price-group CODE each one feeds.
     *
     * ⚠ The mapping is here and nowhere else. `price_groups.code` is the
     * identity key (frozen once assigned), so this is the whole contract
     * between a spreadsheet heading and a pricing tier.
     */
    public const PRICE_COLUMNS = [
        'retail_price' => 'RETAIL',
        'dealer_price' => 'DEALER',
        'distributor_price' => 'DISTRIBUTOR',
        'corporate_price' => 'CORPORATE',
    ];

    /** Read for meaning, not just carried past. */
    public const USED = ['sku', 'name', 'product_name', 'is_active', 'min_quantity', 'effective_from', 'note'];

    /**
     * Columns that carry a price and are knowingly NOT a selling price.
     *
     * ⚠⚠ This list is the difference between a guard and an obstruction. The
     * rule below rejects any unused column containing "price", because that is
     * how a whole pricing tier goes missing on a typo. But `cost_price` is a
     * real column in Bor De Guna's catalogue file - a PURCHASE cost, deliberately
     * not a selling price - and rejecting it made the importer refuse the only
     * file it exists to read. Found by running it against the real file rather
     * than against a fixture that agreed with the rule.
     *
     * ⚠ Naming them is the point. A typo like `retale_price` still errors; only
     * these specific, understood columns are waved past.
     */
    public const NOT_A_SELLING_PRICE = ['cost_price', 'mrp', 'online_discount', 'purchase_price'];

    /**
     * @return array{rows: list<array{line:int, values:array<string,string>}>, ignored: list<string>}
     */
    public static function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Cannot read price file: {$path}");
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Cannot open price file: {$path}");
        }

        try {
            $header = fgetcsv($handle);

            if ($header === false || $header === [null]) {
                throw new RuntimeException('The price file is empty.');
            }

            $header = self::normaliseHeader($header);
            $ignored = self::assertHeaderIsUsable($header);

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

            return ['rows' => $rows, 'ignored' => $ignored];
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

            // ⚠⚠ Excel writes a UTF-8 BOM. Without stripping it the first column
            // reads as "\u{FEFF}sku", the required-column check fails, and the
            // message gives no hint why: the two strings look identical in every
            // terminal that will ever print them.
            if ($index === 0) {
                $name = preg_replace('/^\x{FEFF}/u', '', $name) ?? $name;
            }

            $clean[] = strtolower(trim($name));
        }

        return $clean;
    }

    /**
     * @param  list<string>  $header
     * @return list<string> the columns that will be ignored
     */
    private static function assertHeaderIsUsable(array $header): array
    {
        $missing = array_diff(self::REQUIRED, $header);

        if ($missing !== []) {
            throw new RuntimeException('The file is missing mandatory column(s): '.implode(', ', $missing).'.');
        }

        $duplicates = array_keys(array_filter(array_count_values($header), fn ($n) => $n > 1));

        if ($duplicates !== []) {
            throw new RuntimeException('The file repeats column(s): '.implode(', ', $duplicates).'.');
        }

        $known = array_merge(self::USED, array_keys(self::PRICE_COLUMNS));

        if (array_intersect(array_keys(self::PRICE_COLUMNS), $header) === []) {
            throw new RuntimeException(
                'The file carries no price column at all. Expected at least one of: '.
                implode(', ', array_keys(self::PRICE_COLUMNS)).'.'
            );
        }

        $ignored = array_values(array_diff($header, $known));

        // ⚠⚠ An unused column that calls itself a price is always a mistake.
        // Nobody puts a price in a spreadsheet they do not want imported, so
        // this is a misspelling of a real tier, and importing around it quietly
        // is how a whole pricing tier goes missing without a single error.
        $priceLike = array_values(array_filter(
            $ignored,
            fn ($c) => str_contains($c, 'price') && ! in_array($c, self::NOT_A_SELLING_PRICE, true),
        ));

        if ($priceLike !== []) {
            throw new RuntimeException(
                'Unrecognised price column(s): '.implode(', ', $priceLike).
                '. Expected exactly: '.implode(', ', array_keys(self::PRICE_COLUMNS)).
                '. Rename the column, or remove it if it is not a selling price.'
            );
        }

        return $ignored;
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
