<?php

namespace App\Modules\Inventory\Corrections;

use App\Models\User;
use App\Modules\Accounting\Actions\ReverseJournal;
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
use App\Modules\Inventory\Models\CropWriteOff;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * ⭐ Farm harvest (B9, 24 Oct 2026): an approved crop write-off's reversal.
 *
 *   reverse()       ReverseJournal on its one journal (Dr Crops in Progress /
 *                   Cr the loss head), on the date the date policy gave
 *   markOriginal()  the Reversible columns; the write-off, its approval
 *                   history and its first journal stay
 *
 * Correct is not offered (CropWriteOffActionPolicy), so there is no successor.
 */
class CropWriteOffCorrector implements DocumentCorrector
{
    public function __construct(
        private readonly CropWriteOffActionPolicy $policy,
        private readonly ReverseJournal $reverseJournal,
    ) {}

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function find(int $documentId): ?Model
    {
        return CropWriteOff::query()->find($documentId);
    }

    public function lockForCommit(int $documentId): Model
    {
        $writeOff = CropWriteOff::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();

        Journal::query()->where('source_type', $writeOff->getMorphClass())->where('source_id', $writeOff->getKey())->lockForUpdate()->get(['id']);

        return $writeOff->load(['cropAccount', 'lossAccount']);
    }

    public function fingerprint(Model $document): string
    {
        /** @var CropWriteOff $document */
        $lock = AccountingPeriod::current();

        return hash('sha256', (string) json_encode([
            'w' => [
                $document->id, $document->number, $document->written_off_on?->toDateString(), $document->crop_account_id,
                $document->loss_account_id, (int) $document->amount_paisa, $document->status, $document->journal_id,
                $document->reversed_at?->toIso8601String(),
            ],
            'journals' => Journal::query()->where('source_type', $document->getMorphClass())->where('source_id', $document->getKey())
                ->orderBy('id')->get(['id', 'reverses_journal_id'])->map(fn ($j) => [$j->id, $j->reverses_journal_id])->all(),
            'period' => [$lock?->closed_through?->toDateString(), (bool) $lock?->is_unlocked],
        ]));
    }

    public function validateReplacement(Model $document, array $input): ReplacementData
    {
        throw ValidationException::withMessages(['amount' => 'A crop write-off is not corrected in place. Reverse it and raise it again.']);
    }

    public function replacementInput(ReplacementData $replacement): array
    {
        return $replacement->input;
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var CropWriteOff $document */
        $document->loadMissing(['cropAccount', 'lossAccount']);
        $amount = (int) $document->amount_paisa;
        $on = Tk::date($dates->reversalDate);

        return [
            new EffectLine(Tk::of($amount)." goes back on {$document->cropAccount?->label()} on {$on}.", $document->cropAccount?->label(), $amount, 'ledger'),
            new EffectLine(Tk::of($amount)." comes off {$document->lossAccount?->label()} on {$on}.", $document->lossAccount?->label(), -$amount, 'ledger'),
        ];
    }

    public function describeStays(Model $document, DocumentAction $action): array
    {
        return ["{$document->number} stays, marked Reversed, with its approval.", 'The reversal gets its own JV number.'];
    }

    public function originalDate(Model $document): Carbon
    {
        /** @var CropWriteOff $document */
        return $this->policy->originalDate($document);
    }

    public function totalMinor(Model $document): int
    {
        return (int) $document->amount_paisa;
    }

    public function makerAndLastEditorIds(Model $document): array
    {
        return array_values(array_unique(array_filter([(int) $document->created_by, (int) $document->approved_by])));
    }

    public function journalOf(Model $document): ?Journal
    {
        /** @var CropWriteOff $document */
        return $document->journal_id === null ? null : Journal::query()->find($document->journal_id);
    }

    public function reverse(Model $document, CommitContext $ctx): ?Journal
    {
        /** @var CropWriteOff $document */
        $journal = $this->journalOf($document) ?? throw new LogicException("{$document->number} has no ledger entry to reverse.");

        try {
            return $this->reverseJournal->handle(
                $journal->load('lines'),
                $ctx->actor,
                $ctx->dates->reversalDate,
                "Reversal of crop write-off {$document->number}: {$ctx->reason->display()}",
                $ctx->pass,
            );
        } catch (PeriodClosedException $e) {
            throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
        }
    }

    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model
    {
        throw new LogicException('A crop write-off has no successor: Correct is not offered.');
    }

    public function journalOfSuccessor(Model $successor): ?Journal
    {
        return null;
    }

    public function markOriginal(Model $document, ?Journal $reversal, CommitContext $ctx): void
    {
        $document->forceFill([
            'reversed_on' => $ctx->dates->reversalDate,
            'reversed_at' => now(),
            'reversed_by' => $ctx->actor->id,
            'reversal_reason' => $ctx->reason->display(),
            'reversal_journal_id' => $reversal?->id,
        ])->save();
    }

    public function successorClass(): string
    {
        return CropWriteOff::class;
    }

    public function assertInvariants(Model $original, ?Model $successor): void
    {
        /** @var CropWriteOff $original */
        if (! $original->isReversed()) {
            throw new LogicException("{$original->number} was not marked.");
        }

        $reversal = $original->reversal_journal_id === null ? null : Journal::query()->find($original->reversal_journal_id);

        if ($reversal === null || (int) $reversal->reverses_journal_id !== (int) $original->journal_id) {
            throw new LogicException("{$original->number}'s reversal does not undo its journal.");
        }
    }

    public function visibleTo(Model $document, User $user): bool
    {
        return $this->policy->canView($document, $user);
    }
}
