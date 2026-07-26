<?php

namespace App\Modules\Hrm\Support;

use App\Modules\Hrm\Enums\PayrollBalanceEntryType;
use App\Modules\Hrm\Models\PayrollBalanceEntry;
use App\Modules\Hrm\Models\PayrollPeriod;

/**
 * Reads of the PAY-11 carry-forward balance (Correction C8c).
 *
 * Two questions, and keeping them apart is what makes the whole mechanism
 * arithmetically safe:
 *
 *  - **What was carried forward *into* this very period?** That amount becomes a
 *    credit on this month's payslip, bringing a net that could not be paid up to
 *    exactly zero.
 *  - **What was still owed *before* this period began?** That becomes this
 *    month's recovery deduction.
 *
 * Confusing the two would have a month recover the shortfall it created, which
 * nets to nothing and hides the debt.
 */
class PayrollBalanceLedger
{
    /**
     * Everything still owed as at the start of a period — the recovery C8c takes
     * in full from this payroll. Entries with no period attached are treated as
     * pre-existing, since there is no month to place them after.
     */
    public function outstandingBefore(int $employeeId, PayrollPeriod $period): float
    {
        $total = PayrollBalanceEntry::withoutGlobalScopes()
            ->where('employee_id', $employeeId)
            ->where(fn ($query) => $query
                ->whereNull('payroll_period_id')
                ->orWhereHas('period', fn ($p) => $p->whereDate('start_date', '<', $period->start_date->toDateString())))
            ->sum('amount');

        return max(0.0, round((float) $total, 2));
    }

    /**
     * The shortfall HR chose to carry forward out of this period — the credit
     * that squares this month's payslip.
     */
    public function carriedForwardIn(int $employeeId, PayrollPeriod $period): float
    {
        $total = PayrollBalanceEntry::withoutGlobalScopes()
            ->where('employee_id', $employeeId)
            ->where('payroll_period_id', $period->id)
            ->where('entry_type', PayrollBalanceEntryType::CarriedForward)
            ->sum('amount');

        return max(0.0, round((float) $total, 2));
    }

    public function outstandingFor(int $employeeId): float
    {
        return PayrollBalanceEntry::outstandingFor($employeeId);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, PayrollBalanceEntry>
     */
    public function entriesFor(int $employeeId)
    {
        return PayrollBalanceEntry::with('period')
            ->where('employee_id', $employeeId)
            ->orderByDesc('occurred_on')->orderByDesc('id')
            ->get();
    }

    public static function carryForwardKey(int $employeeId, int $periodId): string
    {
        return "negative_net:{$employeeId}:{$periodId}";
    }

    public static function recoveryKey(int $employeeId, int $periodId): string
    {
        return "recovery:{$employeeId}:{$periodId}";
    }
}
