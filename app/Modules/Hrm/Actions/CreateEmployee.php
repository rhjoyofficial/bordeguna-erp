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
     *
     * ⚠⚠ **Derived from the highest existing code, NEVER from `count()`.** The
     * original implementation was `Employee::count() + 1`, and real data proved
     * it broken before a single production row existed: Bor de Guna's nine
     * employees are numbered 1, 2, 10, 12, 16, 19, 20, 21, 22 — **twelve gaps**,
     * left by people who have since left. After migrating those nine, a count of
     * 9 would have issued **BDG-000010** to the next hire, which already belongs
     * to somebody. Best case a unique-constraint failure that blocks hiring;
     * worst case two people sharing the code that payroll, payslips and every
     * report key on.
     *
     * **The gaps are meaningful and must never be refilled.** EMP-02 makes a
     * code an immutable identity, and Joy confirmed codes are permanent — a
     * departed employee's number stays theirs, because it is stamped on
     * historical payslips and reports that must remain readable.
     *
     * ⚠ Deliberately reads **without global scopes**: the sequence is global
     * across all businesses (`Employee` is central and has no `business_id`), so
     * scoping the lookup would restart numbering inside each business and
     * collide immediately.
     *
     * ⚠ Ordered lexically and parsed in PHP, matching `Loan::nextReference()`.
     * The codes are zero-padded to six digits, so lexical order *is* numeric
     * order — and a SQL `CAST(... AS INTEGER)` would not survive the move from
     * SQLite (tests) to MySQL (production), where the cast type is spelled
     * differently. The unique index on `employee_code` is the real guard.
     */
    private function nextCode(): string
    {
        $highest = Employee::withoutGlobalScopes()
            ->orderByDesc('employee_code')
            ->value('employee_code');

        $next = $highest === null ? 1 : ((int) preg_replace('/\D/', '', $highest)) + 1;

        return sprintf('BDG-%06d', $next);
    }
}
