<?php

namespace App\Modules\Crm\Scopes;

use App\Models\User;
use App\Modules\Crm\Support\LeadVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Narrows every Lead query to the leads the signed-in person may see.
 *
 * ⚠⚠ A GLOBAL SCOPE, NOT A `visibleTo()` CALL IN EACH CONTROLLER, and that is
 * the whole decision. At the time of writing a lead can be reached from the lead
 * list, the timeline, activities, tasks, product interests, the duplicate check,
 * global search, the pipeline's lead picker, quotations, the CRM reports, the
 * phone-book pull, the file manager and the attachment route. A rule that each
 * of those must remember is a rule the next screen forgets; a scope on the model
 * is the chokepoint every one of them already passes through.
 *
 * ⚠⚠ NO SIGNED-IN USER MEANS NO NARROWING, deliberately. This scope answers
 * "which PERSON is reading", and a queued job, the scheduler or a seeder has no
 * person: the cadence runner and the follow-up notifier must see every lead in
 * the business. The tenant boundary is NOT relaxed by this - BusinessScope still
 * fails closed underneath. Every web route that can reach a lead sits behind
 * `auth`, so a guest never gets this far.
 *
 * To bypass on purpose (the shop-phone uniqueness check must see a number held
 * by somebody else's lead, or the database would answer with an error instead):
 * Lead::withoutGlobalScope(LeadVisibilityScope::class) - explicit and grep-able,
 * the same convention as BusinessScope.
 */
class LeadVisibilityScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        app(LeadVisibility::class)->restrict($builder, $user, $model->qualifyColumn('assigned_to_user_id'));
    }
}
