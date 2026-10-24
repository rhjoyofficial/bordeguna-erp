<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Hrm\Corrections\EmployeeActionPolicy;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * ⭐ Delete an unused draft employee to the Recycle bin (corrections wave 3,
 * 24 Oct 2026; FINAL-PLAN 3.4 and 6.3). Only a draft nobody used (no punches,
 * payslips, leave, loans, claims, documents or login: Employee::whyNotRemovable).
 * The row stays, hidden, with who and why; the Owner restores it from
 * Settings > Recycle bin, and the employee code is never handed out again.
 *
 * ⚠ The policy is asked again under the lock, so a draft that gained a punch
 * or a document since the page was drawn is refused, and a double click finds
 * it already in the bin.
 */
class DeleteDraftEmployee
{
    public function __construct(private readonly EmployeeActionPolicy $policy) {}

    public function handle(Employee $employee, User $by, ActionReason $reason): void
    {
        DB::transaction(function () use ($employee, $by, $reason) {
            $current = Employee::query()->whereKey($employee->id)->lockForUpdate()->first();

            if ($current === null) {
                throw new InvalidArgumentException('This employee is already in the Recycle bin.');
            }

            $availability = $this->policy->availability($current, $by, DocumentAction::Delete);

            if (! $availability->allowed) {
                throw new InvalidArgumentException($availability->blockers[0]->message);
            }

            $current->recycle($by, $reason);
        });
    }
}
