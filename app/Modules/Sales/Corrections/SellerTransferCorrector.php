<?php

namespace App\Modules\Sales\Corrections;

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
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Sales\Actions\Sellers\ReverseSellerTransfer;
use App\Modules\Sales\Models\SellerTransfer;
use App\Modules\Sales\Models\SellerTransferLine;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/**
 * ⭐ Initial plan B21: a retail seller's hand-over or return reversed through
 * the engine (preview, reason, original date, closed month for the Owner).
 *
 *   reverse()       ReverseSellerTransfer: the goods back onto the batches
 *                   they left, at the same cost; no journal (none was posted)
 *   markOriginal()  done inside reverse() with the reason
 *
 * Correct is not offered: a wrong sheet is reversed and written again.
 */
class SellerTransferCorrector implements DocumentCorrector
{
    public function __construct(
        private readonly SellerTransferActionPolicy $policy,
        private readonly ReverseSellerTransfer $reverseSheet,
    ) {}

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function find(int $documentId): ?Model
    {
        return SellerTransfer::query()->find($documentId);
    }

    public function lockForCommit(int $documentId): Model
    {
        $sheet = SellerTransfer::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();
        $groups = $sheet->lines()->with('stockTransfer:id,transfer_group_id')->get()->map(fn (SellerTransferLine $l) => $l->stockTransfer?->transfer_group_id)->filter();
        StockBatch::query()->whereIn('id', StockMovement::query()->whereIn('transfer_group_id', $groups)->select('batch_id'))->lockForUpdate()->get(['id']);

        return $sheet->load('lines');
    }

    public function fingerprint(Model $document): string
    {
        /** @var SellerTransfer $document */
        $lock = AccountingPeriod::current();
        $groups = $document->lines()->with('stockTransfer:id,transfer_group_id')->orderBy('id')->get()->map(fn (SellerTransferLine $l) => $l->stockTransfer?->transfer_group_id)->filter()->values();

        return hash('sha256', (string) json_encode([
            's' => [$document->id, $document->number, $document->kind, $document->moved_on?->toDateString(), (string) $document->cost_value, $document->reversed_at?->toIso8601String()],
            'batches' => StockBatch::query()->whereIn('id', StockMovement::query()->whereIn('transfer_group_id', $groups)->select('batch_id'))
                ->orderBy('id')->get(['id', 'current_quantity'])->map(fn ($b) => [$b->id, (string) $b->current_quantity])->all(),
            'period' => [$lock?->closed_through?->toDateString(), (bool) $lock?->is_unlocked],
        ]));
    }

    public function validateReplacement(Model $document, array $input): ReplacementData
    {
        throw ValidationException::withMessages(['replacement' => 'A seller sheet is not corrected in place. Reverse it and write it again.']);
    }

    public function replacementInput(ReplacementData $replacement): array
    {
        return $replacement->input;
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var SellerTransfer $document */
        $document->loadMissing(['lines.product:id,name', 'sellerWarehouse:id,name', 'otherWarehouse:id,name']);
        $on = Tk::date($dates->reversalDate);
        [$from, $to] = $document->isHandover()
            ? [$document->sellerWarehouse?->name, $document->otherWarehouse?->name]
            : [$document->otherWarehouse?->name, $document->sellerWarehouse?->name];
        $lines = [];

        foreach ($document->lines as $line) {
            $quantity = rtrim(rtrim((string) $line->quantity, '0'), '.');
            $lines[] = new EffectLine("{$line->product?->name}: {$quantity} goes from {$from} back to {$to}, onto the batches it left, at the same cost, on {$on}.", null, null, 'stock');
        }

        return $lines;
    }

    public function describeStays(Model $document, DocumentAction $action): array
    {
        return ["{$this->policy->numberOf($document)} stays, marked Reversed, with its reason.", 'No ledger entry: moving goods to or from a seller posts none.'];
    }

    public function originalDate(Model $document): Carbon
    {
        /** @var SellerTransfer $document */
        return $this->policy->documentDate($document);
    }

    public function totalMinor(Model $document): int
    {
        return $this->policy->totalMinor($document);
    }

    public function makerAndLastEditorIds(Model $document): array
    {
        return [(int) $document->created_by];
    }

    public function journalOf(Model $document): ?Journal
    {
        return null;
    }

    public function reverse(Model $document, CommitContext $ctx): ?Journal
    {
        /** @var SellerTransfer $document */
        try {
            $this->reverseSheet->handle($document, $ctx->actor, $ctx->reason->display(), $ctx->dates->reversalDate, $ctx->pass);
        } catch (PeriodClosedException|DomainException|InvalidArgumentException $e) {
            throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
        }

        return null;
    }

    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model
    {
        throw new LogicException('A seller sheet has no successor: Correct is not offered.');
    }

    public function journalOfSuccessor(Model $successor): ?Journal
    {
        return null;
    }

    public function markOriginal(Model $document, ?Journal $reversal, CommitContext $ctx): void
    {
        // ReverseSellerTransfer wrote the Reversible columns with the reason.
    }

    public function successorClass(): string
    {
        return SellerTransfer::class;
    }

    /**
     * Marked, and every batch the sheet filled is back to what it held before
     * (its arriving goods taken out again).
     */
    public function assertInvariants(Model $original, ?Model $successor): void
    {
        /** @var SellerTransfer $original */
        if (! $original->fresh()->isReversed()) {
            throw new LogicException("{$original->label()} was not marked.");
        }

        foreach ($original->lines()->with('stockTransfer:id,transfer_group_id')->get() as $line) {
            $group = $line->stockTransfer?->transfer_group_id;

            foreach (StockMovement::query()->where('transfer_group_id', $group)->where('direction', 'in')->whereNull('reverses_movement_id')->get() as $in) {
                $undone = (float) StockMovement::query()->where('reverses_movement_id', $in->id)->sum('quantity');

                if (abs($undone - (float) $in->quantity) > 0.00005) {
                    throw new LogicException("{$original->label()} left goods behind at the destination.");
                }
            }
        }
    }

    public function visibleTo(Model $document, User $user): bool
    {
        return $this->policy->canView($document, $user);
    }
}
