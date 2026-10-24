<?php

namespace App\Modules\Inventory\Corrections;

use App\Models\User;
use App\Modules\Accounting\Models\Journal;
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
use App\Modules\Inventory\Actions\ReverseStockTransfer;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * ⭐ Initial plan B24 (Joy's Q5 = A, 25 Oct 2026): a received stock transfer
 * reversed through the engine (preview, reason, original date, closed month
 * for the Owner, Tk 20,000 second person on the goods' value).
 *
 *   reverse()       ReverseStockTransfer: the goods out of the destination
 *                   batches and back onto the source batches they left, at
 *                   the same cost; no journal (none was posted)
 *   markOriginal()  done inside reverse() with the reason
 *
 * Correct is not offered: a wrong transfer is reversed and sent again.
 */
class StockTransferCorrector implements CountsClosedPeriodJournals, DocumentCorrector
{
    public function __construct(
        private readonly StockTransferActionPolicy $policy,
        private readonly ReverseStockTransfer $reverseTransfer,
    ) {}

    /**
     * A transfer posts no journal, so the Owner's closed-month pass covers
     * only its stock movements on those dates.
     */
    public function closedPeriodJournals(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        return [0, 0];
    }

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function find(int $documentId): ?Model
    {
        return StockTransfer::query()->find($documentId);
    }

    public function lockForCommit(int $documentId): Model
    {
        $transfer = StockTransfer::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();
        StockBatch::query()->whereIn('id', StockMovement::query()->where('transfer_group_id', $transfer->transfer_group_id)->select('batch_id'))->lockForUpdate()->get(['id']);

        return $transfer;
    }

    public function fingerprint(Model $document): string
    {
        /** @var StockTransfer $document */
        $lock = AccountingPeriod::current();

        return hash('sha256', (string) json_encode([
            't' => [$document->id, $document->status?->value, (string) $document->quantity, $document->received_at?->toIso8601String(), $document->reversed_at?->toIso8601String()],
            'batches' => StockBatch::query()->whereIn('id', StockMovement::query()->where('transfer_group_id', $document->transfer_group_id)->select('batch_id'))
                ->orderBy('id')->get(['id', 'current_quantity'])->map(fn ($b) => [$b->id, (string) $b->current_quantity])->all(),
            'period' => [$lock?->closed_through?->toDateString(), (bool) $lock?->is_unlocked],
        ]));
    }

    public function validateReplacement(Model $document, array $input): ReplacementData
    {
        throw ValidationException::withMessages(['replacement' => 'A stock transfer is not corrected in place. Reverse it and send it again.']);
    }

    public function replacementInput(ReplacementData $replacement): array
    {
        return $replacement->input;
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var StockTransfer $document */
        $document->loadMissing(['product:id,name', 'sourceWarehouse:id,name', 'targetWarehouse:id,name']);
        $quantity = rtrim(rtrim((string) $document->quantity, '0'), '.');

        return [new EffectLine(
            "{$document->product?->name}: {$quantity} goes from {$document->targetWarehouse?->name} back to {$document->sourceWarehouse?->name}, onto the batches it left, at the same cost (".Tk::of($this->totalMinor($document)).'), on '.Tk::date($dates->reversalDate).'.',
            null,
            null,
            'stock',
        )];
    }

    public function describeStays(Model $document, DocumentAction $action): array
    {
        return ["{$this->policy->numberOf($document)} stays, marked Reversed, with its reason.", 'No ledger entry: moving goods between warehouses posts none.'];
    }

    public function originalDate(Model $document): Carbon
    {
        /** @var StockTransfer $document */
        return $this->policy->documentDate($document);
    }

    public function totalMinor(Model $document): int
    {
        /** @var StockTransfer $document */
        return $this->policy->totalMinor($document);
    }

    public function makerAndLastEditorIds(Model $document): array
    {
        /** @var StockTransfer $document */
        return array_values(array_unique(array_filter([(int) $document->dispatched_by, (int) $document->received_by])));
    }

    public function journalOf(Model $document): ?Journal
    {
        return null;
    }

    public function reverse(Model $document, CommitContext $ctx): ?Journal
    {
        /** @var StockTransfer $document */
        try {
            $this->reverseTransfer->handle($document, $ctx->actor, $ctx->reason->display(), $ctx->dates->reversalDate, $ctx->pass);
        } catch (PeriodClosedException|DomainException|InvalidArgumentException|RuntimeException $e) {
            throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
        }

        return null;
    }

    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model
    {
        throw new LogicException('A stock transfer has no successor: Correct is not offered.');
    }

    public function journalOfSuccessor(Model $successor): ?Journal
    {
        return null;
    }

    public function markOriginal(Model $document, ?Journal $reversal, CommitContext $ctx): void
    {
        // ReverseStockTransfer wrote the reversal columns with the reason.
    }

    public function successorClass(): string
    {
        return StockTransfer::class;
    }

    /**
     * Marked, every arriving leg taken back whole, every departing leg put
     * back whole: the goods are where they were before the transfer.
     */
    public function assertInvariants(Model $original, ?Model $successor): void
    {
        /** @var StockTransfer $original */
        if (! $original->fresh()->isReversed()) {
            throw new LogicException("Transfer #{$original->id} was not marked.");
        }

        [$in, $out] = ReverseStockTransfer::legs($original);

        foreach ($in->concat($out) as $leg) {
            $undone = (float) StockMovement::query()->where('reverses_movement_id', $leg->id)->sum('quantity');

            if (abs($undone - (float) $leg->quantity) > 0.00005) {
                throw new LogicException("Transfer #{$original->id} left goods out of place.");
            }
        }
    }

    public function visibleTo(Model $document, User $user): bool
    {
        return $this->policy->canView($document, $user);
    }
}
