<?php

namespace App\Modules\Sales\Actions\Corrections;

use App\Models\User;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Corrections\PartyAdjustmentCorrector;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\PartyAdjustment;
use App\Modules\Foundation\Corrections\ClosedPeriodPass;
use App\Modules\Sales\Actions\PostCustomerAdvanceJournals;
use App\Modules\Sales\Corrections\Checks\SalesLedgerCheck;
use App\Modules\Sales\Models\CustomerAdvance;
use App\Modules\Sales\Models\CustomerAdvanceApplication;
use App\Modules\Sales\Models\CustomerAdvanceRefund;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * ⭐ Taking a customer advance document back (B11, 24 Oct 2026): an advance,
 * a use of one on an invoice, or a refund of one.
 *
 *   undo()  ReverseJournal on the document's one journal, on the date the
 *           correction rules give. A use on an invoice also releases its
 *           `customer_advance_applied` adjustment, kept and marked with what it
 *           had settled (`reversed_allocations`), so the invoice owes again by
 *           exactly what the advance paid and the customer statement shows the
 *           use and its undoing each on its own day
 *   mark()  the Reversible columns; amounts, dates and accounts never change
 *
 * ⚠ Called only from inside a correction's transaction (the three advance
 * correctors), never on its own: the policy, the dates and the Owner's pass
 * are decided there. Dependency order (an advance with a live use or refund)
 * is the policy's check, asked again under the lock.
 */
class UndoCustomerAdvanceDocument
{
    public function __construct(private readonly ReverseJournal $reverseJournal) {}

    public function undo(CustomerAdvance|CustomerAdvanceApplication|CustomerAdvanceRefund $document, User $by, Carbon $on, string $memo, ?ClosedPeriodPass $pass = null): ?Journal
    {
        $journal = SalesLedgerCheck::journalOf($document);

        if ($journal === null && self::expectsJournal($document)) {
            throw new LogicException("{$document->number} has no single ledger entry to reverse.");
        }

        $reversal = $journal === null ? null : $this->reverseJournal->handle($journal->load('lines'), $by, $on, $memo, $pass);

        if ($document instanceof CustomerAdvanceApplication && $document->party_adjustment_id !== null) {
            $adjustment = PartyAdjustment::query()->whereKey($document->party_adjustment_id)->lockForUpdate()->first();

            if ($adjustment !== null && ! $adjustment->isReversed()) {
                $released = PartyAdjustmentCorrector::releaseAllocations($adjustment);

                $adjustment->forceFill([
                    'reversed_allocations' => $released,
                    'reversed_on' => $on,
                    'reversed_at' => now(),
                    'reversed_by' => $by->id,
                    'reversal_reason' => $memo,
                    'reversal_journal_id' => $reversal?->id,
                ])->save();
            }
        }

        return $reversal;
    }

    public function mark(Model $document, User $by, Carbon $on, string $reason, ?Journal $reversal): void
    {
        $document->forceFill([
            'reversed_on' => $on,
            'reversed_at' => now(),
            'reversed_by' => $by->id,
            'reversal_reason' => $reason,
            'reversal_journal_id' => $reversal?->id,
        ])->save();
    }

    /** Would PostCustomerAdvanceJournals have posted something for it? */
    public static function expectsJournal(Model $document): bool
    {
        $on = match (true) {
            $document instanceof CustomerAdvance => $document->received_on,
            $document instanceof CustomerAdvanceApplication => $document->applied_on,
            $document instanceof CustomerAdvanceRefund => $document->refunded_on,
            default => null,
        };

        return $on !== null && PostCustomerAdvanceJournals::posting($on->copy()->startOfDay()) && (float) $document->amount !== 0.0;
    }
}
