<?php

namespace App\Modules\Crm\Support;

use App\Models\User;
use App\Modules\Crm\Enums\TaskStatus;
use App\Modules\Crm\Models\CrmTask;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The two counts on the CRM sidebar (round 3, 16 Oct 2026): follow-ups due on
 * Follow-ups, tickets past their time on Tickets.
 *
 * ⚠ The Failed jobs pattern: NULL, not 0, for somebody without
 * `crm.leads.view` (a withheld count is not a count of nothing), and each count
 * at most a minute old, like Needs attention's badge. Staff hold
 * `crm.leads.view`, so these run on almost every page for almost everybody;
 * one cached COUNT each keeps that cheap.
 */
final class CrmBadges
{
    public const SECONDS = 60;

    /**
     * Open follow-ups due by the end of today, overdue ones included, in the
     * reader's own scope: their own and their team's, everybody's with
     * `crm.tasks.view.all` (LeadVisibility::restrictTasks, the queue's rule).
     */
    public static function followUpsDue(?User $user, ?int $businessId): ?int
    {
        if ($user === null || $businessId === null || ! $user->can('crm.leads.view')) {
            return null;
        }

        return self::remember("crm.badge.followups.{$businessId}.{$user->id}", fn () => CrmTask::query()
            ->tap(fn ($query) => app(LeadVisibility::class)->restrictTasks($query, $user))
            ->where('status', TaskStatus::Open)
            ->where('due_at', '<=', now()->endOfDay())
            ->count());
    }

    /**
     * Tickets still open (or in progress) past their resolve-by stamp. The
     * ticket list reads every ticket of the business, so the count does too.
     */
    public static function ticketsOverdue(?User $user, ?int $businessId): ?int
    {
        if ($user === null || $businessId === null || ! $user->can('crm.leads.view')) {
            return null;
        }

        return self::remember("crm.badge.tickets.{$businessId}", fn () => DB::table('crm_tickets')
            ->where('business_id', $businessId)
            ->whereIn('status', ['open', 'in_progress'])
            ->where('resolve_by', '<', now())
            ->count());
    }

    /**
     * @param  callable(): int  $count
     */
    private static function remember(string $key, callable $count): int
    {
        try {
            return (int) Cache::remember($key, self::SECONDS, $count);
        } catch (Throwable) {
            // A badge that cannot be cached is counted again next time.
            return $count();
        }
    }
}
