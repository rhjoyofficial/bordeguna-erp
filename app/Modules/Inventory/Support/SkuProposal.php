<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Enums\ProductType;
use Normalizer;

/**
 * Proposes a SKU in the shape this business already uses, and refuses to guess
 * when it cannot.
 *
 * ⚠⚠ A PROPOSAL, NEVER AN ASSIGNMENT (C-SKU-2). The SKU is the identity that
 * the catalogue importer, the price importer and the opening stock importer all
 * match on. Nothing in this class writes; it hands back a string and a reason,
 * and a human decides.
 *
 * The convention, read off the live catalogue rather than invented:
 *   finished good   {BRAND}-{CATEGORY}-{ITEM}-{SIZE}[-{GRADE}]   BNC-DAT-AJW-1KG-LRG
 *   raw material    RM-{CATEGORY}-{ITEM}[-{GRADE}]               RM-HON-MAN
 *   packaging       {CATEGORY}-{SIZE}                            PK-JAR-500G
 *
 * ⚠ Raw materials carry no brand, and that is the model's own rule rather than
 * an omission: the same bulk honey becomes LaLaDia or Bionic depending only on
 * what it is packed into.
 *
 * ⚠⚠ THE CATEGORY CODE DECIDES THE SHAPE, which is what makes this flexible
 * without a fourth product type. A plain code such as `HON` is one segment and
 * a raw material using it gets the `RM-` family prefix. A code that already
 * carries its family, such as `PK-JAR`, is used verbatim and no prefix is
 * added - and because that code already names the item, no item segment is
 * taken from the name either, so a 500 g jar is `PK-JAR-500G` and not
 * `PK-JAR-JAR-500G`. Joy can change any of this on the categories screen
 * without touching code.
 */
class SkuProposal
{
    /**
     * Words that carry no identity and must never become the item segment.
     * "Bulk Honey" is honey; "The Ghee" is ghee.
     */
    private const NOISE = ['the', 'a', 'an', 'of', 'and', 'bulk', 'raw', 'pure', 'fresh', 'premium', 'super', 'royal', 'special'];

    /**
     * Grades that are recognised as a trailing segment, mapped to their code.
     * Anything else in trailing brackets is ignored rather than abbreviated,
     * because an unrecognised grade abbreviated by rule is how BNC-SED and
     * BNC-SEE end up side by side.
     */
    private const GRADES = [
        'large' => 'LRG',
        'jambo' => 'JAM',
        'jumbo' => 'JAM',
        'premium' => 'PRM',
        'small' => 'SML',
        'medium' => 'MED',
        'regular' => 'REG',
    ];

    /**
     * @param  callable(string): bool  $isTaken  returns true when a SKU already
     *                                           exists in this business
     * @param  array<string,string>  $knownItems  lower-cased first word => the
     *                                            item segment the catalogue
     *                                            already uses for it
     * @param  list<string>  $skipWords  lower-cased words that are never the item
     *                                   segment: the business's brand names and
     *                                   codes (F7, 17 Oct 2026). "LaLaDia Pure Ghee
     *                                   500g" is ghee, not "LAL".
     */
    public function __construct(private $isTaken, private array $knownItems = [], private array $skipWords = []) {}

    /**
     * The words a set of brands contributes to product names, lower-cased:
     * every English word of each name and each code. "Bionic-Garden" gives
     * bionic and garden.
     *
     * @param  iterable<array{name: ?string, code: ?string}>  $brands
     * @return list<string>
     */
    public static function brandWords(iterable $brands): array
    {
        $words = [];

        foreach ($brands as $brand) {
            foreach ([$brand['name'] ?? '', $brand['code'] ?? ''] as $text) {
                foreach (preg_split('/[^A-Za-z]+/', (string) $text) ?: [] as $word) {
                    if ($word !== '') {
                        $words[] = strtolower($word);
                    }
                }
            }
        }

        return array_values(array_unique($words));
    }

    /**
     * ⚠⚠ THE CONVENTION IS READ OFF THE CATALOGUE, NOT INVENTED. The live item
     * segments are human abbreviations that no rule reproduces: Beetroot is
     * BET, Black is BLK, Beef is BEF. Taking the first three letters would
     * propose BNC-OIL-BLA-500ML next to the existing BNC-OIL-BLK-200ML and
     * split one product into two families - the precise failure C-SKU-1 exists
     * to prevent, one level down.
     *
     * So before falling back to any rule, ask what this business already calls
     * this word.
     *
     * @param  iterable<array{name: string, sku: string}>  $products
     * @param  list<string>  $skipWords  brand words, never learned as an item (F7)
     * @return array<string,string>
     */
    public static function learnFrom(iterable $products, array $skipWords = []): array
    {
        $known = [];

        foreach ($products as $product) {
            $segments = explode('-', strtoupper(CatalogueText::clean($product['sku'])));

            // {BRAND|RM}-{CATEGORY}-{ITEM}. Packaging has no item segment, and
            // its third segment is a size, so anything not purely alphabetic is
            // ignored rather than learned.
            if (count($segments) < 3 || preg_match('/^[A-Z]{2,4}$/', $segments[2]) !== 1) {
                continue;
            }

            $word = self::firstSignificantWord(self::normalise($product['name']), $skipWords);

            if ($word === null) {
                continue;
            }

            // ⚠ First writer wins, and a disagreement is dropped entirely. If
            // two products spell the same word two ways, the catalogue is
            // already inconsistent and picking a side silently would bless one
            // of them.
            if (array_key_exists($word, $known) && $known[$word] !== $segments[2]) {
                $known[$word] = null;

                continue;
            }

            $known[$word] ??= $segments[2];
        }

        return array_filter($known, fn ($segment) => $segment !== null);
    }

