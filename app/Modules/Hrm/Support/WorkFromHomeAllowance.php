<?php

namespace App\Modules\Hrm\Support;

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Enums\MovementType;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\Movement;
use Illuminate\Support\Carbon;

/**
 * How many days of work from home somebody has left this payroll cycle.
 *
 * ⚠⚠ PENDING COUNTS AGAINST THE CAP, not just applied. Counting only approved
 * days would let somebody file eight requests in a morning and have a manager
 * approve them one by one, each looking fine on its own. C-WFH-4 caps the
 * month, so the month is what is counted: everything not rejected or cancelled.
 *
 * ⭐ 27 Oct 2026 (Joy): "for employees everything not 1-31 always 24-23". The
 * cap counts the 24-23 payroll cycle (`PayrollCycle::containing`), so a day on
 * the 24th starts a fresh count. It was the calendar month on purpose until
 * then, read as an arrangement rather than a payroll quantity; the owner's
 * rule settles it the other way. The summary names the cycle with its dates
 * so "October" is never read as 1-31 October.
 */
class WorkFromHomeAllowance
{
    public function __construct(private readonly BusinessContext $context) {}

    public function capFor(?int $businessId = null): int
    {
        $id = $businessId ?? $this->context->id();

        $business = $id === null ? null : Business::withoutGlobalScopes()->find($id);

        return (int) ($business?->wfh_monthly_cap ?? 0);
    }

    /**
     * Requests already standing in the payroll cycle of this date.
     */
    public function usedIn(Employee $employee, Carbon $date): int
    {
        $cycle = PayrollCycle::containing($date);

        return Movement::query()
            ->where('employee_id', $employee->id)
            ->where('type', MovementType::WorkFromHome)
            // ⚠⚠ whereDate on both ends, NOT whereBetween on plain strings. The
            // column is cast to a date and stored with a time on some drivers,
            // and "2026-09-30 00:00:00" sorts AFTER the string "2026-09-30" -
            // so a day worked from home on the last of the month slipped past
            // the cap entirely. Caught by a test that used the 30th on purpose.
            ->whereDate('movement_date', '>=', $cycle['start']->toDateString())
            ->whereDate('movement_date', '<=', $cycle['end']->toDateString())
            ->whereHas('approvalRequest', fn ($query) => $query->whereIn('status', ['pending', 'approved']))
            ->count();
    }

    public function remainingFor(Employee $employee, Carbon $date): int
    {
        return max(0, $this->capFor() - $this->usedIn($employee, $date));
    }

    /**
     * @return array{cap:int, used:int, remaining:int, month:string}
     */
    public function summaryFor(Employee $employee, Carbon $date): array
    {
        $used = $this->usedIn($employee, $date);
        $cap = $this->capFor();
        $cycle = PayrollCycle::containing($date);

        return [
            'cap' => $cap,
            'used' => $used,
            'remaining' => max(0, $cap - $used),
            'month' => PayrollCycle::describe($cycle['year'], $cycle['month']),
        ];
    }
}
