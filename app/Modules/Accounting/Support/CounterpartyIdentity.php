<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\Counterparty;
use App\Modules\Crm\Support\PhoneIdentity;
use App\Modules\Foundation\Models\Business;
use Normalizer;

/**
 * Is this the same party twice, or one of our own businesses?
 *
 * Two questions a counterparty is asked before it is saved and, for the second,
 * again before any loan head names it (loans plan v2; ChatGPT confirmation of
 * 14 Oct 2026, point 5):
 *
 *  1. DUPLICATES. The same TIN, or the same name with the same phone, is the
 *     same party. Two rows for one lender are two half-ledgers that each look
 *     right and together are wrong, so the second is refused with the name of
 *     the first.
 *  2. OUR OWN BUSINESSES. Bor de Guna, LaLaDia, Bionic Garden and Azmion are one
 *     legal company. Money between them is an internal balance moved by
 *     Inter-business transfers (1195/2190) and cancels across the group; a
 *     "loan" to LaLaDia would be an asset the company owes itself.
 *
 * ⚠ Matching forms only. What somebody typed is never rewritten; the keys live
 * in their own columns (the PhoneIdentity rule from CRM).
 */
final class CounterpartyIdentity
{
    /**
     * Words that say what kind of company a name is, not which one. "Tycoon
     * Hi-Tech Park Ltd." and "Tycoon Hi Tech Park Limited" are one firm.
     * Only TRAILING words are dropped: "Company Bazar" is a name.
     */
    private const LEGAL_SUFFIXES = ['ltd', 'limited', 'pvt', 'private', 'plc', 'inc', 'llc', 'co', 'company', 'corp', 'corporation'];

    /**
     * The name as a matching key: case, spacing, punctuation and a trailing
     * legal suffix do not make a different party.
     *
     * ⚠ `\p{M}` is kept with the letters: every Bangla vowel sign is a mark,
     * and splitting on it would cut "রহিম" into pieces.
     */
    public static function nameKey(?string $name): string
    {
        $text = (string) $name;
        $text = Normalizer::normalize($text, Normalizer::FORM_KC) ?: $text;
        $text = str_replace('&', ' and ', mb_strtolower($text));

        $words = preg_split('/[^\p{L}\p{M}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        while (count($words) > 1 && in_array(end($words), self::LEGAL_SUFFIXES, true)) {
            array_pop($words);
        }

        return implode('', $words);
    }

    /** 01711000000, whichever of the usual spellings was typed (CRM's rule). */
    public static function phoneKey(?string $phone): ?string
    {
        return PhoneIdentity::normalize($phone);
    }

    /** A TIN with its dashes and spaces gone. Null when nothing is left. */
    public static function tinKey(?string $tin): ?string
    {
        $key = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', (string) $tin) ?? '');

        return $key === '' ? null : $key;
    }

    /**
     * The business of the group this name belongs to, or null. Compared with
     * the business's name, its legal name and its slug.
     */
    public static function ownBusinessNamed(?string $name): ?Business
    {
        $key = self::nameKey($name);

        if ($key === '') {
            return null;
        }

        foreach (Business::withoutGlobalScopes()->get(['id', 'name', 'legal_name', 'slug']) as $business) {
            foreach ([$business->name, $business->legal_name, str_replace('-', ' ', (string) $business->slug)] as $candidate) {
                if ($candidate !== null && $candidate !== '' && self::nameKey($candidate) === $key) {
                    return $business;
                }
            }
        }

        return null;
    }

    /** The sentence that refuses one of our own businesses as a counterparty. */
    public static function ownBusinessRefusal(string $name, Business $business): string
    {
        return "\"{$name}\" is {$business->name}, one of the group's own businesses, so it cannot be a counterparty. Money between our own businesses is not a loan: use Finance & Accounts > Inter-business transfers, which books 1195 Due from / 2190 Due to Other Businesses.";
    }

    /**
     * The existing counterparty these details belong to, and which detail
     * matched: ['tin' | 'name_phone', Counterparty], or null.
     *
     * @return array{0: string, 1: Counterparty}|null
     */
    public static function duplicateOf(?string $legalName, ?string $phone, ?string $tin, ?int $ignoreId = null): ?array
    {
        $others = fn () => Counterparty::query()->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId));

        if (($tinKey = self::tinKey($tin)) !== null && ($same = $others()->where('tin_key', $tinKey)->orderBy('id')->first()) !== null) {
            return ['tin', $same];
        }

        $nameKey = self::nameKey($legalName);
        $phoneKey = self::phoneKey($phone);

        if ($nameKey !== '' && $phoneKey !== null
            && ($same = $others()->where('name_key', $nameKey)->where('phone_key', $phoneKey)->orderBy('id')->first()) !== null) {
            return ['name_phone', $same];
        }

        return null;
    }
}
