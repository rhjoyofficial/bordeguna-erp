<?php

namespace App\Modules\Hrm\Reporting;

use App\Models\User;
use App\Modules\Hrm\Reporting\Contracts\ReportDefinition;
use App\Support\Features;
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

    /**
     * The report keys a module's tabs link to (Sales › Overview). Shared as
     * `runnableReports`, filtered by canRun() for the signed-in user, so a tab
     * is shown exactly when its report would open.
     *
     * @return Collection<int, string>
     */
    public static function linkedFromTheSidebar(): Collection
    {
        return collect(['sales_by_channel', 'sales_margin', 'delivery_economics', 'courier_settlement_ageing']);
    }

    /**
     * Which of these report keys the user may run (an unknown key, never).
     *
     * @param  iterable<string>  $keys
     * @return list<string>
     */
    public function runnableKeys(User $user, iterable $keys): array
    {
        return collect($keys)
            ->filter(fn (string $key) => ($definition = $this->find($key)) !== null && $this->canRun($user, $definition))
            ->values()
            ->all();
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
        // ⚠ A report that belongs to a hidden feature (Joy, 24 Oct 2026;
        // Features::REPORTS) is unknown to everybody: not listed, and its
        // address is the same 404 as a key that never existed.
        if (! Features::reportOn($definition->key())) {
            return false;
        }

        if (! $user->can($definition->permission())) {
            return false;
        }

        $scope = ReportScope::for($user);

        if (! $scope->restricted) {
            return true;
        }

        // ACL-02 / C5. A Manager holding a payroll permission through some
        // other grant still does not get a payroll report — the questionnaire's
        // rule is about the *data*, not about the permission that happens to
        // guard it.
        if ($this->isMoneyReport($definition)) {
            return false;
        }

        // C10d's other half, and both of these are fail-closed cases rather
        // than conveniences. A manager with no department of their own cannot
        // be scoped to one; a report that declares no department parameter has
        // nothing for the scope to bind to. Serving either unscoped would hand
        // a line manager the whole business, which is the exact outcome C10d
        // exists to prevent — so the report is withheld instead.
        return $scope->resolvable() && $this->acceptsDepartmentScope($definition);
    }

    /**
     * The scope binds to the `department_id` parameter, so a report that does
     * not declare one cannot be scoped. This is checked rather than assumed
     * because the failure is silent: a new attendance report that simply
     * forgets the parameter would otherwise be served whole to a manager, and
     * every row on it would look perfectly plausible.
     */
    private function acceptsDepartmentScope(ReportDefinition $definition): bool
    {
        foreach ($definition->parameters() as $parameter) {
            if ($parameter->key === 'department_id') {
                return true;
            }
        }

        return false;
    }

    private function isMoneyReport(ReportDefinition $definition): bool
    {
        return $definition->group() === 'Payroll';
    }
}
