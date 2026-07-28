<?php

namespace App\Modules\Hrm\Reporting;

use App\Models\User;
use App\Modules\Hrm\Models\Employee;

/**
 * How much of the business a given user's reports are allowed to cover.
 *
 * **This is Correction C10d's second rule, and W3a shipped without it.** C10d
 * settled two things, not one: a Department Manager gets no payroll or salary
 * report *at all* (which `ReportRegistry` enforced from the start), **and**
 * "attendance reports for a Department Manager are scoped to their own
 * department, via the reporting structure Phase 1 built — not the whole
 * business." Only the first was built. The second is this class, and it lands
 * in the framework rather than in the definitions on purpose: the seven
 * attendance reports must not each remember to apply it, because six of them
 * did not exist when the rule was written and the seventh would have been the
 * only one carrying it.
 *
 * **The scope is applied by taking the choice away, not by adding a filter.**
 * A restricted user's `department_id` parameter is overwritten with their own
 * department before the parameter bag is built, so a definition sees an
 * ordinary department filter and knows nothing about access control — exactly
 * the arrangement that lets a definition stay thin.
 *
 * ⚠ **It fails closed, in both directions where it could leak.** A restricted
 * user whose own department cannot be resolved gets no attendance report at
 * all, and neither does one whose definition declares no department parameter
 * for the scope to bind to. Both are withheld rather than served unscoped:
 * an empty report is a support ticket, but an unscoped one is a disclosure, and
 * a new report added later must not be able to leak by omission.
 */
final class ReportScope
{
    private function __construct(
        public readonly bool $restricted = false,
        public readonly ?int $departmentId = null,
    ) {}

    public static function unrestricted(): self
    {
        return new self;
    }

    public static function toDepartment(?int $departmentId): self
    {
        return new self(true, $departmentId);
    }

    /**
     * The scope a user reports under.
     *
     * ⚠ Super Admin is the `is_super_admin` flag, **not a role** — a
     * `Gate::before` bypasses every permission check for them, so testing for a
     * "Super Admin" role would silently never match and would clamp a super
     * admin to a department they probably do not even have.
     */
    public static function for(?User $user): self
    {
        if ($user === null || ! self::isDepartmentManagerOnly($user)) {
            return self::unrestricted();
        }

        // The reporting structure Phase 1 built: a manager's own active primary
        // assignment says which department is theirs. Resolved live rather than
        // stored, so a transfer moves what they can see with them.
        $department = Employee::forUser($user)?->currentAssignment()?->department_id;

        return self::toDepartment($department === null ? null : (int) $department);
    }

    /**
     * A Manager who is not also Owner, Finance or a Super Admin. Those
     * legitimately see the whole business; ACL-02's exclusion is aimed at the
     * line manager who supervises people and has no business seeing beyond
     * their own team.
     */
    public static function isDepartmentManagerOnly(User $user): bool
    {
        if ($user->is_super_admin) {
            return false;
        }

        return $user->hasRole('Manager')
            && ! $user->hasAnyRole(['Owner', 'Finance'])
            && ! $user->can('hrm.salary.view');
    }

    /**
     * Whether this scope can actually be applied. A restricted user with no
     * department of their own cannot be scoped to one, and the honest answer is
     * to withhold the report rather than to serve it whole.
     */
    public function resolvable(): bool
    {
        return ! $this->restricted || $this->departmentId !== null;
    }

    /**
     * Force the scope onto a set of validated parameters.
     *
     * The user's own choice of department is deliberately overwritten rather
     * than merged: a restricted manager may narrow nothing and widen nothing,
     * and a filter they can still edit is not an access control.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function apply(array $values): array
    {
        if (! $this->restricted) {
            return $values;
        }

        $values['department_id'] = $this->departmentId;

        return $values;
    }
}
