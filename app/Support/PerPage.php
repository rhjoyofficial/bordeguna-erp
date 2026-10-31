<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * ⭐ 31 Oct 2026 (Joy): every list has the same footer. On the left, how many
 * rows a page shows (20, 50, 200, 500 or All), on the right, the pages. The
 * choice rides in the address as `per_page`, so a filter, a page link or a
 * reload keeps it.
 *
 * "All" is capped, not unlimited: one page of ten thousand rows is already
 * more than a browser lays out comfortably, and the cap keeps a careless
 * click from loading a whole ledger into memory.
 */
final class PerPage
{
    /** What the footer's select offers, besides All. */
    public const OPTIONS = [20, 50, 200, 500];

    public const DEFAULT = 20;

    /** What "All" means in rows. */
    public const ALL = 10000;

    public static function from(?Request $request = null, string $key = 'per_page'): int
    {
        $value = ($request ?? request())->query($key);

        if ($value === 'all') {
            return self::ALL;
        }

        $number = is_numeric($value) ? (int) $value : 0;

        return in_array($number, self::OPTIONS, true) ? $number : self::DEFAULT;
    }
}
