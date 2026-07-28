<?php

namespace App\Modules\Hrm\Dashboard\Concerns;

use App\Modules\Hrm\Enums\PayrollStatus;
use App\Modules\Hrm\Models\PayrollRun;

/**
 * "The current payroll run for a period" — resolved in one place.
 *
 * Two tiles ask this question (the status tile and the total tile) and a third
 * will, and the answer is not the obvious one: **a run is a version.** WFL-06's
 * reopen path produces v2 and leaves v1 behind as Cancelled, so "the latest run"
 * is the highest **non-cancelled** version, not the newest row.
 *
 * ⚠ **Ordering by `id` is the bug this exists to prevent, and it is invisible.**
 * A cancelled v1 surfaces as the state of the month, or — worse, on the total
 * tile — becomes the basis of a variance against figures nobody was ever paid.
 * Both would render perfectly and foot correctly.
 *
 * Extracted the moment there was a second caller rather than the fifth, on the
 * principle the reporting layer already follows: a repeated correctness rule is
 * a seam, and the next widget should inherit it rather than remember it.
 */
trait ResolvesPayrollRun
{
    protected function runForPeriod(int $year, int $month): ?PayrollRun
    {
        return PayrollRun::query()
            ->with('period')
            ->whereHas('period', fn ($q) => $q->where('year', $year)->where('month', $month))
            ->where('status', '!=', PayrollStatus::Cancelled->value)
            ->orderByDesc('version')
            ->first();
    }
}