    /**
     * @param  string|null  $brandCode  the brand's code, e.g. BNC. Ignored for raw materials.
     * @param  string|null  $categoryCode  the category's code, e.g. DAT.
     * @param  string|null  $categoryName  the category's name. A raw material in a
     *                                     "Packaging ..." category whose code is one
     *                                     plain segment (LBL) is packaging all the
     *                                     same, and takes the PK- family (F4).
     * @return array{sku: ?string, reason: ?string, note: ?string}
     *                                                             `sku` is null when nothing can be proposed and `reason`
     *                                                             says what is missing. `note` explains a suffix.
     */
    public function propose(string $name, ProductType $type, ?string $brandCode, ?string $categoryCode, ?string $categoryName = null): array
    {
        $name = self::normalise($name);

        if ($name === '') {
            return self::nothing('Type the product name first, the SKU is built from it.');
        }

        $categoryCode = self::categoryCode($categoryCode);

        if ($categoryCode === null) {
            return self::nothing('This category has no SKU code yet. Set one on the categories screen and the SKU builds itself.');
        }

        $isRawMaterial = $type === ProductType::RawMaterial;

        // ⚠ F4 (practice run, 17 Oct 2026): "Packaging Labels" carried the code
        // LBL and its labels came out RM-LBL-GHEE beside PK-JAR jars. Packaging
        // is packaging whatever its code says, so a plain code gains PK-.
        if ($isRawMaterial && ! str_contains($categoryCode, '-') && self::isPackagingCategory($categoryName)) {
            $categoryCode = 'PK-'.$categoryCode;
        }

        // A category code that already carries its family (`PK-JAR`) names the
        // item on its own, so it takes no `RM-` prefix and no item segment.
        $carriesOwnFamily = str_contains($categoryCode, '-');

        if ($isRawMaterial) {
            $prefix = $carriesOwnFamily ? null : 'RM';
        } else {
            $prefix = self::segment($brandCode);

            if ($prefix === null) {
                return self::nothing('Choose a brand first. A finished good takes its brand code as the first part of the SKU.');
            }
        }

        $item = $carriesOwnFamily ? null : $this->itemSegment($name);
        $size = self::sizeSegment($name);
        $grade = self::gradeSegment($name);

        // ⚠ A bulk raw material has no pack size on purpose: it is measured, not
        // packed. Packaging keeps its size, because that is the only thing that
        // tells one jar from another.
        $parts = array_values(array_filter([
            $prefix,
            $categoryCode,
            $item,
            ($isRawMaterial && ! $carriesOwnFamily) ? null : $size,
            $grade,
        ], fn ($part) => $part !== null && $part !== ''));

        if (count($parts) < 3 && ! $carriesOwnFamily) {
            return self::nothing('The name does not carry enough to build a SKU from. Add the item and its size, for example "Ajwa Date - 1 kg".');
        }

        // `PK-JAR` alone identifies nothing. A packaging item with no size in
        // its name has to be typed by hand rather than guessed at.
        if ($carriesOwnFamily && $size === null && $grade === null) {
            return self::nothing('This name has no size in it, so there is nothing to tell one from another. Add the size, for example "Jar - 500 g", or type the SKU by hand.');
        }

        return $this->free(implode('-', $parts));
    }

    /**
     * Suffix until the SKU is free, and say so when it had to.
     *
     * @return array{sku: string, reason: null, note: ?string}
     */
    private function free(string $base): array
    {
        if (! ($this->isTaken)($base)) {
            return ['sku' => $base, 'reason' => null, 'note' => null];
        }

        // ⚠ Bounded. An unbounded loop against a callback that always returns
        // true would hang the request rather than fail it.
        for ($n = 2; $n <= 50; $n++) {
            $candidate = $base.'-'.$n;

            if (! ($this->isTaken)($candidate)) {
                return [
                    'sku' => $candidate,
                    'reason' => null,
                    'note' => $base.' is already used by another product, so this one is numbered.',
                ];
            }
        }

        return self::nothing($base.' and fifty numbered variants of it are all taken. Type the SKU by hand.');
    }

    /**
     * @return array{sku: null, reason: string, note: null}
     */
    private static function nothing(string $reason): array
    {
        return ['sku' => null, 'reason' => $reason, 'note' => null];
    }

