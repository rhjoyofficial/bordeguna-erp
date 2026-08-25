<?php

namespace App\Modules\Hrm\Support;

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Enums\MovementType;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\Movement;
use Illuminate\Support\Carbon;

/**
 * How many days of work from home somebody has left this month.
 *
 * ⚠⚠ PENDING COUNTS AGAINST THE CAP, not just applied. Counting only approved
 * days would let somebody file eight requests in a morning and have a manager
 * approve them one by one, each looking fine on its own. C-WFH-4 caps the
 * month, so the month is what is counted: everything not rejected or cancelled.
 *
 * ⚠ The CALENDAR month, deliberately, not the 24th-to-23rd payroll cycle. A cap
 * on working from home is an arrangement between a person and their manager,
 * not a payroll quantity, and "how many days in August" must mean August to
 * both of them. ⚠⚠ The two disagree by design; on this project a rule keyed to
 * the wrong month boundary has bitten before.
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
     * Requests already standing in the calendar month of this date.
     */
    public function usedIn(Employee $employee, Carbon $date): int
    {
        return Movement::query()
            ->where('employee_id', $employee->id)
            ->where('type', MovementType::WorkFromHome)
            // ⚠⚠ whereDate on both ends, NOT whereBetween on plain strings. The
            // column is cast to a date and stored with a time on some drivers,
            // and "2026-09-30 00:00:00" sorts AFTER the string "2026-09-30" -
            // so a day worked from home on the last of the month slipped past
            // the cap entirely. Caught by a test that used the 30th on purpose.
            ->whereDate('movement_date', '>=', $date->copy()->startOfMonth()->toDateString())
            ->whereDate('movement_date', '<=', $date->copy()->endOfMonth()->toDateString())
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

        return [
            'cap' => $cap,
            'used' => $used,
            'remaining' => max(0, $cap - $used),
            'month' => $date->format('F Y'),
        ];
    }
}
