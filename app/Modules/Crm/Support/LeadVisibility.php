<?php

namespace App\Modules\Crm\Support;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Whose leads a person may see.
 *
 * ⭐⭐ JOY'S ANSWER, 14 SEP 2026, and the reason this class exists: ONLY THE
 * OWNER SEES EVERY LEAD. An officer sees the leads assigned to him; the ASM sees
 * his team's. Before this, anybody who could open Leads read every lead in the
 * business, which for a field team means every officer reading every other
 * officer's shops.
 *
 * The rule, in full (build plan D1-D3, approved 15 Sep 2026):
 *
 *  1. `crm.leads.view.all` (the Owner) sees everything.
 *  2. Otherwise a lead is visible when it is assigned to ME or to anybody BELOW
 *     me in the reporting tree - not only my direct reports, so a sales manager
 *     placed above an ASM later sees the whole branch without a code change.
 *  3. An UNASSIGNED lead is visible to whoever may assign (`crm.leads.assign`),
 *     because somebody has to be able to hand it out. An officer's own new lead
 *     is never unassigned: LeadController assigns it to its creator.
 *
 * ⚠⚠ THE TEAM IS THE ORG CHART, NOT A SECOND STRUCTURE INVENTED IN CRM. It is
 * read from `employee_assignments.supervisor_id` in the CURRENT business, current
 * assignments only, so a transfer or a new supervisor in HRM moves lead access
 * the same day with nothing to keep in sync.
 *
 * ⚠ Memoised per user and business for the life of the request. The scope runs
 * on every Lead query, and a report or a timeline issues several.
 */
class LeadVisibility
{
    /** @var array<string, list<int>> */
    private array $userIds = [];

    public function seesEverything(User $user): bool
    {
        return $user->can('crm.leads.view.all');
    }

    public function seesUnassigned(User $user): bool
    {
        return $user->can('crm.leads.assign');
    }

    /**
     * Me, and every user below me in the reporting tree of this business.
     *
     * @return list<int>
     */
    public function userIdsFor(User $user): array
    {
        $businessId = app(BusinessContext::class)->id();
        $key = $user->id.'@'.($businessId ?? 'none');

        return $this->userIds[$key] ??= $this->resolveUserIds($user, $businessId);
    }

    /**
     * The same rule for a single lead already in hand - the attachment route
     * holds a model, not a query.
     */
    public function canSee(User $user, Lead $lead): bool
    {
        if ($this->seesEverything($user)) {
            return true;
        }

        if ($lead->assigned_to_user_id === null) {
            return $this->seesUnassigned($user);
        }

        return in_array((int) $lead->assigned_to_user_id, $this->userIdsFor($user), true);
    }

    /**
     * Narrows any query over rows that carry an assignee to the ones this person
     * may see. Used by the lead scope and by the deals on the pipeline board,
     * which are plain query-builder rows with the same meaning of "assigned".
     *
     * @param  EloquentBuilder<\Illuminate\Database\Eloquent\Model>|QueryBuilder  $query
     */
    public function restrict(EloquentBuilder|QueryBuilder $query, User $user, string $assigneeColumn): void
    {
        if ($this->seesEverything($user)) {
            return;
        }

        $ids = $this->userIdsFor($user);
        $unassigned = $this->seesUnassigned($user);

        $query->where(function ($inner) use ($assigneeColumn, $ids, $unassigned) {
            $inner->whereIn($assigneeColumn, $ids);

            if ($unassigned) {
                $inner->orWhereNull($assigneeColumn);
            }
        });
    }

    /**
     * Whose follow-ups a person may see: everybody's with `crm.tasks.view.all`,
     * otherwise their own and their team's.
     *
     * ⚠⚠ ONE rule for the three places that list tasks - the queue, the report's
     * workload table and the calendar. The calendar had no rule at all and showed
     * every open follow-up in the business to anybody who could open Leads, which
     * is exactly the disclosure the queue's own comment (C-CRMB-10) says it
     * prevents.
     *
     * ⚠ The team half is new on 15 Sep 2026: before it, a person without
     * view-all saw only their own, which would have left the ASM unable to see
     * that an officer's follow-ups are rotting.
     *
     * @param  EloquentBuilder<\Illuminate\Database\Eloquent\Model>  $query
     */
    public function restrictTasks(EloquentBuilder $query, User $user): void
    {
        if ($user->can('crm.tasks.view.all')) {
            return;
        }

        $query->whereIn($query->getModel()->qualifyColumn('assigned_to_user_id'), $this->userIdsFor($user));
    }

    /**
     * @return list<int>
     */
    private function resolveUserIds(User $user, ?int $businessId): array
    {
        $ids = [$user->id];

        $me = Employee::forUser($user);

        if ($me === null || $businessId === null) {
            return $ids;
        }

        // ⚠⚠ Breadth-first with a SEEN set. HRM refuses a reporting cycle when
        // one is saved (Employee::wouldCreateReportingCycle), but a guard that
        // trusts another module's validation forever is one bad data fix away
        // from an infinite loop on every lead query.
        $seen = [$me->id => true];
        $frontier = [$me->id];

        while ($frontier !== []) {
            $below = EmployeeAssignment::query()
                ->withoutGlobalScopes()
                ->where('business_id', $businessId)
                ->whereIn('supervisor_id', $frontier)
                ->active()
                ->pluck('employee_id')
                ->map(fn ($id) => (int) $id)
                ->reject(fn (int $id) => isset($seen[$id]))
                ->unique()
                ->values()
                ->all();

            foreach ($below as $id) {
                $seen[$id] = true;
            }

            $frontier = $below;
        }

        unset($seen[$me->id]);

        if ($seen !== []) {
            $teamUserIds = Employee::query()
                ->whereIn('id', array_keys($seen))
                ->whereNotNull('user_id')
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $ids = array_values(array_unique([...$ids, ...$teamUserIds]));
        }

        return $ids;
    }
}
