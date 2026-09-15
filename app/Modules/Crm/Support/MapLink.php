<?php

namespace App\Modules\Crm\Support;

/**
 * A link that opens a point in Google Maps (plan D14: a link, no embedded map).
 *
 * ⚠ Google's documented Maps URL (`/maps/search/?api=1&query=lat,lng`), which
 * opens the Maps app on a phone and the site on a desktop, needs no API key, and
 * sends nothing but the two numbers.
 */
final class MapLink
{
    public static function to(float|string $latitude, float|string $longitude): string
    {
        $point = self::number($latitude).','.self::number($longitude);

        return 'https://www.google.com/maps/search/?api=1&query='.$point;
    }

    /** "23.8103000" -> "23.8103", never "2.3E+1" and never a locale comma. */
    private static function number(float|string $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 7, '.', ''), '0'), '.');
    }
}
