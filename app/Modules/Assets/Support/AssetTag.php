<?php

namespace App\Modules\Assets\Support;

use App\Modules\Foundation\Models\Business;
use Illuminate\Support\Str;

/**
 * The tag printed on an item: `{BUSINESS}-{CATEGORY}-{0001}` (plan §2.8), for
 * example `BDG-IT-0007`.
 *
 * ⚠ The business part comes from the business's slug, because a business has no
 * short code of its own: the initials of a slug in several words
 * (`bor-de-guna` -> BDG), otherwise its first three letters (`laladia` -> LAL,
 * `bionic` -> BIO, `azmion` -> AZM). Tags are unique across the whole group,
 * and the register refuses a tag another business already holds rather than
 * inventing a different one.
 */
final class AssetTag
{
    public static function prefixFor(Business $business): string
    {
        $parts = array_values(array_filter(preg_split('/[^a-z0-9]+/i', (string) $business->slug) ?: []));

        $prefix = count($parts) > 1
            ? implode('', array_map(fn (string $part) => mb_substr($part, 0, 1), array_slice($parts, 0, 4)))
            : mb_substr($parts[0] ?? 'ASSET', 0, 3);

        return Str::upper($prefix);
    }

    public static function make(Business $business, string $categoryCode, int $sequence): string
    {
        return sprintf('%s-%s-%04d', self::prefixFor($business), Str::upper($categoryCode), $sequence);
    }
}
