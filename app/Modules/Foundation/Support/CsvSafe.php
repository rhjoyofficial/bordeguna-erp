<?php

namespace App\Modules\Foundation\Support;

/**
 * Spreadsheet formula injection, both ways (OWASP "CSV Injection", applied to
 * the asset register exports 13 Oct 2026).
 *
 * ⚠⚠ Excel and LibreOffice run a cell that starts with `=`, `+`, `-` or `@`
 * as a formula the moment the file opens, and a tab or carriage return in
 * front hides one of those. A thing named `=HYPERLINK(...)` by anyone who may
 * type a name would run on the Owner's machine when the export is opened. The
 * standard defence is a leading single quote: the spreadsheet shows the text
 * and runs nothing. The reader strips that quote again ONLY when the next
 * character is one of the triggers, so a name that genuinely starts with a
 * quote (O'Brien's desk, written 'quoted') survives the round trip.
 *
 * Free-text columns only. A tag, a code, a date or a number never starts with
 * one of these characters, and a negative amount must stay a number.
 */
final class CsvSafe
{
    private const TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    /** The value as it is written to a CSV cell. */
    public static function cell(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return in_array($value[0], self::TRIGGERS, true) ? "'".$value : $value;
    }

    /** The value as it is read back from a CSV cell. */
    public static function read(?string $value): ?string
    {
        if ($value === null || strlen($value) < 2 || $value[0] !== "'") {
            return $value;
        }

        return in_array($value[1], self::TRIGGERS, true) ? substr($value, 1) : $value;
    }
}
