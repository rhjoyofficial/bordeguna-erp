<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reassigns an employee to a new placement effective from a date, using the
 * effective-dated close-old-open-new pattern (ORG-07): the current assignment
 * is ended and a fresh primary one is opened. History is never overwritten, so
 * "where did this person sit on date X" stays answerable.
 */
class TransferEmployee
{
    /**
     * @param  array<string, int|null>  $placement  office_id/department_id/section_id/designation_id/supervisor_id
     */
    public function handle(Employee $employee, EmployeeAssignment $current, string $effectiveFrom, array $placement): EmployeeAssignment
    {
        return DB::transaction(function () use ($employee, $current, $effectiveFrom, $placement) {
            // ⚠⚠ THE OLD PLACEMENT ENDS THE DAY BEFORE THE NEW ONE STARTS. Both
            // ends of an assignment are inclusive (EffectiveDated::activeOn), so
            // ending it ON the transfer day left two live primary assignments
            // for that one day, and any report as at that day - a salary sheet
            // whose period ends on it - printed and counted the person twice.
            $current->update(['effective_to' => Carbon::parse($effectiveFrom)->subDay()->toDateString()]);

            return $employee->assignments()->create([
                'office_id' => $placement['office_id'] ?? null,
                'department_id' => $placement['department_id'] ?? null,
                'section_id' => $placement['section_id'] ?? null,
                'designation_id' => $placement['designation_id'] ?? null,
                'supervisor_id' => $placement['supervisor_id'] ?? null,
                'is_primary' => true,
                'effective_from' => $effectiveFrom,
                'effective_to' => null,
            ]);
        });
    }
}