    /**
     * ⚠⚠ NFC FIRST, then ASCII only. Product names here are bilingual and
     * production stores decomposed য + nukta, which is a different string from
     * the precomposed form that looks identical on screen. Bangla never
     * contributes letters to a SKU, so it is normalised and then dropped - but
     * it is normalised first, so that dropping it is deterministic.
     */
    private static function normalise(string $value): string
    {
        $value = CatalogueText::clean($value);

        if (class_exists(Normalizer::class)) {
            $value = Normalizer::normalize($value, Normalizer::FORM_C) ?: $value;
        }

        return $value;
    }

    /**
     * A stored code, upper-cased and validated. Anything that is not plain
     * letters and digits is refused rather than stripped into something else.
     */
    private static function segment(?string $code): ?string
    {
        $code = strtoupper(CatalogueText::clean($code));

        return preg_match('/^[A-Z0-9]{2,8}$/', $code) === 1 ? $code : null;
    }

    /**
     * A category code, which may be one segment (`HON`) or a hyphen-joined
     * family path (`PK-JAR`). Each segment is validated on its own so that a
     * stray hyphen cannot smuggle an empty segment into the middle of a SKU.
     */
    private static function categoryCode(?string $code): ?string
    {
        $code = strtoupper(CatalogueText::clean($code));

        if ($code === '') {
            return null;
        }

        foreach (explode('-', $code) as $part) {
            if (preg_match('/^[A-Z0-9]{2,8}$/', $part) !== 1) {
                return null;
            }
        }

        return $code;
    }

    /**
     * The first significant English word of the name, three letters.
     *
     * The name is cut at the first bracket, so `Ajwa Date (আজওয়া খেজুর) - 1 kg`
     * contributes only `Ajwa Date`, and `AJW` comes out of it.
     */
    private function itemSegment(string $name): ?string
    {
        $word = self::firstSignificantWord($name, $this->skipWords);

        if ($word === null) {
            return null;
        }

        // What the catalogue already calls this, if it calls it anything.
        if (isset($this->knownItems[$word])) {
            return $this->knownItems[$word];
        }

        return strtoupper(self::abbreviate($word));
    }

    /**
     * The first word of the name that carries identity, lower-cased.
     *
     * The name is cut at the first bracket, so `Ajwa Date (আজওয়া খেজুর) - 1 kg`
     * contributes only `Ajwa Date`, and `ajwa` comes out of it.
     */
    private static function firstSignificantWord(string $name, array $skipWords = []): ?string
    {
        $english = preg_split('/[\(\[]/u', $name)[0] ?? $name;
        $english = (string) preg_replace('/[^A-Za-z ]/', ' ', $english);

        foreach (preg_split('/\s+/', trim($english)) ?: [] as $word) {
            if ($word === '' || strlen($word) < 3) {
                continue;
            }

            if (in_array(strtolower($word), self::NOISE, true) || in_array(strtolower($word), $skipWords, true)) {
                continue;
            }

            return strtolower($word);
        }

        return null;
    }

    /** "Packaging Jars", "packaging labels", "Packaging": the packaging family. */
    private static function isPackagingCategory(?string $name): bool
    {
        return preg_match('/^\s*packag/i', (string) $name) === 1;
    }

    /**
     * Three letters from a word the catalogue has never seen.
     *
     * ⚠ Consecutive repeated letters collapse first, which is what turns
     * Beetroot into BET rather than BEE and Beef into BEF rather than BEE - and
     * BEE for both would have been the same segment for two unrelated products.
     * It is a rule, not the rule: where the catalogue already has an opinion,
     * `knownItems` overrides this entirely.
     */
    private static function abbreviate(string $word): string
    {
        $collapsed = (string) preg_replace('/(.)\1+/', '$1', $word);

        return substr(strlen($collapsed) >= 3 ? $collapsed : $word, 0, 3);
    }

    /**
     * The pack size as it is written in the name: `- 1 kg` becomes 1KG,
     * `- 200 ml` becomes 200ML, `- 205 g` becomes 205G.
     *
     * ⚠ Returns null rather than guessing. A name with no size gets no size
     * segment, which is correct for bulk and honest for everything else.
     */
    private static function sizeSegment(string $name): ?string
    {
        if (preg_match('/(\d+(?:\.\d+)?)\s*(kg|g|ml|l|ltr|litre|liter|pcs|pc)\b/i', $name, $m) !== 1) {
            return null;
        }

        // ⚠ Trailing zeros are only noise AFTER a decimal point. `1.0` is `1`,
        // but `500` is emphatically not `5` - stripping blindly turned every
        // 500 g pack into a 5 g one.
        $number = str_contains($m[1], '.')
            ? rtrim(rtrim($m[1], '0'), '.')
            : $m[1];

        $unit = strtolower($m[2]);

        $unit = match ($unit) {
            'ltr', 'litre', 'liter' => 'L',
            'pc' => 'PCS',
            default => strtoupper($unit),
        };

        return str_replace('.', '', $number).$unit;
    }

    /**
     * A recognised grade in trailing brackets, e.g. `(Large)` becomes LRG.
     */
    private static function gradeSegment(string $name): ?string
    {
        if (preg_match('/\(([^()]*)\)\s*$/u', $name, $m) !== 1) {
            return null;
        }

        return self::GRADES[strtolower(trim($m[1]))] ?? null;
    }
}
