<?php

namespace App\Modules\Hrm\Reporting;

/**
 * The kinds of input a report can ask for.
 *
 * A closed set, on purpose. Every report in RPT-01/02 is answered by some
 * combination of "when", "who" and "where" — so those are the types, and the
 * parameter screen can render all of them generically without knowing which
 * report it is drawing. A report that needs something outside this list is a
 * signal to think again, not to add a free-text box.
 */
enum ReportParameterType: string
{
    /** A single calendar date. */
    case Date = 'date';

    /** A from/to pair. The screen renders two inputs bound to one parameter. */
    case DateRange = 'date_range';

    /**
     * A calendar month, as `YYYY-MM`.
     *
     * Distinct from a date and from a payroll period, and both distinctions are
     * real. A register asked for with a *date* invites "which day of the month
     * did you mean"; a payroll period is C7b's 24th-to-23rd cycle, which is not
     * a month at all. The attendance reports in RPT-01 are monthly in the
     * calendar sense, so they say so.
     */
    case Month = 'month';

    /** A payroll period (C7b: 24th → 23rd), chosen from the ones that exist. */
    case Period = 'period';

    /** A specific payroll run, including its version (WFL-06). */
    case PayrollRun = 'payroll_run';

    case Office = 'office';
    case Department = 'department';
    case Employee = 'employee';

    /** A fixed list supplied by the definition. */
    case Select = 'select';

    case Boolean = 'boolean';

    /**
     * Whether the value is a pair rather than a scalar. Only the range type is,
     * and the screen and the validator both need to know without special-casing
     * a string comparison.
     */
    public function isRange(): bool
    {
        return $this === self::DateRange;
    }
}
