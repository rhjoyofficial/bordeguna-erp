<?php

namespace App\Modules\Sales\Couriers\Locations;

/**
 * How two spellings of one place are made comparable.
 *
 * ⚠⚠ TWO STRENGTHS, ON PURPOSE. `key()` folds only what cannot change the
 * place: case, punctuation, spaces, the renamed-city spellings below and
 * words that are labels rather than names ("Upazila"). Equal keys are
 * the same place, and only that is allowed to apply a map on its own.
 * `looseKey()` also drops vowels and doubled letters, which is how "Brahamanbaria"
 * meets "Brahmanbaria", and how two different villages can meet too; so a
 * loose match is only ever a suggestion.
 */
final class PlaceName
{
    /**
     * Spellings couriers and customers use for one name, folded to one token.
     * Whole tokens only: "bogra" becomes "bogura", "bograpara" stays.
     */
    public const VARIANTS = [
        'chittagong' => 'chattogram',
        'ctg' => 'chattogram',
        'bogra' => 'bogura',
        'jessore' => 'jashore',
        'jasore' => 'jashore',
        'comilla' => 'cumilla',
        'barisal' => 'barishal',
        'gopalgonj' => 'gopalganj',
        'jhenidah' => 'jhenaidah',
        'jhenaidaha' => 'jhenaidah',
        'jhalokathi' => 'jhalakathi',
        'jhalokati' => 'jhalakathi',
        'netrakona' => 'netrokona',
        'narshingdi' => 'narsingdi',
        'munsiganj' => 'munshiganj',
        'khagrachari' => 'khagrachhari',
        'coxs' => 'coxsbazar',
        'coxsbazar' => 'coxsbazar',
        'cantt' => 'cantonment',
        'cant' => 'cantonment',
        'brahamanbaria' => 'brahmanbaria',
        'bbaria' => 'brahmanbaria',
    ];

    /**
     * Words that label a place rather than name it.
     *
     * ⚠ "Thana" and "Model" are NOT here: Pathao's "Keraniganj Model Thana" is
     * one police station inside Keraniganj upazila, not the whole of it, and
     * folding the label away would make it an exact (auto-applied) match.
     */
    private const LABELS = ['upazila', 'upozila', 'upo'];

    /** Words in the normalised form, after variants and without labels. */
    public static function words(?string $name): array
    {
        $text = mb_strtolower(trim((string) $name));
        // An apostrophe joins ("Cox's" is "coxs"), every other mark separates.
        $text = str_replace(["'", "\u{2019}", '`'], '', $text);
        $text = (string) preg_replace('/[^a-z0-9]+/', ' ', $text);
        $text = (string) preg_replace('/\bcox s\b/', 'coxs', $text);

        $words = [];

        foreach (preg_split('/\s+/', trim($text)) ?: [] as $word) {
            if ($word === '' || in_array($word, self::LABELS, true)) {
                continue;
            }

            $words[] = self::VARIANTS[$word] ?? $word;
        }

        // "Cox's Bazar" and "Coxsbazar" are one name written two ways.
        if (($i = array_search('coxsbazar', $words, true)) !== false && ($words[$i + 1] ?? null) === 'bazar') {
            array_splice($words, $i + 1, 1);
        }

        return $words;
    }

    /** Words joined with single spaces: what a contained-word match runs on. */
    public static function phrase(?string $name): string
    {
        return implode(' ', self::words($name));
    }

    /** The exact key. Equal keys are the same place. */
    public static function key(?string $name): string
    {
        return implode('', self::words($name));
    }

    /** The exact key without a trailing "sadar": "Bagerhat Sadar" meets "Bagerhat". */
    public static function keyWithoutSadar(?string $name): string
    {
        $words = self::words($name);

        if (count($words) > 1 && end($words) === 'sadar') {
            array_pop($words);
        }

        return implode('', $words);
    }

    /** Spelling no longer matters. Suggestion strength only. */
    public static function looseKey(?string $name): string
    {
        $key = self::key($name);

        if ($key === '') {
            return '';
        }

        $loose = (string) preg_replace('/(.)\1+/', '$1', (string) preg_replace('/[aeiou]/', '', str_replace('h', '', $key)));

        return $loose === '' ? $key : $loose;
    }

    /**
     * Whether `needle` appears in `haystack` as whole words ("mirpur" is in
     * "mirpur 10", never in "mirpurbazar"; "mirpur 1" is not in "mirpur 10").
     */
    public static function containsWords(string $haystack, string $needle): bool
    {
        if ($needle === '' || strlen(str_replace(' ', '', $needle)) < 3) {
            return false;
        }

        return (bool) preg_match('/(?<![a-z0-9])'.preg_quote($needle, '/').'(?![a-z0-9])/', $haystack);
    }
}
