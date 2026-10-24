<?php

namespace App\Modules\Inventory\Corrections;

use App\Models\User;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\CommitContext;
use App\Modules\Foundation\Corrections\Contracts\CountsClosedPeriodJournals;
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
use App\Modules\Inventory\Actions\PostStockCostDifference;
use App\Modules\Inventory\Actions\RestoreStockIssue;
use App\Modules\Inventory\Actions\ReverseStockReceipt;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockCostDifference;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\SoldStock;
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
 *
 * ⭐ 24 Oct 2026 (wave 1 gaps): found stock partly sold or used since is
 * reversed for what is LEFT; the cost of what was used posts today to Stock
 * Cost Corrections on Goods Sold (SoldStock, the Tk 10,000 rule; above it the
 * Owner). And a closed month no longer refuses an adjustment that posted no
 * journal: closedPeriodJournals() tells the Owner's pass the real count.
 */
class InventoryAdjustmentCorrector implements CountsClosedPeriodJournals, DocumentCorrector
{
    public function __construct(
        private readonly InventoryAdjustmentActionPolicy $policy,
        private readonly ReverseJournal $reverseJournal,
        private readonly RestoreStockIssue $restore,
        private readonly ReverseStockReceipt $takeBack,
        private readonly PostInventoryAdjustmentJournal $posting,
        private readonly PostStockCostDifference $costDifference,
    ) {}

    /** ⭐ 24 Oct 2026: the reversal mirrors the adjustment's journal, when it posted one, and nothing else. */
    public function closedPeriodJournals(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var InventoryAdjustment $document */
        $count = $this->journalOf($document) === null ? 0 : 1;

        return [$count, $count];
    }

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

        $used = collect(AdjustmentStockCheck::shares($document))->keyBy(fn ($share) => $share->in->id);

        foreach ($this->originals($document) as $movement) {
            $batch = $movement->batch?->batch_number ?? "#{$movement->batch_id}";
            $quantity = rtrim(rtrim((string) $movement->quantity, '0'), '.');
            $share = $used[$movement->id] ?? null;

            // ⭐ 24 Oct 2026: found stock partly used since.
            if ($share !== null && $share->isUsed()) {
                $left = rtrim(rtrim($share->remaining, '0'), '.');
                $lines[] = new EffectLine("Only what is left ({$left} {$unit}) of {$document->product?->name} comes back out of batch {$batch} on {$on}.", null, null, 'stock');
                $lines[] = new EffectLine(SoldStock::effectSentence($share->costMinor, $share->used, $unit, $document->product?->name), 'Stock Cost Corrections on Goods Sold', -$share->costMinor, 'ledger');

                continue;
            }

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

        // ⭐ 24 Oct 2026: of found stock partly used, only what is left.
        $shares = AdjustmentStockCheck::shares($document);
        $partly = collect($shares)->contains(fn ($share) => $share->isUsed());

        foreach ($partly ? $shares : [] as $share) {
            if ($share->refusal !== null) {
                throw ValidationException::withMessages(['reason_text' => $share->refusal]);
            }
        }

        $back = $partly
            ? array_sum(array_map(fn ($share) => SoldStock::scaled($share->remaining), $shares))
            : SoldStock::scaled((string) $originals->sum(fn (StockMovement $m) => (float) $m->quantity));

        try {
            $written = match (true) {
                $document->direction === AdjustmentDirection::Increase && $back === 0 => collect(),
                $document->direction === AdjustmentDirection::Increase => $this->takeBack->handle(
                    receipts: $originals,
                    quantity: SoldStock::unscaled($back),
                    performedBy: $ctx->actor,
                    note: $note,
                    reason: MovementReason::Adjustment,
                    occurredAt: $at,
                    pass: $ctx->pass,
                )->map(fn ($reversed) => $reversed->movement),
                default => $this->restore->handle(
                    restorations: $originals->map(fn (StockMovement $m) => ['movement' => $m, 'quantity' => (string) $m->quantity])->all(),
                    performedBy: $ctx->actor,
                    note: $note,
                    reason: MovementReason::Adjustment,
                    occurredAt: $at,
                    pass: $ctx->pass,
                ),
            };
        } catch (InsufficientStockException|InvalidArgumentException|PeriodClosedException $e) {
            throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
        }

        StockMovement::query()->whereIn('id', $written->pluck('id'))->update(['adjustment_id' => $document->id, 'reason_id' => $document->reason_id]);

        // The ledger takes the whole adjustment back; the batches gave back
        // only what was left. The gap is what the used units cost.
        if ($partly) {
            $difference = StockValue::sum($originals)->plus(StockValue::sum($written))->minor;
            $usedQuantity = SoldStock::unscaled(array_sum(array_map(fn ($share) => SoldStock::scaled($share->used), $shares)));

            if (($owner = SoldStock::ownerBlocker($difference, $ctx->actor, $ctx->approver)) !== null) {
                throw ValidationException::withMessages(['reason_text' => $owner->message]);
            }

            $this->costDifference->handle($document, $this->policy->numberOf($document), $difference, $usedQuantity, $note, $ctx->actor, $ctx->approver);
        }

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

        // ⭐ 24 Oct 2026: found stock partly used gave back only what was
        // left, and the gap posted as a stock cost difference to the paisa.
        $difference = Money::sum(StockCostDifference::query()
            ->where('source_type', $original->getMorphClass())
            ->where('source_id', $original->id)
            ->get()
            ->map(fn (StockCostDifference $row) => $row->money()));
        $partly = ! $difference->isZero();

        foreach ($originals as $movement) {
            $back = $mirrors->where('reverses_movement_id', $movement->id);
            $given = (string) $back->sum(fn ($m) => (float) $m->quantity);

            if ($back->contains(fn ($m) => (int) $m->batch_id !== (int) $movement->batch_id)
                || (! $partly && ($back->isEmpty() || bccomp($given, (string) (float) $movement->quantity, 4) !== 0))
                || ($partly && bccomp($given, (string) (float) $movement->quantity, 4) > 0)) {
                throw new LogicException("Adjustment #{$original->id}'s reversal does not mirror its movements.");
            }
        }

        if (StockValue::sum($movements)->minor !== $difference->minor) {
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
