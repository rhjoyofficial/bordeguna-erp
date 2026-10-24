<?php

namespace App\Modules\Crm\Support;

use App\Modules\Crm\Enums\LeadStatus;
use App\Modules\Crm\Enums\LeadType;
use App\Modules\Crm\Enums\TaskStatus;
use App\Modules\Crm\Models\Lead;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * "Going cold": a lead somebody is working that has gone quiet (CRM redesign
 * round 3, 16 Oct 2026).
 *
 * ⭐ The rule, decided 16 Oct (MAPPING.md, "Decisions by Claude" 3, corrected
 * by the round-3 verifier C-E4, there is no "consumer" type):
 *
 *  1. A WORKING lead (not a duplicate, not archived),
 *  2. in New, Contacted, Qualified or Opportunity. Nurture is left out on
 *     purpose (it means "not now"), Converted and Lost are closed,
 *  3. with NO open follow-up (somebody already planned the next step),
 *  4. and nothing recorded for 14 days (customer, retailer) or 30 days
 *     (distributor, supplier). A lead nobody ever contacted counts from the
 *     day it was added: a shop captured three weeks ago and never called is
 *     the coldest of all.
 *
 * ⚠ ONE definition, read by the lead list's "Going cold" view, the Overview
 * tile and the row's own "quiet" mark, so the three can never disagree.
 */
final class GoingCold
{
    /** Days of silence before a lead of each type counts as going cold. */
    public const DAYS = [
        'customer' => 14,
        'retailer' => 14,
        'distributor' => 30,
        'supplier' => 30,
    ];

    /** @return list<string> */
    public static function statuses(): array
    {
        return [LeadStatus::New->value, LeadStatus::Contacted->value, LeadStatus::Qualified->value, LeadStatus::Opportunity->value];
    }

    public static function daysFor(LeadType $type): int
    {
        return self::DAYS[$type->value];
    }

    /**
     * The last time anything was recorded on the lead, or the day it was added.
     * A correlated subquery, so a page of leads stays one query.
     */
    public static function lastTouchSql(): string
    {
        return 'COALESCE((SELECT MAX(crm_activities.occurred_at) FROM crm_activities'
            .' WHERE crm_activities.subject_type = ? AND crm_activities.subject_id = leads.id'
            // ⭐ Wave 3 (24 Oct 2026): live entries only, not binned or corrected.
            .' AND crm_activities.deleted_at IS NULL AND crm_activities.corrected_at IS NULL), leads.created_at)';
    }

    /**
     * Narrows a lead query to the leads going cold.
     *
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    public static function apply(Builder $query, ?CarbonInterface $now = null): Builder
    {
        $now ??= Carbon::now();
        $morph = (new Lead)->getMorphClass();

        return $query
            ->workable()
            ->whereIn('leads.status', self::statuses())
            ->whereNotExists(fn ($tasks) => $tasks->selectRaw('1')->from('crm_tasks')
                ->where('crm_tasks.subject_type', $morph)
                ->whereColumn('crm_tasks.subject_id', 'leads.id')
                ->where('crm_tasks.status', TaskStatus::Open->value))
            ->where(function ($inner) use ($now, $morph) {
                foreach (self::DAYS as $type => $days) {
                    $inner->orWhere(fn ($one) => $one
                        ->where('leads.lead_type', $type)
                        ->whereRaw(self::lastTouchSql().' < ?', [$morph, $now->copy()->subDays($days)->toDateTimeString()]));
                }
            });
    }

    /**
     * The same rule for one lead already in hand, given the facts the list
     * query carries (its last touch and whether a follow-up is open).
     */
    public static function isCold(Lead $lead, int $quietDays, bool $hasOpenFollowUp): bool
    {
        return $lead->duplicate_of_lead_id === null
            && $lead->archived_at === null
            && in_array($lead->status->value, self::statuses(), true)
            && ! $hasOpenFollowUp
            && $quietDays >= self::daysFor($lead->lead_type);
    }
}
