<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Models\Employee;

/**
 * Applies a set of validated master-data field values to an employee, audited
 * (Employee LogsActivity records the before/after). This is the single write
 * path for EMP-04 master data — the HR edit form calls it today, and the future
 * generic Change Request flow calls the very same action when an Employee Update
 * Request is approved, so an approved self-service change and a direct HR edit
 * apply identically and leave the same audit trail. Deliberately narrow: it
 * only touches Employee::EDITABLE_FIELDS, never identity (code/user link),
 * status, separation, or the effective-dated assignment — those have their own
 * flows.
 */
class UpdateEmployee
{
    /**
     * @param  array<string, mixed>  $fields  keys are validated against Employee::EDITABLE_FIELDS
     */
    public function handle(Employee $employee, array $fields): Employee
    {
        $employee->update(collect($fields)
            ->only(Employee::EDITABLE_FIELDS)
            ->all());

        return $employee;
    }
}
