<?php

namespace App\Modules\Purchasing\Corrections;

use App\Models\User;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\PartyAdjustment;
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
use App\Modules\Inventory\Actions\RevalueStockBatch;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Purchasing\Models\SupplierDiscount;
use App\Modules\Purchasing\Models\SupplierDiscountAllocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use LogicException;
use Spatie\Activitylog\Models\Activity;

/**
 * ⭐ A supplier discount's own reversal (ruling P3, 18 Oct 2026).
 *
 *   reverse()       ReverseJournal on the discount's one journal (Cr 2110 AP
 *                   supplier / Dr 1150 / Dr the consumed head), on the date the
 *                   date policy gave; each batch it lowered gets the on-hand
 *                   share back through RevalueStockBatch (a value-only `in`
 *                   movement, so the stock subledger and 1150 agree); then the
 *                   party adjustment is removed, so the bill is owed again by
 *                   exactly the discount
 *   markOriginal()  the Reversible columns; the discount, its number, its
 *                   allocations and its first journal stay
 *
 * ⚠ The on-hand share goes back onto the SAME units only: SupplierDiscountStockCheck
 * refuses a reversal once any of them has moved, and the closed-month and
 * second-person rules are the corrections process's own.
 *
 * Correct is not offered (SupplierDiscountActionPolicy), so there is no successor.
 */
class SupplierDiscountCorrector implements DocumentCorrector
{
    public function __construct(
        private readonly SupplierDiscountActionPolicy $policy,
        private readonly ReverseJournal $reverseJournal,
        private readonly RevalueStockBatch $revalue,
    ) {}

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function find(int $documentId): ?Model
    {
        return SupplierDiscount::query()->find($documentId);
    }

    public function lockForCommit(int $documentId): Model
    {
        $discount = SupplierDiscount::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();

        Journal::query()
            ->where('source_type', $discount->getMorphClass())
            ->where('source_id', $discount->getKey())
            ->lockForUpdate()
            ->get(['id']);

        StockBatch::query()->whereIn('id', $discount->allocations()->pluck('stock_batch_id'))->lockForUpdate()->get(['id']);

        if ($discount->party_adjustment_id !== null) {
            PartyAdjustment::query()->whereKey($discount->party_adjustment_id)->lockForUpdate()->get(['id']);
        }

        return $discount->load('allocations');
    }

    public function fingerprint(Model $document): string
    {
        /** @var SupplierDiscount $document */
        $journals = Journal::query()
            ->where('source_type', $document->getMorphClass())
            ->where('source_id', $document->getKey())
            ->orderBy('id')
            ->get(['id', 'reverses_journal_id'])
            ->map(fn (Journal $j) => [$j->id, $j->reverses_journal_id])
            ->all();

        $lock = AccountingPeriod::current();

        return hash('sha256', (string) json_encode([
            'd' => [
                $document->id, $document->number, $document->kind, $document->discount_on?->toDateString(), $document->supplier_bill_id,
                (string) $document->amount, $document->party_adjustment_id, $document->journal_id, $document->reversed_at?->toIso8601String(),
            ],
            'allocations' => $document->allocations()->orderBy('id')->get(['stock_batch_id', 'on_hand_share_paisa', 'consumed_share_paisa', 'stock_movement_id'])
                ->map(fn ($a) => [$a->stock_batch_id, $a->on_hand_share_paisa, $a->consumed_share_paisa, $a->stock_movement_id])->all(),
            'journals' => $journals,
            'period' => [$lock?->closed_through?->toDateString(), (bool) $lock?->is_unlocked],
        ]));
    }

    public function validateReplacement(Model $document, array $input): ReplacementData
    {
        throw ValidationException::withMessages(['lines' => 'A discount is not corrected in place. Reverse it and record it again.']);
    }

    public function replacementInput(ReplacementData $replacement): array
    {
        return $replacement->input;
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var SupplierDiscount $document */
        $document->loadMissing(['bill:id,bill_number', 'supplier:id,name', 'allocations.batch:id,batch_number']);
        $total = $this->totalMinor($document);
        $on = Tk::date($dates->reversalDate);

        $lines = [
            new EffectLine(Tk::of($total)." goes back on Accounts Payable for {$document->supplier?->name} on {$on}.", 'Accounts Payable', $total, 'ledger'),
            new EffectLine("Bill {$document->bill?->bill_number} is owed again by ".Tk::of($total).'.', null, $total, 'allocation'),
        ];

        foreach ($document->allocations as $allocation) {
            if ($allocation->on_hand_share_paisa > 0) {
                $lines[] = new EffectLine('Batch '.($allocation->batch?->batch_number ?? "#{$allocation->stock_batch_id}").' is carried '.Tk::of($allocation->on_hand_share_paisa).' higher again.', 'Stock on Hand', $allocation->on_hand_share_paisa, 'stock');
            }
        }

        if ($document->consumed_paisa > 0) {
            $lines[] = new EffectLine(Tk::of($document->consumed_paisa).' goes back on cost of sales for stock already consumed or sold.', 'Purchase Discounts on Goods Sold', $document->consumed_paisa, 'ledger');
        }

        return $lines;
    }

