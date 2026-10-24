<?php

namespace App\Modules\Crm\Actions;

use App\Models\User;
use App\Modules\Crm\Corrections\LeadActionPolicy;
use App\Modules\Crm\Enums\LeadStatus;
use App\Modules\Crm\Enums\TaskStatus;
use App\Modules\Crm\Models\CrmTask;
use App\Modules\Crm\Models\Lead;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * ⭐ The three corrections of a lead (corrections wave 3, 24 Oct 2026;
 * FINAL-PLAN 3.2 "Lead: Reverse conversion if the new customer is unused.
 * Reopen clears the lost date." and 6.3, the Recycle bin):
 *
 *   reverseConversion()  the lead stops being converted and goes back to the
 *                        status it had before. A customer (or supplier) the
 *                        conversion CREATED is switched off, never deleted;
 *                        one that existed before is only unlinked. Refused
 *                        while anything was recorded against it (the policy).
 *   reopen()             a lost lead is worked again: the status it had
 *                        before it was lost, the lost date and reason cleared.
 *   delete()             a lead nobody worked goes to the Recycle bin; its
 *                        open follow-ups are cancelled with it (else they
 *                        would remind an officer about a lead he cannot open).
 *
 * Every one takes a reason (>= 5 characters, checked by the controller and
 * kept in History) and asks LeadActionPolicy again under the row lock, so a
 * double click finds the work already done and is refused with a sentence.
 *
 * ⚠⚠ Conversion stays the ONE writer of the conversion columns going
 * forward; this is the one writer going back. Same forceFill, same reason:
 * the columns are deliberately unfillable (Lead::$fillable).
 */
class CorrectLead
{
    public function __construct(private readonly LeadActionPolicy $policy) {}

    /**
     * @throws ValidationException
     */
    public function reverseConversion(Lead $lead, User $by, ActionReason $reason): Lead
    {
        return DB::transaction(function () use ($lead, $by, $reason) {
            $current = $this->lock($lead);
            $this->ask($current, $by, DocumentAction::Reverse);

            $party = $current->converted_customer_id !== null
                ? Customer::query()->find($current->converted_customer_id)
                : Supplier::query()->find($current->converted_supplier_id);

            $created = $party !== null && $this->conversionCreated($current, $party);

            // ⚠ Switched off, never deleted: a customer row may already carry
            // papers and history of its own, and its code stays reserved.
            if ($created && $party->is_active) {
                $party->forceFill(['is_active' => false])->save();
                CorrectionLog::event('switched_off', $by, $party, ['reason' => "Conversion of {$this->policy->numberOf($current)} reversed: {$reason->display()}"]);
            }

            $current->forceFill([
                'converted_customer_id' => null,
                'converted_supplier_id' => null,
                'converted_at' => null,
                'converted_party_created' => null,
                'status' => $this->statusBefore($current, LeadStatus::Converted, LeadStatus::Qualified),
            ])->save();

            CorrectionLog::event('conversion_reversed', $by, $current, array_filter([
                'reason' => $reason->display(),
                'party' => $party?->getAttribute('code'),
                'switched_off' => $created ?: null,
            ]));

            return $current;
        });
    }

    /**
     * @throws ValidationException
     */
    public function reopen(Lead $lead, User $by, ActionReason $reason): Lead
    {
        return DB::transaction(function () use ($lead, $by, $reason) {
            $current = $this->lock($lead);

            if (($why = $this->policy->reopenBlocker($current, $by)) !== null) {
                throw ValidationException::withMessages(['lead' => $why]);
            }

            $current->forceFill([
                'status' => $this->statusBefore($current, LeadStatus::Lost, LeadStatus::Contacted),
                'lost_at' => null,
                'lost_reason_id' => null,
            ])->save();

            CorrectionLog::event('reopened', $by, $current, ['reason' => $reason->display()]);

            return $current;
        });
    }

    /**
     * @return int how many open follow-ups were cancelled with it
     *
     * @throws ValidationException
     */
    public function delete(Lead $lead, User $by, ActionReason $reason): int
    {
        return DB::transaction(function () use ($lead, $by, $reason) {
            $current = $this->lock($lead);
            $this->ask($current, $by, DocumentAction::Delete);

            // ⚠ The bin first: it asks "never worked?" again, and a follow-up
            // cancelled a line earlier would read as worked.
            $current->recycle($by, $reason);

            return CrmTask::query()
                ->whereIn('subject_type', array_unique([$current->getMorphClass(), Lead::class]))
                ->where('subject_id', $current->id)
                ->where('status', TaskStatus::Open->value)
                ->update(['status' => TaskStatus::Cancelled->value, 'updated_at' => now()]);
        });
    }

    /**
     * ⚠ Read through the SCOPED model, so a lead outside this person's team is
     * "not there" here exactly as on its page.
     *
     * @throws ValidationException
     */
    private function lock(Lead $lead): Lead
    {
        return Lead::query()->whereKey($lead->id)->lockForUpdate()->first()
            ?? throw ValidationException::withMessages(['lead' => 'This lead is no longer there. It may have been deleted a moment ago.']);
    }

    /**
     * @throws ValidationException
     */
    private function ask(Lead $lead, User $by, DocumentAction $action): void
    {
        $availability = $this->policy->availability($lead, $by, $action);

        if (! $availability->allowed) {
            throw ValidationException::withMessages(['lead' => $availability->blockers[0]->message]);
        }
    }

    /**
     * Whether the conversion made the party, or linked one that existed.
     * Recorded since 24 Oct 2026; for an older conversion the party counts as
     * made by it when it was created within two minutes of the conversion
     * (the same transaction wrote both).
     */
    private function conversionCreated(Lead $lead, Model $party): bool
    {
        if ($lead->converted_party_created !== null) {
            return $lead->converted_party_created;
        }

        $made = $party->getAttribute('created_at');

        return $made !== null && $lead->converted_at !== null && abs($lead->converted_at->diffInSeconds($made)) <= 120;
    }

    /**
     * The status the lead had before it became $now, read from its own change
     * log; $fallback when the log does not say (a lead older than the log, or
     * one created straight into that status).
     */
    private function statusBefore(Lead $lead, LeadStatus $now, LeadStatus $fallback): LeadStatus
    {
        $changes = Activity::query()
            ->where('subject_type', $lead->getMorphClass())
            ->where('subject_id', $lead->id)
            ->where('event', 'updated')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        foreach ($changes as $change) {
            $became = $change->properties['attributes']['status'] ?? null;
            $was = LeadStatus::tryFrom((string) ($change->properties['old']['status'] ?? ''));

            if ($became === $now->value && $was !== null && ! in_array($was, [LeadStatus::Lost, LeadStatus::Converted], true)) {
                return $was;
            }
        }

        return $fallback;
    }
}
