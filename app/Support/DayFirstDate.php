<?php

namespace App\Support;

/**
 * ⚠⚠ A DATE A PERSON TYPED IS DAY FIRST (Joy, 18 Oct 2026: "in our country we
 * always write Day Month Year").
 *
 * What a sheet or a command line says, as Y-m-d, or null when it is not a real
 * date. Never Carbon::parse(): that reads 05/09/2026 as 9 May (American order)
 * and a whole file would land a month out without one error.
 *
 *  - 2026-09-17, 2026/09/17      ISO, unchanged meaning
 *  - 17/09/2026, 17-09-2026, 17.09.2026, 5/9/2026   day, month, year
 *
 * Bangla digits are read. Anything else (a two-digit year, 31/02, words) is null.
 */
final class DayFirstDate
{
    public static function toIso(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtr(trim($value), ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);

        if (preg_match('#^(\d{4})[-/](\d{1,2})[-/](\d{1,2})$#', $value, $m) === 1) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})$#', $value, $m) === 1) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }

        if ($y < 1900 || $y > 2200 || ! checkdate($mo, $d, $y)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }

    /**
     * A stored date for a printed page or an email: "2026-09-17" (or a longer
     * value starting with one) as "17/09/2026". Anything else comes back as it
     * was; empty is "-".
     */
    public static function show(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('d/m/Y');
        }

        $value = trim((string) $value);

        if ($value === '') {
            return '-';
        }

        return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) === 1 ? "{$m[3]}/{$m[2]}/{$m[1]}" : $value;
    }
}
