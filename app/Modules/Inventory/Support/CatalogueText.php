<?php

namespace App\Modules\Inventory\Support;

use Illuminate\Support\Str;

/**
 * The text rules the catalogue importer applies before anything else looks at a
 * value.
 *
 * ⚠⚠ WHY THIS IS ITS OWN CLASS. Identity in this import is decided by
 * normalised text: a category is its slug, a unit is its abbreviation, and a
 * product is its SKU. If two places normalise even slightly differently, the
 * importer resolves a row to one record and then writes a second - which is
 * precisely the duplicate this whole exercise exists to prevent.
 */
class CatalogueText
{
    /**
     * Trim, and collapse every run of whitespace to a single space.
     *
     * ⚠ Includes the non-breaking space (U+00A0). Spreadsheets produce them
     * constantly, they are invisible on screen, and `trim()` does not remove
     * them - so "Honey " with an nbsp would otherwise be a different category
     * from "Honey" for ever.
     */
    public static function clean(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $value = str_replace(["\u{00A0}", "\u{FEFF}"], ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * The cleaned value, or null when it holds nothing.
     *
     * ⚠⚠ This is where "blank means NULL" is decided, inside the importer.
     * The screen gets that for free from the framework's global
     * ConvertEmptyStringsToNull middleware; a console command does not pass
     * through any middleware at all. An empty string reaching the database
     * would collide on the (business_id, barcode) unique index, where NULL is
     * free to repeat.
     */
    public static function nullIfBlank(?string $value): ?string
    {
        $clean = self::clean($value);

        return $clean === '' ? null : $clean;
    }

    /**
     * The identity key for a category: the slug the database itself is unique
     * on. `Honey`, `honey` and ` Honey ` all become `honey`.
     */
    public static function categoryKey(string $name): string
    {
        return Str::slug(self::clean($name));
    }

    /**
     * The identity key for a unit: its abbreviation, compared case-insensitively.
     *
     * ⚠⚠ Folded here rather than left to the database. `units_of_measure` is
     * unique on (business_id, abbreviation), and that index behaves differently
     * per engine - MySQL's default collation is case-insensitive so `pcs` and
     * `PCS` collide, while SQLite compares case-sensitively and accepts both.
     * Deciding it in PHP makes the import produce the same catalogue on either.
     */
    public static function unitKey(string $abbreviation): string
    {
        return Str::lower(self::clean($abbreviation));
    }

    /**
     * A loose key for spotting near-duplicates: lowercase, letters and digits
     * only. `Sundarban Honey 500g` and `sundarban honey 500 g` collapse
     * together. Used only to WARN, and to compare unit names - never to merge
     * anything.
     *
     * ⚠⚠ LETTERS IN ANY SCRIPT, NOT `a-z0-9`. The first version stripped every
     * character outside ASCII, so every Bangla name collapsed to the EMPTY
     * STRING and all of them compared equal. On a real Bangla catalogue that
     * produced three separate failures: every product after the first was
     * warned as a near-duplicate of the first; two genuinely different Bangla
     * unit names compared equal, which raised a FALSE blocking error about one
     * name under two abbreviations; and the near-duplicate CATEGORY check,
     * which skips empty keys, silently did nothing at all. Bor De Guna's
     * catalogue is in Bangla, so all three were certainties, not risks.
     */
    public static function looseKey(string $value): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', Str::lower(self::clean($value)));
    }

    /**
     * Edit distance in CHARACTERS, not bytes.
     *
     * ⚠⚠ PHP's levenshtein() counts bytes, and every Bangla character is three
     * of them - all sharing the same two lead bytes (0xE0 0xA6/0xA7). So two
     * completely unrelated Bangla words score a tiny byte distance purely
     * because their encoding rhymes: মধু and ঘি came out "nearly identical".
     * strlen() has the same flaw, so the length guard that was meant to exempt
     * short words let 2-character Bangla words straight through at 6 bytes.
     *
     * Only ever used to WARN, and the catalogue holds a few dozen categories,
     * so the quadratic table is not worth optimising.
     */
    public static function distance(string $a, string $b): int
    {
        $x = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $y = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $n = count($x);
        $m = count($y);

        if ($n === 0 || $m === 0) {
            return max($n, $m);
        }

        $previous = range(0, $m);

        for ($i = 1; $i <= $n; $i++) {
            $current = [$i];

            for ($j = 1; $j <= $m; $j++) {
                $current[$j] = min(
                    $previous[$j] + 1,
                    $current[$j - 1] + 1,
                    $previous[$j - 1] + ($x[$i - 1] === $y[$j - 1] ? 0 : 1),
                );
            }

            $previous = $current;
        }

        return $previous[$m];
    }

    /**
     * The length of a value in characters, for guards that mean "too short to
     * judge" rather than "too few bytes".
     */
    public static function length(string $value): int
    {
        return mb_strlen($value);
    }
}
