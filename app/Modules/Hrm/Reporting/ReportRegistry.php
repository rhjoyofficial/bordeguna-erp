<?php

namespace App\Modules\Hrm\Reporting;

use App\Models\User;
use App\Modules\Hrm\Reporting\Contracts\ReportDefinition;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Every report the system knows about, and which of them a given user may run.
 *
 * ⚠ **Correction C5 / ACL-02 is enforced here, and this is the load-bearing
 * part of the class.** A Department Manager must not see their team's payslips
 * — asserted in tests since 3a — and a report is the most efficient way there
 * has ever been to see all of them at once. So a payroll or salary report is
 * withheld from a Department Manager **by role, not merely by permission**:
 * permissions can be granted through the Roles UI by someone who has not read
 * ACL-02, and this rule must not be one grant away from being broken.
 *
 * The list is filtered rather than the run being refused, because a report a
 * user may not run should not be visible for them to try.
 */
class ReportRegistry
{
    /** @var array<string, ReportDefinition> */
    private array $definitions = [];

    /**
     * @param  iterable<ReportDefinition>  $definitions
     */
    public function __construct(iterable $definitions = [])
    {
        foreach ($definitions as $definition) {
            $this->register($definition);
        }
    }

    public function register(ReportDefinition $definition): void
    {
        $this->definitions[$definition->key()] = $definition;
    }

    /** @return Collection<string, ReportDefinition> */
    public function all(): Collection
    {
        return collect($this->definitions);
    }

    public function find(string $key): ?ReportDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    /**
     * The definition, or a 404. A report key that does not exist and a report
     * key the user may not run are deliberately the same response — enumerating
     * report keys should not tell anyone what exists.
     */
    public function findOrFail(string $key, ?User $user = null): ReportDefinition
    {
        $definition = $this->find($key);

        if ($definition === null || ($user !== null && ! $this->canRun($user, $definition))) {
            throw new NotFoundHttpException('No such report.');
        }

        return $definition;
    }

    /** @return Collection<string, ReportDefinition> */
    public function availableTo(User $user): Collection
    {
        return $this->all()->filter(fn (ReportDefinition $d) => $this->canRun($user, $d));
    }

    public function canRun(User $user, ReportDefinition $definition): bool
    {
        if (! $user->can($definition->permission())) {
            return false;
        }

        // ACL-02 / C5. A Manager holding a payroll permission through some
        // other grant still does not get a payroll report — the questionnaire's
        // rule is about the *data*, not about the permission that happens to
        // guard it.
        if ($this->isMoneyReport($definition) && $this->isDepartmentManagerOnly($user)) {
            return false;
        }

        return true;
    }

    private function isMoneyReport(ReportDefinition $definition): bool
    {
        return $definition->group() === 'Payroll';
    }

    /**
     * A Manager who is not also Owner, Finance or a Super Admin. Those
     * legitimately see payroll; ACL-02's exclusion is aimed at the line manager
     * who supervises people and has no business seeing what they earn.
     *
     * ⚠ Super Admin is the `is_super_admin` flag on the user, **not a role** —
     * a `Gate::before` bypasses every permission check for them, so testing for
     * a "Super Admin" role would silently never match and would strip the
     * super admin of reports they are explicitly allowed.
     */
    private function isDepartmentManagerOnly(User $user): bool
    {
        if ($user->is_super_admin) {
            return false;
        }

        return $user->hasRole('Manager')
            && ! $user->hasAnyRole(['Owner', 'Finance'])
            && ! $user->can('hrm.salary.view');
    }
}
