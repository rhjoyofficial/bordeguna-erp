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
use App\Modules\Inventory\Actions\PostInventoryAdjustmentJournal;
use App\Modules\Inventory\Actions\RestoreStockIssue;
use App\Modules\Inventory\Actions\ReverseStockReceipt;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockValue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use Spatie\Activitylog\Models\Activity;

/**
 * ⭐ Corrections wave 1, B4 (20 Oct 2026): a stock adjustment's own reversal,
 * into the SAME batches (FINAL-PLAN 3.3).
 *
 *   reverse()       damage or loss: every unit goes back onto the exact batch
 *                   it left, at the cost it left at (RestoreStockIssue);
 *                   found stock: the units come back out of the batch they
 *                   created (ReverseStockReceipt); then ReverseJournal on its
 *                   one journal, if it posted one. All on the adjustment's own
 *                   date and time, so the batch history reads in order.
 *   markOriginal()  the Reversible columns; the adjustment, its approval, its
 *                   movements and its journal all stay
 *
 * ⚠ No generic "inverse row" (ChatGPT REPLY-0 3.3): each new movement names the
 * one it undoes (`reverses_movement_id`), the batch quantity follows from the
 * movements (StockMovementObserver), and the reversal's stock value must equal
 * the original's to the paisa or the whole thing rolls back.
 *
 * Correct is not offered (InventoryAdjustmentActionPolicy), so no successor.
 */
class InventoryAdjustmentCorrector implements DocumentCorrector
{
    public function __construct(
        private readonly InventoryAdjustmentActionPolicy $policy,
        private readonly ReverseJournal $reverseJournal,
        private readonly RestoreStockIssue $restore,
        private readonly ReverseStockReceipt $takeBack,
        private readonly PostInventoryAdjustmentJournal $posting,
    ) {}

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function find(int $documentId): ?Model
    {
        return InventoryAdjustment::query()->find($documentId);
    }

    public function lockForCommit(int $documentId): Model
    {
        $adjustment = InventoryAdjustment::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();

        Journal::query()
            ->where('source_type', $adjustment->getMorphClass())
            ->where('source_id', $adjustment->getKey())
            ->lockForUpdate()
            ->get(['id']);

        StockBatch::query()->whereIn('id', $adjustment->movements()->pluck('batch_id'))->lockForUpdate()->get(['id']);

        return $adjustment->load(['movements', 'approvalRequest']);
    }

    public function fingerprint(Model $document): string
    {
        /** @var InventoryAdjustment $document */
        $movements = $document->movements()->orderBy('id')->get(['id', 'batch_id', 'quantity', 'unit_cost', 'reverses_movement_id']);
        $batches = StockBatch::query()->whereIn('id', $movements->pluck('batch_id'))->orderBy('id')->get(['id', 'current_quantity', 'unit_cost']);
        $lastOnBatches = StockMovement::query()->whereIn('batch_id', $movements->pluck('batch_id'))->max('id');
        $lock = AccountingPeriod::current();

        return hash('sha256', (string) json_encode([
            'a' => [
                $document->id, $document->direction?->value, (string) $document->quantity, (string) $document->unit_cost,
                Carbon::parse($document->effective_date)->toIso8601String(), $document->approvalRequest?->status?->value,
                $document->reversed_at?->toIso8601String(),
            ],
            'movements' => $movements->map(fn ($m) => [$m->id, $m->batch_id, (string) $m->quantity, (string) $m->unit_cost, $m->reverses_movement_id])->all(),
            'batches' => $batches->map(fn ($b) => [$b->id, (string) $b->current_quantity, (string) $b->unit_cost])->all(),
            'last_on_batches' => $lastOnBatches,
            'journals' => Journal::query()->where('source_type', $document->getMorphClass())->where('source_id', $document->getKey())
                ->orderBy('id')->get(['id', 'reverses_journal_id'])->map(fn ($j) => [$j->id, $j->reverses_journal_id])->all(),
            'period' => [$lock?->closed_through?->toDateString(), (bool) $lock?->is_unlocked],
        ]));
    }

    public function validateReplacement(Model $document, array $input): ReplacementData
    {
        throw ValidationException::withMessages(['replacement' => 'A stock correction is not corrected in place. Reverse it and raise a new one.']);
    }

    public function replacementInput(ReplacementData $replacement): array
    {
        return $replacement->input;
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var InventoryAdjustment $document */
        $document->loadMissing(['product.unitOfMeasure', 'warehouse']);
        $unit = $document->product?->unitOfMeasure?->abbreviation ?? 'units';
        $on = Tk::date($dates->reversalDate);
        $total = $this->totalMinor($document);
        $lines = [];

        foreach ($this->originals($document) as $movement) {
            $batch = $movement->batch?->batch_number ?? "#{$movement->batch_id}";
            $quantity = rtrim(rtrim((string) $movement->quantity, '0'), '.');

            $lines[] = $document->direction === AdjustmentDirection::Increase
                ? new EffectLine("{$quantity} {$unit} of {$document->product?->name} come back out of batch {$batch} at {$document->warehouse?->name} on {$on}.", null, null, 'stock')
                : new EffectLine("{$quantity} {$unit} of {$document->product?->name} go back into batch {$batch} at {$document->warehouse?->name} on {$on}.", null, null, 'stock');
        }

        if ($this->journalOf($document) !== null) {
            $lines[] = $document->direction === AdjustmentDirection::Increase
                ? new EffectLine(Tk::of($total).' comes off Stock on Hand, and the gain it recorded is taken back.', 'Stock on Hand', -$total, 'ledger')
                : new EffectLine(Tk::of($total).' goes back onto Stock on Hand, and the loss it recorded is taken back.', 'Stock on Hand', $total, 'ledger');
        }

        return $lines;
    }

