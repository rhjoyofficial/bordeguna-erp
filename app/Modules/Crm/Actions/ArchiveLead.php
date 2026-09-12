<?php

namespace App\Modules\Crm\Actions;

use App\Models\User;
use App\Modules\Crm\Enums\LeadStatus;
use App\Modules\Crm\Models\Lead;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Validation\ValidationException;

/**
 * Takes a lead out of everybody's working list, without destroying it.
 *
 * ⛔⛔ THERE IS NO HARD DELETE AND THERE MUST NOT BE ONE. Joy's decision, 12 Sep
 * 2026. A deleted lead takes its activities, its attribution and the record of
 * who created it with it, which means the management numbers verification exists
 * to produce would change retrospectively - and the one thing a field officer
 * inventing retailers would most want is the ability to make them disappear.
 *
 * ⚠ Archiving frees the shop's phone number, which is the part worth
 * understanding. `shop_phone_key` stays set on the archived row, so the number
 * remains taken. That is deliberate: if archiving released it, the same officer
 * could archive and re-create the same shop endlessly and no duplicate would
 * ever be detected. Restoring is the way back, and it is one press.
 *
 * ⚠⚠ THE PERMISSION IS CHECKED BY THE CALLER, as everywhere else in this module.
 */
class ArchiveLead
{
    /**
     * @throws ValidationException
     */
    public function handle(Lead $lead, User $archivedBy): Lead
    {
        $this->guardTenancy($lead);

        if ($lead->isArchived()) {
            throw ValidationException::withMessages([
                'lead' => 'That lead is already archived.',
            ]);
        }

        // ⚠⚠ A converted lead has a real customer behind it and every conversion
        // figure the business reads derives from it. Hiding it from the working
        // list is fine; this action refuses because the honest question there is
        // what to do with the CUSTOMER, and that is not a question this action
        // may answer on somebody's behalf. Same reasoning as LinkDuplicateLead.
        if ($lead->status === LeadStatus::Converted) {
            throw ValidationException::withMessages([
                'lead' => 'That lead has been converted to a customer and cannot be archived.',
            ]);
        }

        $lead->forceFill([
            'archived_at' => now(),
            'archived_by_user_id' => $archivedBy->id,
        ])->save();

        return $lead;
    }

    /**
     * @throws ValidationException
     */
    public function restore(Lead $lead): Lead
    {
        $this->guardTenancy($lead);

        if (! $lead->isArchived()) {
            throw ValidationException::withMessages([
                'lead' => 'That lead is not archived.',
            ]);
        }

        $lead->forceFill([
            'archived_at' => null,
            'archived_by_user_id' => null,
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
