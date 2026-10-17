<?php

namespace App\Modules\Foundation\Corrections;

use App\Models\User;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\Exceptions\ClosedPeriodPassException;
use App\Modules\Foundation\Models\ClosedPeriodException;
use App\Modules\Foundation\Models\CorrectionLink;
use App\Modules\Foundation\Models\CorrectionRequest;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The Owner's one-document exception for a closed month (PHASE-0-CONTRACT v2,
 * 1.10): grant, consume, close. Section 9 answer 4: "posted on the original
 * date as a logged one-document exception, no general unlock".
 *
 * ⚠⚠ THE BOUNDARY IS NEVER TOUCHED. `accounting_periods.is_unlocked` stays
 * false throughout; nothing here calls unlock(). The month stays closed for
 * every other screen, job and listener.
 *
 * ⚠⚠ BOUND TO ONE CORRECTION (v2, C1). The row names the business, the original,
 * the action, the dates, the maximum number of journals, and ONE successor of a
 * named type (bound by the first successor journal). consume() refuses anything
 * else, re-checks the granting Owner (C2) and counts the journals already posted
 * under the row (C4). The (period_exception_id, period_exception_slot) unique
 * index on journals is the database's last word.
 *
 * ⚠ TRANSACTION-BOUND (C3). grant() refuses to run outside a transaction, so a
 * rollback takes the row with it: an unused exception cannot outlive the attempt.
 */
class ClosedPeriodExceptions
{
    /**
     * @param  list<string>  $dates  Y-m-d, exactly the closed dates this correction posts on
     *
     * @throws ClosedPeriodPassException
     */
    public function grant(
        Model $original,
        string $originalNumber,
        string $action,
        ?string $successorType,
        array $dates,
        int $maxJournals,
        User $owner,
        User $executedBy,
        ActionReason $reason,
        ?CorrectionRequest $request = null,
    ): ClosedPeriodPass {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('A closed-month exception is granted only inside the correction transaction.');
        }

        // ⚠⚠ Checked HERE, not only at the route (C2): the Owner role (or the
        // super admin) AND the permission. A tick in Settings > Roles cannot
        // hand Finance a closed month.
        if (! Owners::mayCorrectClosedMonth($owner)) {
            throw new ClosedPeriodPassException('Only the Owner can correct a closed month.');
        }

        if ($dates === [] || $maxJournals < 1) {
            throw new LogicException('A closed-month exception must name its dates and its journals.');
        }

        $businessId = (int) app(BusinessContext::class)->id();
        $lock = AccountingPeriod::current();

        if ($lock === null || $lock->is_unlocked) {
            throw new LogicException('There is no closed month to make an exception in.');
        }

        $secret = bin2hex(random_bytes(32));

        $row = new ClosedPeriodException;
        $row->forceFill([
            'business_id' => $businessId,
            'closed_through_snapshot' => $lock->closed_through->toDateString(),
            'original_type' => $original->getMorphClass(),
            'original_id' => $original->getKey(),
            'original_number' => $originalNumber,
            'action' => $action,
            'successor_type' => $action === DocumentAction::Correct->value ? $successorType : null,
            'dates' => array_values(array_unique($dates)),
            'max_journals' => $maxJournals,
            'journals_posted' => 0,
            'secret_hash' => hash('sha256', $secret),
            'granted_by' => $owner->id,
            'executed_by' => $executedBy->id,
            'reason_id' => $reason->reasonId,
            'reason_text' => $reason->text,
            'correction_request_id' => $request?->id,
            'status' => ClosedPeriodException::GRANTED,
        ])->save();

        return new ClosedPeriodPass(
            exceptionId: (int) $row->id,
            businessId: $businessId,
            originalType: $original->getMorphClass(),
            originalId: (int) $original->getKey(),
            action: $action,
            successorType: $row->successor_type,
            dates: $row->dates,
            maxJournals: $maxJournals,
            secret: $secret,
        );
    }

    /**
     * Called by PostJournal for each journal it posts on a closed date with the
     * pass, inside PostJournal's transaction. Returns the slot the journal takes.
     *
     * @throws ClosedPeriodPassException
     */
    public function consume(ClosedPeriodPass $pass, ?Model $source, Carbon $date): int
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('A closed-month exception is consumed only inside a transaction.');
        }

        $row = ClosedPeriodException::query()->withoutGlobalScopes()->whereKey($pass->exceptionId)->lockForUpdate()->first();

        $refuse = fn (string $why) => throw new ClosedPeriodPassException("This closed-month exception cannot be used: {$why}");

        if ($row === null) {
            $refuse('it does not exist.');
        }

        if ($row->status !== ClosedPeriodException::GRANTED) {
            $refuse('it was already used.');
        }

        if (! hash_equals((string) $row->secret_hash, hash('sha256', $pass->secret))) {
            $refuse('it does not match its record.');
        }

        $businessId = app(BusinessContext::class)->id();

        if ((int) $row->business_id !== $pass->businessId || (int) $row->business_id !== (int) $businessId) {
            $refuse('it belongs to another business.');
        }

        if (! in_array($date->toDateString(), $row->dates ?? [], true)) {
            $refuse("it does not cover {$date->toDateString()}.");
        }

        if ($source === null) {
            $refuse('the journal has no document behind it.');
        }

        $isOriginal = $source->getMorphClass() === $row->original_type && (int) $source->getKey() === (int) $row->original_id;

        if (! $isOriginal) {
            $isSuccessor = $row->action === DocumentAction::Correct->value
                && $row->successor_type !== null
                && $source->getMorphClass() === $row->successor_type
                && ($row->successor_id === null || (int) $row->successor_id === (int) $source->getKey());

            if (! $isSuccessor) {
                $refuse('it was granted for a different document.');
            }

            $row->successor_id = $source->getKey();
        }

        // ⚠⚠ Counted from the journals themselves, not trusted from the counter.
        $posted = Journal::query()->withoutGlobalScopes()->where('period_exception_id', $row->id)->count();

        if ($posted >= (int) $row->max_journals) {
            $refuse('it has already posted every journal it allows.');
        }

        // ⚠ C2: the Owner gate again, at execution, against the granting person.
        $granter = User::query()->find($row->granted_by);

        if (! Owners::mayCorrectClosedMonth($granter)) {
            $refuse('the person who granted it is no longer the Owner.');
        }

        $row->journals_posted = $posted + 1;
        $row->save();

        return $posted + 1;
    }

    /**
     * Called by the correction service before commit: every planned journal was
     * posted; the exception is used (consumed) and linked to its correction.
     *
     * @throws ClosedPeriodPassException
     */
    public function close(ClosedPeriodPass $pass, CorrectionLink $link, int $plannedJournals): ClosedPeriodException
    {
        $row = ClosedPeriodException::query()->withoutGlobalScopes()->whereKey($pass->exceptionId)->lockForUpdate()->firstOrFail();
        $posted = Journal::query()->withoutGlobalScopes()->where('period_exception_id', $row->id)->count();

        if ($row->status !== ClosedPeriodException::GRANTED || $posted !== $plannedJournals || (int) $row->journals_posted !== $posted) {
            throw new ClosedPeriodPassException('The closed-month exception did not post exactly what it was granted for.');
        }

        $row->status = ClosedPeriodException::USED;
        $row->used_at = now();
        $row->correction_link_id = $link->id;
        $row->save();

        return $row;
    }
}
