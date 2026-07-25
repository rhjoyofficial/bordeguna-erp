<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Enums\LeaveLedgerEntryType;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use App\Modules\Hrm\Models\LeaveType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Grants each employee their full annual quota at the start of a calendar year
 * (LEV-02 — a full grant, not monthly accrual and not prorated by joining date).
 *
 * Keyed per employee/type/year, so running it twice grants once. That is what
 * lets it sit safely next to the year-end job on a schedule.
 */
class GrantAnnualLeaveQuota
{
    /**
     * @return array{granted: int, skipped: int}
     */
    public function handle(int $year): array
    {
        $types = LeaveType::active()->where('deducts_balance', true)->where('annual_quota', '>', 0)->get();

        $employees = Employee::whereHas('assignments', fn ($query) => $query
            ->activeOn(Carbon::create($year, 1, 1))
            ->where('is_primary', true))
            ->orderBy('id')->get();

        $granted = 0;
        $skipped = 0;

        foreach ($employees as $employee) {
            foreach ($types as $type) {
                $entry = LeaveLedgerEntry::once(
                    sprintf('grant:%d:%d:%d', $year, $employee->id, $type->id),
                    [
                        'employee_id' => $employee->id,
                        'leave_type_id' => $type->id,
                        'year' => $year,
                        'entry_type' => LeaveLedgerEntryType::Grant,
                        'days' => (float) $type->annual_quota,
                        'occurred_on' => Carbon::create($year, 1, 1)->toDateString(),
                        'note' => sprintf('Annual quota for %d.', $year),
                        'created_by' => Auth::id(),
                    ],
                );

                $entry === null ? $skipped++ : $granted++;
            }
        }

        return ['granted' => $granted, 'skipped' => $skipped];
    }
}
