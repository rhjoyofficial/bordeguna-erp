<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Enums\EmploymentType;
use App\Modules\Hrm\Enums\LeaveLedgerEntryType;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Support\LeaveEntitlement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Ends an employee's probation (Joy's Q2/Q3 decisions, 24 Aug 2026).
 *
 * One action does all three things because they are one event: the employment
 * type flips to permanent, the confirmation date is recorded, and the
 * permanent leave entitlements for the rest of THIS year are granted,
 * pro-rated from the confirmation date with the same LeaveEntitlement math
 * (and the same round-DOWN) every other grant uses.
 *
 * ⚠ Sick is a TOP-UP, not a fresh grant. The probationer already holds the
 * flat 3-day sick grant, and the year has ONE sick balance: the top-up lifts
 * what was granted this year to the pro-rated permanent level, never below
 * zero, and days already taken stay deducted. Without this rule someone
 * confirmed in June would hold 3 + 6 = 9 sick days - more than the permanent
 * policy itself.
 *
 * Grants are keyed 'confirm:{year}:{employee}:{type}', and
 * GrantAnnualLeaveQuota skips an employee whose confirm entries exist, so
 * neither a rerun of this action nor a rerun of the annual grant can
 * double-pay the year.
 */
class ConfirmEmployee
{
    public function handle(Employee $employee, string $confirmationDate): Employee
    {
        $date = Carbon::parse($confirmationDate)->startOfDay();
        $year = (int) $date->year;

        return DB::transaction(function () use ($employee, $date, $year) {
            // Audited by the Employee model's LogsActivity: both fields are in
            // EDITABLE_FIELDS, so the flip carries who/when in activity_log.
            $employee->update([
                'employment_type' => EmploymentType::Permanent,
                'confirmation_date' => $date->toDateString(),
            ]);

            $types = LeaveType::active()->where('deducts_balance', true)->where('annual_quota', '>', 0)->get();

            foreach ($types as $type) {
                $prorated = LeaveEntitlement::daysFor((float) $type->annual_quota, $date->toDateString(), $year);

                if ($type->code === 'sick') {
                    // Lift this year's granted sick to the pro-rated permanent
                    // level. Read what was ACTUALLY granted rather than
                    // assuming the flat 3 - a probationer confirmed before the
                    // annual grant ever ran has 0 granted, and the top-up is
                    // then the whole pro-rated amount.
                    $alreadyGranted = (float) LeaveLedgerEntry::query()
                        ->where('employee_id', $employee->id)
                        ->where('leave_type_id', $type->id)
                        ->forYear($year)
                        ->where('entry_type', LeaveLedgerEntryType::Grant)
                        ->sum('days');

                    $days = max(0.0, $prorated - $alreadyGranted);
                    $note = sprintf(
                        'Confirmation top-up for %d: pro-rated %s of %s from %s, minus %s already granted.',
                        $year, number_format($prorated, 1), number_format((float) $type->annual_quota, 1),
                        $date->toDateString(), number_format($alreadyGranted, 1),
                    );
                } else {
                    $days = $prorated;
                    $note = sprintf(
                        'Confirmation grant for %d: %s pro-rated from %s (%s of %s days).',
                        $year, number_format($days, 1), $date->toDateString(),
                        number_format($days, 1), number_format((float) $type->annual_quota, 1),
                    );
                }

                if ($days <= 0) {
                    continue;
                }

                LeaveLedgerEntry::once(
                    sprintf('confirm:%d:%d:%d', $year, $employee->id, $type->id),
                    [
                        'employee_id' => $employee->id,
                        'leave_type_id' => $type->id,
                        'year' => $year,
                        'entry_type' => LeaveLedgerEntryType::Grant,
                        'days' => $days,
                        'occurred_on' => $date->toDateString(),
                        'note' => $note,
                        'created_by' => Auth::id(),
                    ],
                );
            }

            return $employee;
        });
    }
}
