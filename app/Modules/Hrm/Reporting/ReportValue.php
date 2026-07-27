<?php

namespace App\Modules\Hrm\Reporting;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * The single place a report value becomes text.
 *
 * Three renderings of the same value, one per medium, chosen by the column's
 * declared type:
 *
 *  - **`forCsv`** — bare. No thousands separators, no currency symbol, ISO
 *    dates. This is the only rendering every spreadsheet and every parser
 *    reads back as the value it started as; a prettier CSV is a less useful
 *    one. (`GenericCsvFormat` already states this rule for its own amounts.)
 *  - **`forSpreadsheet`** — the native PHP type where the column is numeric, so
 *    Excel can sort and SUM it, and a string otherwise.
 *  - **`forDisplay`** — grouped and formatted for a human, used by the PDF and
 *    the print view.
 *
 * Every writer goes through here, which is what makes "the same value rendered
 * three ways is the same value" an assertion a test can make.
 *
 * ⚠ **W5 (localisation) is not done here yet.** Bangladeshi lakh/crore
 * grouping — 12,34,567 rather than 1,234,567 — belongs in `forDisplay` and
 * nowhere else. It is deliberately left out until the W5 language decision is
 * answered, and this is the one function that will need to change when it is.
 */
final class ReportValue
{
    public static function forCsv(mixed $value, ReportColumnType $type): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($type) {
            ReportColumnType::Money, ReportColumnType::Decimal => number_format((float) $value, 2, '.', ''),
            ReportColumnType::Integer => (string) (int) $value,
            ReportColumnType::Date => self::carbon($value)?->toDateString() ?? '',
            ReportColumnType::DateTime => self::carbon($value)?->toDateTimeString() ?? '',
            ReportColumnType::Time => self::carbon($value)?->format('H:i') ?? '',
            ReportColumnType::Duration => self::duration($value),
            ReportColumnType::Boolean => $value ? 'Yes' : 'No',
            ReportColumnType::Text => (string) $value,
        };
    }

    public static function forSpreadsheet(mixed $value, ReportColumnType $type): mixed
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($type) {
            // Real numbers, so the column sorts and sums in Excel. A money
            // column written as text is the single most common complaint about
            // generated spreadsheets.
            ReportColumnType::Money, ReportColumnType::Decimal => round((float) $value, 2),
            ReportColumnType::Integer => (int) $value,
            default => self::forCsv($value, $type),
        };
    }

    public static function forDisplay(mixed $value, ReportColumnType $type): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($type) {
            ReportColumnType::Money => number_format((float) $value, 2),
            ReportColumnType::Decimal => rtrim(rtrim(number_format((float) $value, 2), '0'), '.'),
            ReportColumnType::Integer => number_format((int) $value),
            ReportColumnType::Date => self::carbon($value)?->format('d M Y') ?? '',
            ReportColumnType::DateTime => self::carbon($value)?->format('d M Y, h:i A') ?? '',
            ReportColumnType::Time => self::carbon($value)?->format('h:i A') ?? '',
            ReportColumnType::Duration => self::duration($value),
            ReportColumnType::Boolean => $value ? 'Yes' : 'No',
            ReportColumnType::Text => (string) $value,
        };
    }

    /**
     * Minutes as hours and minutes. Attendance stores worked time in minutes,
     * and "512" is not an answer to "how long did they work".
     */
    private static function duration(mixed $value): string
    {
        $minutes = (int) round((float) $value);

        if ($minutes === 0) {
            return '0m';
        }

        $sign = $minutes < 0 ? '-' : '';
        $minutes = abs($minutes);

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        if ($hours === 0) {
            return $sign.$rest.'m';
        }

        return $rest === 0 ? "{$sign}{$hours}h" : "{$sign}{$hours}h {$rest}m";
    }

    private static function carbon(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value);
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            // A value that will not parse is shown as nothing rather than as an
            // exception — one malformed cell must not take a whole report down.
            return null;
        }
    }
}
