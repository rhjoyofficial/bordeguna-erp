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
use App\Modules\Inventory\Actions\ReverseStockReceipt;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Models\CropHarvest;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * ⭐ Farm harvest (B9, 24 Oct 2026): a harvest's own reversal.
 *
 *   reverse()       every line's batch emptied through ReverseStockReceipt
 *                   (the exact layer it made, never FIFO), then the mirror of
 *                   its journal through ReverseJournal: Dr Crops in Progress /
 *                   Cr Stock on Hand, so the season's cost is back on the head
 *                   to the paisa; on the date the date policy gave (its own)
 *   markOriginal()  the Reversible columns; the harvest, its lines, its
 *                   batches (now empty) and its first journal stay
 *
 * Correct is not offered (CropHarvestActionPolicy), so there is no successor.
 */
class CropHarvestCorrector implements DocumentCorrector
{
    public function __construct(
        private readonly CropHarvestActionPolicy $policy,
        private readonly ReverseStockReceipt $takeBack,
        private readonly ReverseJournal $reverseJournal,
    ) {}

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function find(int $documentId): ?Model
    {
        return CropHarvest::query()->find($documentId);
    }

    public function lockForCommit(int $documentId): Model
    {
        $harvest = CropHarvest::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();

        Journal::query()->where('source_type', $harvest->getMorphClass())->where('source_id', $harvest->getKey())->lockForUpdate()->get(['id']);

        $movementIds = $harvest->lines()->pluck('stock_movement_id')->filter();
        StockBatch::query()->whereIn('id', StockMovement::query()->whereIn('id', $movementIds)->pluck('batch_id'))->lockForUpdate()->get(['id']);

        return $harvest->load(['lines.stockMovement.batch', 'lines.product.unitOfMeasure', 'cropAccount']);
    }

    public function fingerprint(Model $document): string
    {
        /** @var CropHarvest $document */
        $lock = AccountingPeriod::current();
        $batches = $document->lines()->with('stockMovement.batch')->get()->map(fn ($line) => [
            $line->id, $line->stock_movement_id, (string) $line->stockMovement?->batch?->current_quantity,
            StockMovement::query()->where('batch_id', $line->stockMovement?->batch_id)->max('id'),
        ])->all();

        return hash('sha256', (string) json_encode([
            'h' => [
                $document->id, $document->number, $document->harvested_on?->toDateString(), $document->crop_account_id,
                (int) $document->amount_paisa, $document->journal_id, $document->reversed_at?->toIso8601String(),
            ],
            'batches' => $batches,
            'journals' => Journal::query()->where('source_type', $document->getMorphClass())->where('source_id', $document->getKey())
                ->orderBy('id')->get(['id', 'reverses_journal_id'])->map(fn ($j) => [$j->id, $j->reverses_journal_id])->all(),
            'period' => [$lock?->closed_through?->toDateString(), (bool) $lock?->is_unlocked],
        ]));
    }

    public function validateReplacement(Model $document, array $input): ReplacementData
    {
        throw ValidationException::withMessages(['lines' => 'A harvest is not corrected in place. Reverse it and record it again.']);
    }

    public function replacementInput(ReplacementData $replacement): array
    {
        return $replacement->input;
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var CropHarvest $document */
        $document->loadMissing(['lines.product.unitOfMeasure', 'cropAccount']);
        $on = Tk::date($dates->reversalDate);
        $lines = [];

        foreach ($document->lines as $line) {
            $quantity = rtrim(rtrim((string) $line->quantity, '0'), '.');
            $unit = $line->product?->unitOfMeasure?->abbreviation ?? 'units';
            $lines[] = new EffectLine("{$quantity} {$unit} of {$line->product?->name} come out of batch {$line->batch_number} on {$on}.", null, null, 'stock');
        }

        $amount = (int) $document->amount_paisa;
        $lines[] = new EffectLine(Tk::of($amount)." goes back on {$document->cropAccount?->label()} on {$on}, and Stock on Hand comes down by the batches' value.", $document->cropAccount?->label(), $amount, 'ledger');

        return $lines;
    }

    public function describeStays(Model $document, DocumentAction $action): array
    {
        return ["{$document->number} stays, marked Reversed.", 'The reversal gets its own JV number.', 'To bring the crop into stock again, record a new harvest.'];
    }

    public function originalDate(Model $document): Carbon
    {
        /** @var CropHarvest $document */
        return $this->policy->originalDate($document);
    }

    public function totalMinor(Model $document): int
    {
        return (int) $document->amount_paisa;
    }

    public function makerAndLastEditorIds(Model $document): array
    {
        return [(int) $document->created_by];
    }

    public function journalOf(Model $document): ?Journal
    {
        /** @var CropHarvest $document */
        return $document->journal_id === null ? null : Journal::query()->find($document->journal_id);
    }

    public function reverse(Model $document, CommitContext $ctx): ?Journal
    {
        /** @var CropHarvest $document */
        $journal = $this->journalOf($document) ?? throw new LogicException("{$document->number} has no ledger entry to reverse.");
        $note = "Reversal of harvest {$document->number}: {$ctx->reason->display()}";

        try {
            foreach ($document->lines as $line) {
                $this->takeBack->handle(
                    receipts: [$line->stockMovement],
                    quantity: (string) $line->quantity,
                    performedBy: $ctx->actor,
                    note: $note,
                    reason: MovementReason::Harvest,
                    occurredAt: $ctx->dates->reversalDate->copy()->startOfDay(),
                    pass: $ctx->pass,
                );
            }

            return $this->reverseJournal->handle($journal->load('lines'), $ctx->actor, $ctx->dates->reversalDate, $note, $ctx->pass);
        } catch (RuntimeException|InvalidArgumentException|DomainException|PeriodClosedException $e) {
            throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
        }
    }

    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model
    {
        throw new LogicException('A harvest has no successor: Correct is not offered.');
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
        return CropHarvest::class;
    }

    /**
     * This type's own invariants: marked reversed; every batch it made is
     * empty again, by a mirror on the same batch; its journal is reversed by
     * the reversal it names.
     */
    public function assertInvariants(Model $original, ?Model $successor): void
    {
        /** @var CropHarvest $original */
        if (! $original->isReversed()) {
            throw new LogicException("{$original->number} was not marked.");
        }

        foreach ($original->lines()->with('stockMovement.batch')->get() as $line) {
            $mirror = StockMovement::query()->where('reverses_movement_id', $line->stock_movement_id)->get();

            if ($mirror->isEmpty() || $mirror->contains(fn ($m) => (int) $m->batch_id !== (int) $line->stockMovement->batch_id)
                || bccomp((string) $line->stockMovement->batch->fresh()->current_quantity, '0', 4) !== 0) {
                throw new LogicException("{$original->number}'s reversal does not take back what it brought in.");
            }
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
