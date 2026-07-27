<?php

namespace App\Modules\Hrm\Reporting;

/**
 * What kind of value a report column holds.
 *
 * This is the whole of W3's formatting story. A column declares its type once,
 * in its definition, and each writer decides what that type means in its own
 * medium: a money column is a bare two-decimal string in CSV (the only
 * rendering every spreadsheet and parser reads back as a number), a genuine
 * numeric cell with a `#,##0.00` format in xlsx, and a grouped, right-aligned
 * string in PDF and print.
 *
 * The alternative — letting each report format its own strings — is how fifteen
 * reports end up disagreeing about what a taka looks like.
 */
enum ReportColumnType: string
{
    case Text = 'text';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Money = 'money';
    case Date = 'date';
    case DateTime = 'datetime';
    case Time = 'time';
    case Duration = 'duration';
    case Boolean = 'boolean';

    /**
     * Numbers and dates read right, words read left. Nothing here is a
     * preference — a column of amounts that is not right-aligned cannot be
     * scanned for magnitude, which is the only reason to put amounts in a
     * column in the first place.
     */
    public function align(): string
    {
        return match ($this) {
            self::Integer, self::Decimal, self::Money, self::Duration => 'right',
            self::Date, self::DateTime, self::Time, self::Boolean => 'center',
            self::Text => 'left',
        };
    }

    /**
     * Whether a writer that distinguishes numbers from text (xlsx) should emit
     * this as a real number. Excel sorting and SUM() only work if it does.
     */
    public function isNumeric(): bool
    {
        return in_array($this, [self::Integer, self::Decimal, self::Money], true);
    }
}
