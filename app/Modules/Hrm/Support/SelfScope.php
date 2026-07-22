<?php

namespace App\Modules\Hrm\Support;

use App\Models\User;
use App\Modules\Hrm\Models\Employee;

/**
 * P3 — Self-scope. The single "owner-or-permitted" rule behind Employee
 * Self-Service. A user may reach an employee-owned record — the employee record
 * itself today, and that employee's attendance / leave / payslip in later
 * phases — when they either hold the relevant management permission (HR sees
 * everyone) or the record is their own (the owning employee is linked to this
 * user).
 *
 * This is orthogonal to business scope: the business scope answers "is this
 * record in my tenant?", this answers "is it mine, or am I allowed to see
 * everyone's?". Every ESS feature routes through here so the rule stays
 * identical across attendance, leave, and payroll instead of being re-derived
 * (slightly differently) per feature — the whole point of the primitive.
 */
final class SelfScope
{
    /**
     * @param  Employee|null  $owner  The employee that owns the record (for the
     *                                 Employee record itself, that's the record).
     */
    public static function allows(?User $user, ?Employee $owner, string $managePermission): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->can($managePermission)) {
            return true;
        }

        return $owner !== null
            && $owner->user_id !== null
            && $owner->user_id === $user->id;
    }
}
