<?php

namespace App\Modules\Accounting\Corrections;

use App\Models\User;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\PartyAdjustment;
use App\Modules\Accounting\Models\SetOff;
use App\Modules\Foundation\Corrections\CommitContext;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\Contracts\DocumentCorrector;
use App\Modules\Foundation\Corrections\CorrectionDates;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectLine;
use App\Modules\Foundation\Corrections\ReplacementData;
use App\Modules\Foundation\Corrections\Tk;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\AccountingPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use LogicException;
use Spatie\Activitylog\Models\Activity;

/**
 * ⭐ A set-off's own reversal (decision D-F, 18 Oct 2026; PHASE-0-CONTRACT 1.4).
 *
 *   reverse()       ReverseJournal on the set-off's one journal (Dr 1140 AR
 *                   customer / Cr 2110 AP supplier), on the date the date
 *                   policy gave (its own date by default); then the `set_off`
 *                   party adjustment is removed, so its allocations go and the
 *                   bills and invoices are OPEN again by exactly what it took
 *   markOriginal()  the Reversible columns and status `reversed`; the
 *                   set-off, its number, its lines and its first journal stay
 *
 * ⚠ The adjustment goes with the reversal for the reason ReverseVoucher
 * deletes the receipts it created: its journal line was the set-off's, now
 * reversed, so leaving the document would make the statements, the ageing
 * and the subledger reconciliation disagree with the ledger.
 *
 * Correct is not offered (SetOffActionPolicy), so there is no successor.
 */
class SetOffCorrector implements DocumentCorrector
{
    public function __construct(
        private readonly SetOffActionPolicy $policy,
        private readonly ReverseJournal $reverseJournal,
    ) {}

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function find(int $documentId): ?Model
    {
        return SetOff::query()->find($documentId);
    }

    public function lockForCommit(int $documentId): Model
    {
        $setOff = SetOff::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();

        Journal::query()
            ->where('source_type', $setOff->getMorphClass())
            ->where('source_id', $setOff->getKey())
            ->lockForUpdate()
            ->get(['id']);

        if ($setOff->party_adjustment_id !== null) {
            PartyAdjustment::query()->whereKey($setOff->party_adjustment_id)->lockForUpdate()->get(['id']);
        }

        return $setOff->load('lines');
    }

    public function fingerprint(Model $document): string
    {
        /** @var SetOff $document */
        $journals = Journal::query()
            ->where('source_type', $document->getMorphClass())
            ->where('source_id', $document->getKey())
            ->orderBy('id')
            ->get(['id', 'reverses_journal_id'])
            ->map(fn (Journal $j) => [$j->id, $j->reverses_journal_id])
            ->all();

        $lock = AccountingPeriod::current();

        return hash('sha256', (string) json_encode([
            's' => [
                $document->id, $document->number, $document->set_off_on?->toDateString(), $document->supplier_id,
                $document->customer_id, (string) $document->amount, $document->status, $document->party_adjustment_id,
                $document->journal_id, $document->reversed_at?->toIso8601String(),
            ],
            'lines' => $document->lines()->orderBy('id')->get(['sales_invoice_id', 'supplier_bill_id', 'amount'])
                ->map(fn ($l) => [$l->sales_invoice_id, $l->supplier_bill_id, (string) $l->amount])->all(),
            'journals' => $journals,
            'period' => [$lock?->closed_through?->toDateString(), (bool) $lock?->is_unlocked],
        ]));
    }

    public function validateReplacement(Model $document, array $input): ReplacementData
    {
        throw ValidationException::withMessages(['lines' => 'A set-off is not corrected in place. Reverse it and raise a new one.']);
    }

