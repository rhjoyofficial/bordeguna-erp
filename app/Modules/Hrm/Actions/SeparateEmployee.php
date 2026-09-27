<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Foundation\Scopes\BusinessScope;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Enums\SeparationType;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Support\RevokeErpAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Separates an employee: records how and when they left, closes every open
 * assignment as of the separation date (so they drop out of "who works here"),
 * and disables their ERP login if one is linked.
 *
 * Separation is a central event (the person leaves Bor de Guna), so assignments
 * are closed across all businesses, not just the current one.
 *
 * Removing access = RevokeErpAccess: deactivate the account, drop its live
 * sessions, revoke its API tokens (27 Sep 2026). Final salary, leave
 * settlement and asset clearance are the remaining EMP-08 automations, still
 * to land as their own pieces.
 */
class SeparateEmployee
{
    public function handle(Employee $employee, SeparationType $type, string $date, ?string $reason = null): Employee
    {
        return DB::transaction(function () use ($employee, $type, $date, $reason) {
            $employee->update([
                'status' => EmployeeStatus::Separated,
                'separation_type' => $type,
                'separation_date' => $date,
                'separation_reason' => $reason,
            ]);

            EmployeeAssignment::withoutGlobalScope(BusinessScope::class)
                ->where('employee_id', $employee->id)
                ->whereNull('effective_to')
                ->update(['effective_to' => $date]);

            // ⭐ 27 Sep 2026 (Joy): access ends on the LAST WORKING DAY, not the
            // day the resignation is recorded. A future-dated separation keeps the
            // login live until that day has passed; `hrm:disable-due-separations`
            // switches it off the next morning. A past or same-day date has already
            // passed only once it is BEFORE today, so a resignation dated today
            // leaves them the rest of their last day.
            if (Carbon::parse($date)->startOfDay()->lt(Carbon::today())) {
                RevokeErpAccess::for($employee->user);
            }

            return $employee;
        });
    }
}
