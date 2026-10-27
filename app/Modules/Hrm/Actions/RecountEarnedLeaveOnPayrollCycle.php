<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\EarnedLeaveAccrual;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ The one-time earned-leave recount (Joy, 27 Oct 2026): "reset full recount
 * from aug 24 to sep 23, sep 24 to oct 23 based on that add the amount, from
 * the 24 oct to nov 23 will calculate".
 *
 * Live decided 2026 months 8 and 9 on the CALENDAR month (1-31 Aug, 1-30 Sep).
 * This run:
 *
 *  1. supersedes every calendar decision for those two months and reverses
 *     each grant that still stands with an offsetting ledger entry;
 *  2. decides month 9 again on the payroll CYCLE, 24 Aug - 23 Sep, with the
 *     normal rules (that payroll is locked, its attendance complete).
 *
 * ⚠ Month 8 is NOT decided again: the owner's recount starts on 24 Aug, the
 * first day of cycle 9. Month 10 (24 Sep - 23 Oct) is decided when October
 * payroll is calculated, not here, because its last days are imported at the
 * end of the month.
 *
 * ⚠ Only businesses that HAD calendar decisions for those months are touched.
 * It runs from a migration, where a business that never accrued on the
 * calendar has nothing to recount.
 *
 * Idempotent: a superseded row is left alone, every ledger entry is once-keyed,
 * and month 9 is decided once on the cycle basis.
 */
class RecountEarnedLeaveOnPayrollCycle
{
    public const YEAR = 2026;

    public const REASON = 'Superseded by the owner\'s decision of 27 Oct 2026: earned leave is recounted on the 24-23 payroll cycle from 24 Aug 2026, so the calendar month decision no longer stands.';

    public function __construct(
        private readonly AccrueMonthlyEarnedLeave $accrue,
        private readonly BusinessContext $context,
    ) {}

    /**
     * @return array<int, array{superseded: int, month9: array<string, mixed>}> keyed by business id
     */
    public function handle(): array
    {
        $businessIds = DB::table('earned_leave_accruals')
            ->where('basis', EarnedLeaveAccrual::BASIS_CALENDAR)
            ->where('year', self::YEAR)
            ->whereIn('month', [8, 9])
            ->distinct()
            ->orderBy('business_id')
            ->pluck('business_id');

        $previous = $this->context->id();
        $results = [];

        try {
            foreach ($businessIds as $businessId) {
                $this->context->set((int) $businessId);

                $calendar = EarnedLeaveAccrual::query()
                    ->where('basis', EarnedLeaveAccrual::BASIS_CALENDAR)
                    ->where('year', self::YEAR)
                    ->whereIn('month', [8, 9])
                    ->current()
                    ->orderBy('month')->orderBy('employee_id')
                    ->get();

                foreach ($calendar as $decision) {
                    $this->accrue->supersede($decision, self::REASON);
                }

                $results[(int) $businessId] = [
                    'superseded' => $calendar->count(),
                    'month9' => $this->accrue->handle(self::YEAR, 9),
                ];
            }
        } finally {
            $previous === null ? $this->context->forget() : $this->context->set($previous);
        }

        return $results;
    }
}
