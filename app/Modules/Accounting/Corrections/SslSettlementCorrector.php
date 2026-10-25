<?php

namespace App\Modules\Accounting\Corrections;

use App\Models\User;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Corrections\Concerns\CorrectsAccountsDocuments;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\SslSettlement;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\CommitContext;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\Contracts\DocumentCorrector;
use App\Modules\Foundation\Corrections\CorrectionDates;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectLine;
use App\Modules\Foundation\Corrections\ReplacementData;
use App\Modules\Foundation\Corrections\Tk;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * ⭐ Joy's answer 9 (25 Oct 2026): an SSL settlement's reversal. ReverseJournal
 * on its one journal, on the date the date policy gave; the bank, the SSL
 * charge and SSL Commerz Clearing move back together. No successor: Correct is
 * not offered (reverse and record it again).
 */
class SslSettlementCorrector implements DocumentCorrector
{
    use CorrectsAccountsDocuments;

    public function __construct(
        private readonly SslSettlementActionPolicy $policy,
        private readonly ReverseJournal $reverseJournal,
    ) {}

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function find(int $documentId): ?Model
    {
        return SslSettlement::query()->find($documentId);
    }

    public function lockForCommit(int $documentId): Model
    {
        $settlement = SslSettlement::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();
        $this->lockJournalsOf($settlement);

        return $settlement;
    }

    public function fingerprint(Model $document): string
    {
        /** @var SslSettlement $document */
        return hash('sha256', (string) json_encode([
            'v' => [
                $document->id, $document->number, $document->settled_on?->toDateString(),
                (int) $document->bank_money_account_id, (int) $document->clearing_money_account_id,
                (string) $document->gross, (string) $document->fee, (string) $document->net,
                $document->reference, $document->reversed_at?->toIso8601String(),
            ],
            ...$this->ledgerState($document),
        ]));
    }

    public function validateReplacement(Model $document, array $input): ReplacementData
    {
        throw ValidationException::withMessages(['replacement' => 'An SSL settlement is not corrected in place. Reverse it and record it again.']);
    }

    public function replacementInput(ReplacementData $replacement): array
    {
        return $replacement->input;
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var SslSettlement $document */
        $document->loadMissing(['bank', 'clearing']);
        $on = Tk::date($dates->reversalDate);
        $gross = Money::ofTaka((string) $document->gross)->minor;
        $fee = Money::ofTaka((string) $document->fee)->minor;
        $net = Money::ofTaka((string) $document->net)->minor;
        $bank = $document->bank?->name ?? 'the bank';
        $clearing = $document->clearing?->name ?? 'SSL Commerz Clearing';

        $lines = [
            new EffectLine(Tk::of($net)." comes out of {$bank} on {$on}.", $bank, -$net, 'ledger'),
            new EffectLine(Tk::of($gross)." goes back into {$clearing} on {$on}.", $clearing, $gross, 'ledger'),
        ];

        if ($fee > 0) {
            $lines[] = new EffectLine('The SSL charge of '.Tk::of($fee)." comes off Payment Gateway Charge on {$on}.", 'Payment Gateway Charge', -$fee, 'ledger');
        }

        return $lines;
    }

    public function describeStays(Model $document, DocumentAction $action): array
    {
        return ["{$document->number} stays, marked Reversed.", 'The reversal gets its own JV number.'];
    }

    public function originalDate(Model $document): Carbon
    {
        /** @var SslSettlement $document */
        return $document->settled_on->copy()->startOfDay();
    }

    public function totalMinor(Model $document): int
    {
        /** @var SslSettlement $document */
        return Money::ofTaka((string) $document->gross)->minor;
    }

    public function journalOf(Model $document): ?Journal
    {
        return $this->liveJournalOf($document);
    }

    public function reverse(Model $document, CommitContext $ctx): ?Journal
    {
        $journal = $this->journalOf($document) ?? throw new LogicException("{$document->number} has no single ledger entry to reverse.");

        try {
            return $this->reverseJournal->handle($journal->load('lines'), $ctx->actor, $ctx->dates->reversalDate, "Reversal of SSL settlement {$document->number}: {$ctx->reason->display()}", $ctx->pass);
        } catch (PeriodClosedException $e) {
            throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
        }
    }

    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model
    {
        throw new LogicException('An SSL settlement has no successor: Correct is not offered.');
    }

    public function journalOfSuccessor(Model $successor): ?Journal
    {
        return $this->liveJournalOf($successor);
    }

    public function markOriginal(Model $document, ?Journal $reversal, CommitContext $ctx): void
    {
        $this->markReversed($document, $reversal, $ctx);
    }

    public function successorClass(): string
    {
        return SslSettlement::class;
    }

    public function assertInvariants(Model $original, ?Model $successor): void
    {
        $this->assertReversedBy($original, (string) $original->number);
    }

    public function visibleTo(Model $document, User $user): bool
    {
        return $user->can('accounting.view');
    }
}
