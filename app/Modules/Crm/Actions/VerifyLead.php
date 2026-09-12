<?php

namespace App\Modules\Crm\Actions;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Validation\ValidationException;

/**
 * Says that somebody senior checked this lead is a real place.
 *
 * ⭐⭐ WHY THIS EXISTS INSTEAD OF APPROVAL ON EVERY EDIT. Joy's first instruction
 * was that all lead CRUD should wait on the ASM, and the fear behind it is real:
 * two field officers could invent retailers and visits that never happened. But
 * approval on every edit answers that fear badly - a phone correction waiting on
 * a manager, an address fix waiting, a note waiting, and the officers go back to
 * WhatsApp. He chose verification instead on 12 Sep 2026.
 *
 * So a lead EXISTS IMMEDIATELY and is usable for work, and reads as Unverified
 * until somebody with the permission confirms it. That also produces management
 * numbers approval could never give: 42 field-created retailers, 31 verified, 11
 * unverified, 7 by one officer and 4 by another.
 *
 * ⚠⚠ THE PERMISSION IS CHECKED BY THE CALLER, NOT HERE. Every other action in
 * this module works that way and the controller is where the acting user is
 * known. What this action guarantees is the RULES - tenancy, and that a decision
 * already made is not quietly made again by somebody else.
 *
 * ⚠ Verification is reversible, because a mistake is possible and an
 * unreversible one would simply be lied about instead. Unverifying is the same
 * permission and leaves the activity log entry behind.
 */
class VerifyLead
{
    /**
     * @throws ValidationException
     */
    public function handle(Lead $lead, User $verifiedBy): Lead
    {
        $this->guardTenancy($lead);

        if ($lead->isArchived()) {
            throw ValidationException::withMessages([
                'lead' => 'That lead has been archived. Restore it before verifying it.',
            ]);
        }

        // ⚠ Refused rather than silently re-stamped. Overwriting would replace
        // the name of the person who actually checked it, and the date they did,
        // with somebody who only pressed a button again.
        if ($lead->isVerified()) {
            throw ValidationException::withMessages([
                'lead' => 'That lead is already verified.',
            ]);
        }

        $lead->forceFill([
            'verified_at' => now(),
            'verified_by_user_id' => $verifiedBy->id,
        ])->save();

        return $lead;
    }

    /**
     * ⚠ Both columns are cleared together. A `verified_by` left behind with no
     * `verified_at` is a row that says somebody verified this at no particular
     * time, which no screen could render honestly.
     *
     * @throws ValidationException
     */
    public function undo(Lead $lead): Lead
    {
        $this->guardTenancy($lead);

        if (! $lead->isVerified()) {
            throw ValidationException::withMessages([
                'lead' => 'That lead is not verified.',
            ]);
        }

        $lead->forceFill([
            'verified_at' => null,
            'verified_by_user_id' => null,
        ])->save();

        return $lead;
    }

    /**
     * @throws ValidationException
     */
    private function guardTenancy(Lead $lead): void
    {
        if ($lead->business_id !== app(BusinessContext::class)->id()) {
            throw ValidationException::withMessages([
                'lead' => 'That lead belongs to a different business.',
            ]);
        }
    }
}