    public function describeStays(Model $document, DocumentAction $action): array
    {
        return ["{$document->number} stays, marked Reversed.", 'The reversal gets its own JV number.', 'To take the discount again, record it again on the bill or the payment.'];
    }

    public function originalDate(Model $document): Carbon
    {
        return $document->discount_on->copy()->startOfDay();
    }

    public function totalMinor(Model $document): int
    {
        /** @var SupplierDiscount $document */
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
        /** @var SupplierDiscount $document */
        return $document->journal_id === null ? null : Journal::query()->find($document->journal_id);
    }

    public function reverse(Model $document, CommitContext $ctx): ?Journal
    {
        /** @var SupplierDiscount $document */
        $journal = $this->journalOf($document) ?? throw new LogicException("{$document->number} has no ledger entry to reverse.");

        try {
            $reversal = $this->reverseJournal->handle(
                $journal->load('lines'),
                $ctx->actor,
                $ctx->dates->reversalDate,
                "Reversal of {$document->label()} {$document->number}: {$ctx->reason->display()}",
                $ctx->pass,
            );
        } catch (PeriodClosedException $e) {
            throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
        }

        // The on-hand share back onto each batch: the mirror of the journal's
        // Dr Stock on Hand, to the paisa (value_delta = the share).
        foreach ($document->allocations as $allocation) {
            /** @var SupplierDiscountAllocation $allocation */
            if ($allocation->stock_movement_id === null || $allocation->on_hand_share_paisa <= 0) {
                continue;
            }

            $batch = StockBatch::query()->whereKey($allocation->stock_batch_id)->lockForUpdate()->firstOrFail();
            $movement = $this->revalue->handle(
                batch: $batch,
                direction: MovementDirection::In,
                newUnitCost: $this->raisedCost((string) $batch->unit_cost, $allocation->on_hand_share_paisa, (string) $batch->current_quantity),
                valueDelta: number_format($allocation->on_hand_share_paisa / 100, 2, '.', ''),
                performedBy: $ctx->actor->id,
                note: "Reversal of {$document->number}: {$ctx->reason->display()}",
                occurredAt: $ctx->dates->reversalDate,
                reason: MovementReason::PurchaseDiscount,
            );

            $allocation->update(['reversal_movement_id' => $movement->id]);
        }

        // The allocation cascades with the adjustment: the bill is owed again.
        PartyAdjustment::query()->whereKey($document->party_adjustment_id)->first()?->delete();

        return $reversal;
    }

    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model
    {
        throw new LogicException('A discount has no successor: Correct is not offered.');
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
        return SupplierDiscount::class;
    }

    /**
     * This type's own invariant: marked reversed, its journal reversed by the
     * reversal it names, no allocation of it left on the bill, and every batch
     * it lowered raised back by the same share.
     */
    public function assertInvariants(Model $original, ?Model $successor): void
    {
        /** @var SupplierDiscount $original */
        if (! $original->isReversed()) {
            throw new LogicException("{$original->number} was not marked.");
        }

        $reversal = $original->reversal_journal_id === null ? null : Journal::query()->find($original->reversal_journal_id);

        if ($reversal === null || (int) $reversal->reverses_journal_id !== (int) $original->journal_id) {
            throw new LogicException("{$original->number}'s reversal does not undo its journal.");
        }

        if ($original->party_adjustment_id !== null && PartyAdjustment::query()->whereKey($original->party_adjustment_id)->exists()) {
            throw new LogicException("{$original->number} still reduces its bill.");
        }

        $unraised = $original->allocations()->whereNotNull('stock_movement_id')->where('on_hand_share_paisa', '>', 0)->whereNull('reversal_movement_id')->exists();

        if ($unraised) {
            throw new LogicException("{$original->number} left a batch without its cost back.");
        }
    }

    public function visibleTo(Model $document, User $user): bool
    {
        return $this->policy->canView($document, $user);
    }

    /** cost + share / units on hand, four places, half up: the engine's own rounding. */
    private function raisedCost(string $unitCost, int $shareMinor, string $onHand): string
    {
        $scaled = (int) round(((float) $onHand) * 10000);

        if ($scaled <= 0) {
            return number_format((float) $unitCost, 4, '.', '');
        }

        $numerator = $shareMinor * 1_000_000;
        $delta = intdiv($numerator, $scaled) + ((($numerator % $scaled) * 2 >= $scaled) ? 1 : 0);
        $old = (int) round(((float) $unitCost) * 10000);

        return number_format(($old + $delta) / 10000, 4, '.', '');
    }
}
