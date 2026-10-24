<?php

namespace App\Modules\Sales\Corrections;

use App\Models\User;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Corrections\Concerns\CorrectsAccountsDocuments;
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
use App\Modules\Sales\Models\CommissionPayment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use LogicException;

/** ⭐ A commission payment reversed and KEPT (25 Oct 2026): the settlement owes it again. */
class CommissionPaymentCorrector implements DocumentCorrector
{
    use CorrectsAccountsDocuments;

    public function __construct(
        private readonly CommissionPaymentActionPolicy $policy,
        private readonly ReverseJournal $reverseJournal,
    ) {}

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function find(int $documentId): ?Model
    {
        return CommissionPayment::query()->find($documentId);
    }

    public function lockForCommit(int $documentId): Model
    {
        $payment = CommissionPayment::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();
        $this->lockJournalsOf($payment);

        return $payment;
    }

    public function fingerprint(Model $document): string
    {
        /** @var CommissionPayment $document */
        return hash('sha256', (string) json_encode([
            'v' => [$document->id, $document->paid_on?->toDateString(), (string) $document->amount, $document->money_account_id, $document->reversed_at?->toIso8601String()],
            ...$this->ledgerState($document),
        ]));
    }

    public function validateReplacement(Model $document, array $input): ReplacementData
    {
        throw ValidationException::withMessages(['replacement' => 'A commission payment is reversed and paid again. It is not corrected in one step.']);
    }

    public function replacementInput(ReplacementData $replacement): array
    {
        return $replacement->input;
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var CommissionPayment $document */
        $document->loadMissing(['settlement', 'moneyAccount']);
        $amount = $document->money()->minor;

        return [
            new EffectLine(Tk::of($amount).' goes back into '.$document->moneyAccount?->label().' on '.Tk::date($dates->reversalDate).'.', $document->moneyAccount?->label(), $amount, 'ledger'),
            new EffectLine("{$document->settlement?->number} owes ".Tk::of($amount).' again.', $document->settlement?->number, $amount, 'allocation'),
        ];
    }

    public function describeStays(Model $document, DocumentAction $action): array
    {
        return ["{$this->policy->numberOf($document)} stays, marked Reversed.", 'The reversal gets its own JV number.'];
    }

    public function originalDate(Model $document): Carbon
    {
        return $document->paid_on->copy()->startOfDay();
    }

    public function totalMinor(Model $document): int
    {
        /** @var CommissionPayment $document */
        return $document->money()->minor;
    }

    public function journalOf(Model $document): ?Journal
    {
        return $this->liveJournalOf($document);
    }

    public function reverse(Model $document, CommitContext $ctx): ?Journal
    {
        $number = $this->policy->numberOf($document);
        $journal = $this->journalOf($document) ?? throw new LogicException("{$number} has no single ledger entry to reverse.");

        try {
            return $this->reverseJournal->handle($journal->load('lines'), $ctx->actor, $ctx->dates->reversalDate, "Reversal of commission payment {$number}: {$ctx->reason->display()}", $ctx->pass);
        } catch (PeriodClosedException $e) {
            throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
        }
    }

    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model
    {
        throw new LogicException('A commission payment has no successor: Correct is not offered.');
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
        return CommissionPayment::class;
    }

    public function assertInvariants(Model $original, ?Model $successor): void
    {
        $this->assertReversedBy($original, $this->policy->numberOf($original));
    }

    public function visibleTo(Model $document, User $user): bool
    {
        return $user->can(CommissionSettlementActionPolicy::PERMISSION);
    }
}
