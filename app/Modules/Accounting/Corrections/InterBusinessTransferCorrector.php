<?php

namespace App\Modules\Accounting\Corrections;

use App\Models\User;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Corrections\Concerns\CorrectsAccountsDocuments;
use App\Modules\Accounting\Models\InterBusinessTransfer;
use App\Modules\Accounting\Models\Journal;
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
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * ⭐ Corrections wave 2 (B7, 24 Oct 2026): an inter-business transfer reversed
 * in BOTH businesses, in one transaction.
 *
 *   reverse()   the sender's journal (Dr bank / Cr Due from, undone) in the
 *               sender's books, with the Owner's pass when the sender's month
 *               is closed; then, inside the receiver's context, the receiver's
 *               journal, on the same date, refused if the receiver's month is
 *               closed (no pass reaches another business's books)
 *   markOriginal()  the Reversible columns, once, on the shared document
 *
 * ⚠ The tenant is always restored, also when the receiver refuses: the whole
 * transaction then rolls back, the sender's reversal with it.
 */
class InterBusinessTransferCorrector implements DocumentCorrector
{
    use CorrectsAccountsDocuments;

    public function __construct(
        private readonly InterBusinessTransferActionPolicy $policy,
        private readonly ReverseJournal $reverseJournal,
    ) {}

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function find(int $documentId): ?Model
    {
        // ⚠ No tenant scope on this model: only a transfer this business took
        // part in is found at all.
        return InterBusinessTransfer::query()->involving((int) app(BusinessContext::class)->id())->find($documentId);
    }

    public function lockForCommit(int $documentId): Model
    {
        $transfer = InterBusinessTransfer::query()->involving((int) app(BusinessContext::class)->id())->whereKey($documentId)->lockForUpdate()->firstOrFail();
        $this->lockJournalsOf($transfer);

        return $transfer;
    }

    public function fingerprint(Model $document): string
    {
        /** @var InterBusinessTransfer $document */
        return hash('sha256', (string) json_encode([
            'v' => [
                $document->id, $document->from_business_id, $document->to_business_id, $document->from_money_account_id,
                $document->to_money_account_id, $document->transferred_on?->toDateString(), (string) $document->amount,
                $document->note, $document->reversed_at?->toIso8601String(),
            ],
            'receiver_matched' => InterBusinessTransferActionPolicy::receiverMatched($document),
            ...$this->ledgerState($document),
        ]));
    }

    public function validateReplacement(Model $document, array $input): ReplacementData
    {
        throw ValidationException::withMessages(['replacement' => 'An inter-business transfer is reversed, then copied. It is not corrected in one step.']);
    }

    public function replacementInput(ReplacementData $replacement): array
    {
        return $replacement->input;
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var InterBusinessTransfer $document */
        $document->loadMissing(['fromBusiness:id,name', 'toBusiness:id,name', 'fromAccount', 'toAccount']);
        $amount = $document->money()->minor;
        $on = Tk::date($dates->reversalDate);
        $from = $document->fromAccount?->name ?? 'the sending account';
        $to = $document->toAccount?->name ?? 'the receiving account';

        return [
            new EffectLine(Tk::of($amount)." goes back into {$from} in {$document->fromBusiness?->name}'s books on {$on}.", $from, $amount, 'ledger'),
            new EffectLine(Tk::of($amount)." comes out of {$to} in {$document->toBusiness?->name}'s books on {$on}.", $to, -$amount, 'ledger'),
            new EffectLine('The amount due between the two businesses goes back by '.Tk::of($amount).' on both sides.', null, null, 'ledger'),
        ];
    }

    public function describeStays(Model $document, DocumentAction $action): array
    {
        $number = $this->policy->numberOf($document);

        return ["{$number} stays, marked Reversed, in both businesses.", 'Each business gets its own reversing journal. Copy opens the transfer form filled in.'];
    }

    public function originalDate(Model $document): Carbon
    {
        return $document->transferred_on->copy()->startOfDay();
    }

    public function totalMinor(Model $document): int
    {
        /** @var InterBusinessTransfer $document */
        return $document->money()->minor;
    }

    public function journalOf(Model $document): ?Journal
    {
        return $this->liveJournalOf($document);
    }

    public function reverse(Model $document, CommitContext $ctx): ?Journal
    {
        /** @var InterBusinessTransfer $document */
        $number = $this->policy->numberOf($document);
        $memo = "Reversal of inter-business transfer {$number}: {$ctx->reason->display()}";
        $journal = $this->journalOf($document) ?? throw new LogicException("{$number} has no single ledger entry to reverse in this business.");
        $here = (int) app(BusinessContext::class)->id();

        if ($here !== (int) $document->from_business_id) {
            throw ValidationException::withMessages(['reason_text' => 'Reverse it from the books it was sent from.']);
        }

        try {
            $reversal = $this->reverseJournal->handle($journal->load('lines'), $ctx->actor, $ctx->dates->reversalDate, $memo, $ctx->pass);
        } catch (PeriodClosedException $e) {
            throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
        }

        // The receiver's books, in the receiver's context, then back.
        TenantScope::enter((int) $document->to_business_id);

        try {
            $receiverJournal = $this->liveJournalOf($document);

            if ($receiverJournal === null) {
                $name = Business::withoutGlobalScopes()->find($document->to_business_id)?->name ?? 'the receiving business';

                throw new LogicException("{$number} has no single ledger entry in {$name}'s books.");
            }

            AccountingPeriod::assertOpenFor($ctx->dates->reversalDate);
            $this->reverseJournal->handle($receiverJournal->load('lines'), $ctx->actor, $ctx->dates->reversalDate, $memo);
        } catch (PeriodClosedException $e) {
            throw ValidationException::withMessages(['reason_text' => 'The receiving business: '.$e->getMessage()]);
        } finally {
            TenantScope::enter($here);
        }

        return $reversal;
    }

    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model
    {
        throw new LogicException('An inter-business transfer has no successor: Correct is not offered.');
    }

    public function journalOfSuccessor(Model $successor): ?Journal
    {
        return null;
    }

    public function markOriginal(Model $document, ?Journal $reversal, CommitContext $ctx): void
    {
        $this->markReversed($document, $reversal, $ctx);
    }

    public function successorClass(): string
    {
        return InterBusinessTransfer::class;
    }

    /** Marked, and neither business keeps a live journal for it. */
    public function assertInvariants(Model $original, ?Model $successor): void
    {
        /** @var InterBusinessTransfer $original */
        $this->assertReversedBy($original, $this->policy->numberOf($original));

        $live = Journal::query()->withoutGlobalScopes()
            ->where('source_type', $original->getMorphClass())
            ->where('source_id', $original->getKey())
            ->whereNull('reverses_journal_id')
            ->whereDoesntHave('reversal', fn ($q) => $q->withoutGlobalScopes())
            ->count();

        if ($live !== 0) {
            throw new LogicException("{$this->policy->numberOf($original)} still stands in one of the two businesses.");
        }
    }

    public function visibleTo(Model $document, User $user): bool
    {
        return $this->policy->canView($document, $user);
    }
}
