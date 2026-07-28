<?php

namespace App\Modules\Hrm\Reporting\Concerns;

use App\Modules\Hrm\Reporting\ReportParameters;

/**
 * The default exact row count: the same figure the estimate returns.
 *
 * Correction C11a added `countRows()` to `ReportDefinition` because a dashboard
 * tile displays its number and `estimatedRows()` is contractually allowed to be
 * approximate. For most definitions the two are the same query and the same
 * answer, so this trait says so once instead of thirteen times.
 *
 * ⚠ **A definition whose estimate is deliberately cheap must NOT use this.** The
 * two grouped reports return 0 from `estimatedRows()` on purpose — counting a
 * `GROUP BY` costs a subquery over the whole period to learn that the answer is
 * "a few dozen", and the estimate only picks between two correct paths. Those
 * implement `countRows()` properly, and using this trait there would report a
 * KPI of zero with complete confidence.
 */
trait CountsRows
{
    public function countRows(ReportParameters $parameters): int
    {
        return $this->estimatedRows($parameters);
    }
}