    public function describeStays(Model $document, DocumentAction $action): array
    {
        $number = $this->policy->numberOf($document);

        return ["{$number} stays, marked Reversed, with its approval and its movements.", 'The reversal adds new movements on the same batches and gets its own JV number.'];
    }

    public function originalDate(Model $document): Carbon
    {
        /** @var InventoryAdjustment $document */
        return $this->policy->originalDate($document);
    }

    public function totalMinor(Model $document): int
    {
        /** @var InventoryAdjustment $document */
        return $this->policy->valueMinor($document);
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
        /** @var InventoryAdjustment $document */
        return $this->posting->existingJournal($document);
    }

    public function reverse(Model $document, CommitContext $ctx): ?Journal
    {
        /** @var InventoryAdjustment $document */
        $originals = $this->originals($document);
        $note = "Reversal of adjustment #{$document->id}: {$ctx->reason->display()}";

        // ⚠ The adjustment's own moment when the reversal lands on its own day:
        // a reversal stamped at midnight would read BEFORE the units it undoes.
        $effective = Carbon::parse($document->effective_date);
        $at = $ctx->dates->reversalDate->isSameDay($effective) ? $effective : $ctx->dates->reversalDate;

        try {
            $written = $document->direction === AdjustmentDirection::Increase
                ? $this->takeBack->handle(
                    receipts: $originals,
                    quantity: (string) $originals->sum(fn (StockMovement $m) => (float) $m->quantity),
                    performedBy: $ctx->actor,
                    note: $note,
                    reason: MovementReason::Adjustment,
                    occurredAt: $at,
                    pass: $ctx->pass,
                )->map(fn ($reversed) => $reversed->movement)
                : $this->restore->handle(
                    restorations: $originals->map(fn (StockMovement $m) => ['movement' => $m, 'quantity' => (string) $m->quantity])->all(),
                    performedBy: $ctx->actor,
                    note: $note,
                    reason: MovementReason::Adjustment,
                    occurredAt: $at,
                    pass: $ctx->pass,
                );
        } catch (InsufficientStockException|InvalidArgumentException|PeriodClosedException $e) {
            throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
        }

        StockMovement::query()->whereIn('id', $written->pluck('id'))->update(['adjustment_id' => $document->id, 'reason_id' => $document->reason_id]);

        $journal = $this->journalOf($document);

        if ($journal === null) {
            return null;
        }

        try {
            return $this->reverseJournal->handle($journal->load('lines'), $ctx->actor, $ctx->dates->reversalDate, $note, $ctx->pass);
        } catch (PeriodClosedException $e) {
            throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
        }
    }

    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model
    {
        throw new LogicException('A stock correction has no successor: Correct is not offered.');
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
        return InventoryAdjustment::class;
    }

    /**
     * This type's own invariants: marked; every original movement has exactly
     * its mirror on the same batch; the stock value of the two nets to zero to
     * the paisa (so Stock on Hand and the movements still agree); and the
     * journal, when there is one, is reversed by the journal it names.
     */
    public function assertInvariants(Model $original, ?Model $successor): void
    {
        /** @var InventoryAdjustment $original */
        if (! $original->isReversed()) {
            throw new LogicException("Adjustment #{$original->id} was not marked.");
        }

        $movements = $original->movements()->get();
        $originals = $movements->whereNull('reverses_movement_id');
        $mirrors = $movements->whereNotNull('reverses_movement_id');

        foreach ($originals as $movement) {
            $back = $mirrors->where('reverses_movement_id', $movement->id);

            if ($back->isEmpty() || $back->contains(fn ($m) => (int) $m->batch_id !== (int) $movement->batch_id)
                || bccomp((string) $back->sum(fn ($m) => (float) $m->quantity), (string) (float) $movement->quantity, 4) !== 0) {
                throw new LogicException("Adjustment #{$original->id}'s reversal does not mirror its movements.");
            }
        }

        if (! StockValue::sum($movements)->isZero()) {
            throw new LogicException("Adjustment #{$original->id}'s reversal does not take back its stock value.");
        }

        $journal = $this->journalOf($original);

        if ($journal !== null && ((int) $original->reversal_journal_id === 0 || ! $journal->hasBeenReversed())) {
            throw new LogicException("Adjustment #{$original->id}'s journal was not reversed.");
        }
    }

    public function visibleTo(Model $document, User $user): bool
    {
        return $this->policy->canView($document, $user);
    }

    /** @return Collection<int, StockMovement> */
    private function originals(InventoryAdjustment $adjustment)
    {
        return $adjustment->movements()->whereNull('reverses_movement_id')->with('batch')->orderBy('id')->get();
    }
}
