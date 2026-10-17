<?php

namespace App\Modules\Foundation\Corrections;

use App\Models\User;
use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Foundation\Models\ApprovalWorkflow;
use Illuminate\Support\Collection;

/**
 * The Tk 20,000 second-person rule for corrections (PHASE-0-CONTRACT v2, 1.8).
 *
 * ⚠⚠ THE BASIS IS max(original, corrected), not the corrected value. The 12 Oct
 * Pathao case (Tk 102,340.00 corrected to Tk 1,023.40) has a corrected value far
 * below the limit, but the correction moves Tk 102,340. ChatGPT approved this,
 * Owner included (REPLY-6 section 2). The threshold is the Finance policy's
 * payment_second_approval_above_minor; zero switches the rule off.
 *
 * ⚠⚠ THE OWNER IS NEVER EXEMPT. When the Owner asks, a DIFFERENT eligible person
 * approves (Claude's decision on the deadlock, 17 Oct, on Joy's instruction).
 * When nobody else is eligible the action is refused with a plain sentence,
 * never waved through.
 */
class SecondPersonRule
{
    public function limitMinor(int $businessId): int
    {
        return FinancePolicy::for($businessId)->money('payment_second_approval_above_minor')->minor;
    }

    public function basis(int $originalMinor, ?int $correctedMinor): int
    {
        return max(abs($originalMinor), abs($correctedMinor ?? 0));
    }

    public function applies(int $businessId, int $basisMinor): bool
    {
        $limit = $this->limitMinor($businessId);

        return $limit > 0 && $basisMinor > $limit;
    }

    /**
     * @param  bool  $closedMonth  the correction posts into a closed month
     * @param  bool  $askerIsOwner  the person asking may correct a closed month
     */
    public function route(bool $closedMonth, bool $askerIsOwner, bool $aboveLimit): ApprovalRoute
    {
        if ($closedMonth && ! $askerIsOwner) {
            return $aboveLimit ? ApprovalRoute::Both : ApprovalRoute::OwnerClosedPeriod;
        }

        return $aboveLimit ? ApprovalRoute::SecondPerson : ApprovalRoute::None;
    }

    /**
     * The active people of this business who could act on the request's stage
     * and are not refused for it. Empty = nobody can approve = refuse now.
     *
     * @param  list<int>  $excluded  the original's maker and last editor
     * @return Collection<int, User>
     */
    public function eligibleApprovers(int $businessId, ApprovalRoute $route, int $requesterId, array $excluded): Collection
    {
        $workflow = ApprovalWorkflow::query()->withoutGlobalScopes()
            ->where('business_id', $businessId)
            ->where('key', $route->workflowKey())
            ->where('is_active', true)
            ->first();

        $stage = $workflow?->stageAt(1);

        if ($stage === null) {
            return collect();
        }

        return User::query()
            ->where('is_active', true)
            ->where('id', '!=', $requesterId)
            ->whereHas('businesses', fn ($query) => $query->where('businesses.id', $businessId))
            ->get()
            ->filter(fn (User $user) => ! ($route->needsSecondPerson() && in_array($user->id, $excluded, true)))
            ->filter(fn (User $user) => $stage->allows($user))
            ->values();
    }
}