    public function replacementInput(ReplacementData $replacement): array
    {
        return $replacement->input;
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var SetOff $document */
        $document->loadMissing(['lines.bill', 'lines.invoice', 'supplier', 'customer']);
        $total = $this->totalMinor($document);
        $on = Tk::date($dates->reversalDate);

        $lines = [
            new EffectLine(Tk::of($total)." goes back on Accounts Payable for {$document->supplier?->name} on {$on}.", 'Accounts Payable', $total, 'ledger'),
            new EffectLine(Tk::of($total)." goes back on Accounts Receivable for {$document->customer?->name} on {$on}.", 'Accounts Receivable', $total, 'ledger'),
        ];

        foreach ($document->lines as $line) {
            $amount = $line->money()->minor;
            $what = $line->bill !== null ? "Bill {$line->bill->bill_number}" : "Invoice {$line->invoice?->invoice_number}";
            $lines[] = new EffectLine("{$what} is open again by ".Tk::of($amount).'.', null, $amount, 'allocation');
        }

        return $lines;
    }

    public function describeStays(Model $document, DocumentAction $action): array
    {
        return ["{$document->number} stays, marked Reversed.", 'The reversal gets its own JV number.', 'To clear these documents again, raise a new set-off.'];
    }

    public function originalDate(Model $document): Carbon
    {
        return $document->set_off_on->copy()->startOfDay();
    }

    public function totalMinor(Model $document): int
    {
        /** @var SetOff $document */
        return $document->money()->minor;
    }

    public function makerAndLastEditorIds(Model $document): array
    {
        $lastEditor = Activity::query()
            ->where('subject_type', $document->getMorphClass())
            ->where('subject_id', $document->getKey())
            ->where('event', 'updated')
            ->whereNotNull('causer_id')
            ->latest('id')
            ->value('causer_id');

        return array_values(array_unique(array_filter([(int) $document->created_by, $lastEditor === null ? null : (int) $lastEditor])));
    }

    public function journalOf(Model $document): ?Journal
    {
        /** @var SetOff $document */
        return $document->journal_id === null ? null : Journal::query()->find($document->journal_id);
    }

    public function reverse(Model $document, CommitContext $ctx): ?Journal
    {
        /** @var SetOff $document */
        $journal = $this->journalOf($document) ?? throw new LogicException("{$document->number} has no ledger entry to reverse.");

        try {
            $reversal = $this->reverseJournal->handle(
                $journal->load('lines'),
                $ctx->actor,
                $ctx->dates->reversalDate,
                "Reversal of set-off {$document->number}: {$ctx->reason->display()}",
                $ctx->pass,
            );
        } catch (PeriodClosedException $e) {
            throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
        }

        // The allocations cascade with the adjustment: both sides open again.
        PartyAdjustment::query()->whereKey($document->party_adjustment_id)->first()?->delete();

        return $reversal;
    }

    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model
    {
        throw new LogicException('A set-off has no successor: Correct is not offered.');
    }

    public function journalOfSuccessor(Model $successor): ?Journal
    {
        return null;
    }

    public function markOriginal(Model $document, ?Journal $reversal, CommitContext $ctx): void
    {
        $document->forceFill([
            'status' => SetOff::REVERSED,
            'reversed_on' => $ctx->dates->reversalDate,
            'reversed_at' => now(),
            'reversed_by' => $ctx->actor->id,
            'reversal_reason' => $ctx->reason->display(),
            'reversal_journal_id' => $reversal?->id,
        ])->save();
    }

    public function successorClass(): string
    {
        return SetOff::class;
    }

    /**
     * This type's own invariant: marked reversed, its journal reversed by the
     * reversal it names, and no allocation of it left on any bill or invoice.
     */
    public function assertInvariants(Model $original, ?Model $successor): void
    {
        /** @var SetOff $original */
        if (! $original->isReversed()) {
            throw new LogicException("{$original->number} was not marked.");
        }

        $reversal = $original->reversal_journal_id === null ? null : Journal::query()->find($original->reversal_journal_id);

        if ($reversal === null || (int) $reversal->reverses_journal_id !== (int) $original->journal_id) {
            throw new LogicException("{$original->number}'s reversal does not undo its journal.");
        }

        if ($original->party_adjustment_id !== null && PartyAdjustment::query()->whereKey($original->party_adjustment_id)->exists()) {
            throw new LogicException("{$original->number} still clears its bills and invoices.");
        }
    }

    public function visibleTo(Model $document, User $user): bool
    {
        return $this->policy->canView($document, $user);
    }
}
