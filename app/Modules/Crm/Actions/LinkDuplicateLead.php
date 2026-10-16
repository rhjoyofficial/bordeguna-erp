<?php

namespace App\Modules\Crm\Actions;

use App\Models\User;
use App\Modules\Crm\Enums\LeadStatus;
use App\Modules\Crm\Models\Lead;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Says that two leads are one person.
 *
 * ⚠⚠ NOTHING IS MOVED AND NOTHING IS DELETED. The duplicate keeps its own
 * activities, tasks, interests and attributions; it simply points at the lead
 * that should be worked instead. Re-parenting historical rows would be a
 * retrospective edit of history, and "which campaign produced this" would start
 * answering differently than it did yesterday.
 *
 * ⚠ The ONLY writer of `duplicate_of_lead_id`, `merged_at` and `merged_by`, all
 * of which are unfillable - same reasoning as the conversion columns.
 */
class LinkDuplicateLead
{
    /**
     * @throws ValidationException
     */
    public function handle(Lead $duplicate, Lead $survivor, User $mergedBy): Lead
    {
        $businessId = app(BusinessContext::class)->id();

        foreach ([$duplicate, $survivor] as $lead) {
            if ($lead->business_id !== $businessId) {
                throw ValidationException::withMessages([
                    'survivor_id' => 'That lead belongs to a different business.',
                ]);
            }
        }

        if ($duplicate->is($survivor)) {
            throw ValidationException::withMessages([
                'survivor_id' => 'A lead cannot be a duplicate of itself.',
            ]);
        }

        if ($duplicate->duplicate_of_lead_id !== null) {
            throw ValidationException::withMessages([
                'survivor_id' => 'That lead is already marked as a duplicate.',
            ]);
        }

        // ⚠⚠ C-CRMB-34. A converted lead has a customer behind it and every
        // conversion figure derives from it. If two leads both converted, that
        // is a CUSTOMER-level question and not one this action may answer.
        if ($duplicate->status === LeadStatus::Converted) {
            throw ValidationException::withMessages([
                'survivor_id' => 'That lead has been converted to a customer and cannot be marked as a duplicate.',
            ]);
        }

        // ⚠⚠ C-CRMB-33. NO CHAINS. Otherwise "whose history is this" needs a
        // recursive walk that can cycle, and every report has to know how to
        // follow it.
        if ($survivor->duplicate_of_lead_id !== null) {
            throw ValidationException::withMessages([
                'survivor_id' => 'That lead is itself a duplicate. Link to the one it points at instead.',
            ]);
        }

        return DB::transaction(function () use ($duplicate, $survivor, $mergedBy) {
            $duplicate->forceFill([
                'duplicate_of_lead_id' => $survivor->id,
                'merged_at' => now(),
                'merged_by' => $mergedBy->id,
            ])->save();

            // ⚠ Bug hunt 16 Oct 2026: the check above looked at the survivor
            // only. Leads already pointing at the duplicate would now point at
            // a duplicate, the chain C-CRMB-33 forbids. They follow it to the
            // lead to work instead; only the pointer moves, never their history.
            Lead::query()->where('duplicate_of_lead_id', $duplicate->id)
                ->update(['duplicate_of_lead_id' => $survivor->id, 'updated_at' => now()]);

            return $duplicate;
        });
    }
}
