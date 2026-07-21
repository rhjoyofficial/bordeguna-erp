<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * Creates a central employee and opens their first (primary) effective-dated
 * assignment to the current business in one transaction. The assignment's
 * business is set from the request context by BelongsToBusiness.
 */
class CreateEmployee
{
    /**
     * @param  array<string, mixed>  $data  employee attributes
     * @param  array<string, mixed>  $assignment  office_id/department_id/section_id/designation_id/supervisor_id
     */
    public function handle(array $data, array $assignment = []): Employee
    {
        return DB::transaction(function () use ($data, $assignment) {
            $employee = Employee::create([
                'employee_code' => $this->nextCode(),
                'user_id' => $data['user_id'] ?? null,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'employment_type' => $data['employment_type'],
                'status' => $data['status'] ?? EmployeeStatus::Draft,
                'personal_email' => $data['personal_email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'national_id' => $data['national_id'] ?? null,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'joining_date' => $data['joining_date'] ?? null,
            ]);

            $employee->assignments()->create([
                'office_id' => $assignment['office_id'] ?? null,
                'department_id' => $assignment['department_id'] ?? null,
                'section_id' => $assignment['section_id'] ?? null,
                'designation_id' => $assignment['designation_id'] ?? null,
                'supervisor_id' => $assignment['supervisor_id'] ?? null,
                'is_primary' => true,
                'effective_from' => $data['joining_date'] ?? now()->toDateString(),
                'effective_to' => null,
            ]);

            return $employee;
        });
    }

    /**
     * Immutable global sequence: BDG-000001, BDG-000002, … (EMP-02).
     */
    private function nextCode(): string
    {
        return sprintf('BDG-%06d', Employee::count() + 1);
    }
}
