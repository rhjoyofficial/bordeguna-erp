<?php

namespace App\Modules\Crm\Actions;

use App\Models\User;
use App\Modules\Crm\Enums\ActivityDirection;
use App\Modules\Crm\Enums\ActivityType;
use App\Modules\Crm\Models\CrmActivity;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Records that something happened.
 *
 * ⚠ Every rule about WHAT may be recorded lives here rather than in the
 * controller, because there is already a second caller in prospect (task
 * completion, and later a Meta ingestion) and a rule that lives in one screen is
 * a rule the next caller does not have.
 */
class LogCrmActivity
{
    /**
     * @throws ValidationException
     */
    public function handle(
        Model $subject,
        ActivityType $type,
        string $summary,
        User $recordedBy,
        mixed $occurredAt = null,
        ?ActivityDirection $direction = null,
        ?string $body = null,
        ?string $outcome = null,
        ?int $durationSeconds = null,
    ): CrmActivity {
        $businessId = app(BusinessContext::class)->id();

        // ⚠⚠ Whitelist, not `morphTo` trusting whatever arrives. Without this a
        // request could attach an "activity" to a Journal or a Payslip and the
        // polymorphic column would happily store it.
        if (! in_array($subject::class, CrmActivity::SUBJECTS, true)) {
            throw ValidationException::withMessages([
                'subject' => 'An activity can only be recorded against a lead or a customer.',
            ]);
        }

        // ⚠ The subject must belong to the business doing the recording. The
        // global scope already hides other tenants' rows, so a mismatch here
        // means something bypassed it - fail loudly rather than write a row
        // pointing across the boundary.
        if ($subject->business_id !== $businessId) {
            throw ValidationException::withMessages([
                'subject' => 'That record belongs to a different business.',
            ]);
        }

        $occurred = $occurredAt === null ? now() : Carbon::parse($occurredAt);

        // ⚠⚠ C-CRMB-3. A thing that has not happened yet is a TASK, not an
        // activity. Without this, "call them next Tuesday" gets logged as a call
        // that already took place, and every contact report is wrong.
        if ($occurred->isFuture()) {
            throw ValidationException::withMessages([
                'occurred_at' => 'That is in the future. Something that has not happened yet is a follow-up, not an activity.',
            ]);
        }

        if (trim($summary) === '') {
            throw ValidationException::withMessages([
                'summary' => 'Say in one line what happened.',
            ]);
        }

        // ⚠ A note has no direction and a call is the only thing measured in
        // seconds. Silently storing either would produce reports that count
        // "inbound notes".
        if (! $type->hasDirection()) {
            $direction = null;
        }

        if (! $type->hasDuration()) {
            $durationSeconds = null;
        }

        if ($durationSeconds !== null && $durationSeconds < 0) {
            throw ValidationException::withMessages([
                'duration_seconds' => 'A call cannot last less than no time.',
            ]);
        }

        return CrmActivity::create([
            'business_id' => $businessId,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'type' => $type,
            'direction' => $direction,
            'occurred_at' => $occurred,
            'summary' => trim($summary),
            'body' => $body,
            'outcome' => $outcome,
            'duration_seconds' => $durationSeconds,
            'recorded_by' => $recordedBy->id,
        ]);
    }
}
