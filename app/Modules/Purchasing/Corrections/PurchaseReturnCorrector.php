<?php

namespace App\Modules\Purchasing\Corrections;

use App\Models\User;
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
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Purchasing\Actions\PostPurchaseJournals;
use App\Modules\Purchasing\Actions\ReversePurchaseReturn;
use App\Modules\Purchasing\Corrections\Concerns\CorrectsPurchasingDocuments;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\PurchaseReturn;
use App\Modules\Purchasing\Models\PurchaseReturnLine;
use App\Modules\Purchasing\Models\SupplierCreditNote;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * ⭐ A purchase return's own reversal (corrections wave 1, 20 Oct 2026):
 * ReversePurchaseReturn puts the goods back onto the batches they left, at the
 * same cost, mirrors the return's journal on the date the date policy gave,
 * and gives the returned quantity back to the order lines.
 *
 * Correct is not offered: a return of the wrong goods is reversed and the
 * right return recorded from the purchase order.
 */
class PurchaseReturnCorrector implements DocumentCorrector
{
    use CorrectsPurchasingDocuments;

    public function __construct(
        private readonly PurchaseReturnActionPolicy $policy,
        private readonly ReversePurchaseReturn $reverseReturn,
        private readonly PostPurchaseJournals $posting,
    ) {}

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function find(int $documentId): ?Model
    {
        return PurchaseReturn::query()->find($documentId);
    }

    public function lockForCommit(int $documentId): Model
    {
        $return = PurchaseReturn::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();
        $this->lockJournals($return);
        $lines = $return->lines()->with('stockMovement:id,batch_id')->get();
        StockBatch::query()->whereIn('id', $lines->map(fn ($l) => $l->stockMovement?->batch_id)->filter())->lockForUpdate()->get(['id']);
        PurchaseOrderLine::query()->whereIn('id', $lines->pluck('purchase_order_line_id'))->lockForUpdate()->get(['id']);

        return $return->load('lines');
    }

    public function fingerprint(Model $document): string
    {
        /** @var PurchaseReturn $document */
        $lines = $document->lines()->with(['stockMovement:id,batch_id,quantity,unit_cost', 'stockMovement.batch:id,unit_cost,current_quantity'])->orderBy('id')->get();

        return hash('sha256', (string) json_encode([
            'r' => [$document->id, $document->purchase_order_id, $document->returned_at?->toIso8601String(), $document->reversed_at?->toIso8601String()],
            'lines' => $lines->map(fn (PurchaseReturnLine $l) => [
                (int) $l->purchase_order_line_id, (string) $l->quantity_returned, $l->stock_movement_id,
                (string) $l->stockMovement?->batch?->unit_cost,
            ])->all(),
            'credits' => SupplierCreditNote::query()->where('purchase_return_id', $document->id)->orderBy('id')->get(['id', 'reversed_at'])
                ->map(fn ($n) => [$n->id, $n->reversed_at?->toIso8601String()])->all(),
            'journals' => $this->journalTrail($document),
            'period' => $this->periodBoundary(),
        ]));
    }

    public function validateReplacement(Model $document, array $input): ReplacementData
    {
        throw ValidationException::withMessages(['replacement' => 'A return is not corrected in place. Reverse it and record the right return from the purchase order.']);
    }

    public function replacementInput(ReplacementData $replacement): array
    {
        return $replacement->input;
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var PurchaseReturn $document */
        $document->loadMissing(['lines.purchaseOrderLine.product:id,name', 'lines.stockMovement.batch:id,batch_number']);
        $on = Tk::date($dates->reversalDate);
        $lines = [];

        foreach ($document->lines as $line) {
            $product = $line->purchaseOrderLine?->product?->name ?? 'The goods';
            $batch = $line->stockMovement?->batch?->batch_number;
            $lines[] = new EffectLine(
                $batch === null
                    ? "{$product}: ".rtrim(rtrim((string) $line->quantity_returned, '0'), '.')." goes back on the purchase order on {$on}."
                    : "{$product}: ".rtrim(rtrim((string) $line->stockMovement->quantity, '0'), '.')." goes back into batch {$batch} at the same cost on {$on}.",
                null,
                null,
                'stock',
            );
        }

        $value = $this->totalMinor($document);

        if ($value > 0) {
            $lines[] = new EffectLine(Tk::of($value).' goes back into stock, and Goods Received Not Invoiced owes the supplier that much again.', 'Stock on Hand', $value, 'ledger');
        }

        return $lines;
    }

    public function describeStays(Model $document, DocumentAction $action): array
    {
        $number = $this->policy->numberOf($document);

        return ["{$number} stays, marked Reversed.", 'The reversal gets its own JV number.', 'The goods can be billed again.'];
    }

    public function originalDate(Model $document): Carbon
    {
        return $this->policy->documentDate($document);
    }

    public function totalMinor(Model $document): int
    {
        return $this->policy->totalMinor($document);
    }

    public function makerAndLastEditorIds(Model $document): array
    {
        return $this->makerAndLastEditor($document, 'returned_by');
    }

    public function journalOf(Model $document): ?Journal
    {
        /** @var PurchaseReturn $document */
        return $this->posting->existingJournal($document);
    }

    public function reverse(Model $document, CommitContext $ctx): ?Journal
    {
        /** @var PurchaseReturn $document */
        try {
            $reversed = $this->reverseReturn->handle($document, $ctx->actor, $ctx->reason->display(), $ctx->dates->reversalDate, $ctx->pass);
        } catch (PeriodClosedException $e) {
            throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
        }

        return $reversed->reversal_journal_id === null ? null : Journal::query()->find($reversed->reversal_journal_id);
    }

    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model
    {
        throw new LogicException('A return has no successor: Correct is not offered.');
    }

    public function journalOfSuccessor(Model $successor): ?Journal
    {
        return null;
    }

    public function markOriginal(Model $document, ?Journal $reversal, CommitContext $ctx): void
    {
        // ReversePurchaseReturn wrote the Reversible columns with the reason.
    }

    public function successorClass(): string
    {
        return PurchaseReturn::class;
    }

    /**
     * This type's own invariant: marked; every stock movement it wrote has a
     * restoring movement of the same quantity on the same batch at the same
     * cost; its journal, if any, is mirrored by the one it names.
     */
    public function assertInvariants(Model $original, ?Model $successor): void
    {
        /** @var PurchaseReturn $original */
        if (! $original->isReversed()) {
            throw new LogicException("{$original->label()} was not marked.");
        }

        foreach ($original->lines()->with('stockMovement')->get() as $line) {
            $movement = $line->stockMovement;

            if ($movement === null) {
                continue;
            }

            $restored = $movement->newQuery()->where('reverses_movement_id', $movement->id)->get(['batch_id', 'quantity', 'unit_cost']);
            $same = $restored->filter(fn ($m) => (int) $m->batch_id === (int) $movement->batch_id
                && (string) $m->quantity === (string) $movement->quantity
                && (string) $m->unit_cost === (string) $movement->unit_cost);

            if ($same->count() !== 1) {
                throw new LogicException("{$original->label()} left a batch without its goods back.");
            }
        }

        $journal = $this->journalOf($original);

        if ($journal !== null && (int) $original->reversal_journal_id !== (int) Journal::query()->where('reverses_journal_id', $journal->id)->value('id')) {
            throw new LogicException("{$original->label()}'s reversal does not undo its journal.");
        }
    }

    public function visibleTo(Model $document, User $user): bool
    {
        return $this->policy->canView($document, $user);
    }
}
