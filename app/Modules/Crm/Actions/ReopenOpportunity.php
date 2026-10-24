<?php

namespace App\Modules\Crm\Actions;

use App\Models\User;
use App\Modules\Crm\Models\Opportunity;
use App\Modules\Crm\Support\LeadVisibility;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ A won or lost deal goes back on the board (corrections wave 3, 24 Oct
 * 2026; FINAL-PLAN 3.2 "Deal: Reopen with a reason. No messages are
 * re-sent."). It returns to the stage it was in, open, with the won or lost
 * date and the lost reason cleared; the reason for reopening is kept in its
 * History. Nothing else happens: no order, no message, no task.
 *
 * ⚠ The board's own rule first: a deal is reopened only by somebody who may
 * see it (the deal's assignee and their team, or everybody for the Owner),
 * and only while it is closed, so a double click is refused with a sentence.
 */
class ReopenOpportunity
{
    /**
     * @throws ValidationException
     */
    public function handle(int $opportunityId, User $by, ActionReason $reason): Opportunity
    {
        return DB::transaction(function () use ($opportunityId, $by, $reason) {
            $deal = Opportunity::query()
                ->whereKey($opportunityId)
                ->tap(fn ($query) => app(LeadVisibility::class)->restrict($query, $by, 'crm_opportunities.assigned_to_user_id'))
                ->lockForUpdate()
                ->first();

            abort_if($deal === null, 404);

            if (! in_array($deal->status, ['won', 'lost'], true)) {
                throw ValidationException::withMessages(['opportunity' => 'This deal is already open on the board.']);
            }

            $was = $deal->status;

            $deal->forceFill([
                'status' => 'open',
                'won_at' => null,
                'lost_at' => null,
                'lost_reason_id' => null,
            ])->save();

            CorrectionLog::event('reopened', $by, $deal, ['reason' => $reason->display(), 'was' => $was]);

            return $deal;
        });
    }
}
