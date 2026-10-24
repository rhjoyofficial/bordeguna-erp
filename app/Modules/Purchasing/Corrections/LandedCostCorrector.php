<?php

namespace App\Modules\Purchasing\Corrections;

use App\Models\User;
use App\Modules\Accounting\Exceptions\JournalException;
use App\Modules\Accounting\Models\Journal;
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
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Support\SoldStock;
use App\Modules\Purchasing\Actions\ReverseLandedCost;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\LandedCostAllocation;
use App\Modules\Purchasing\Models\SupplierBill;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * ⭐ Initial plan B24 (Joy's Q5 = A, 25 Oct 2026): a posted landed cost
 * reversed through the engine (preview, reason, original date, closed month
 * for the Owner, Tk 20,000 second person on its total, the Tk 10,000 rule on
 * the part already sold).
 *
 *   reverse()       ReverseLandedCost: the cost off the batches still on
 *                   hand, the sold part to cost of sales today, the journal
 *                   mirrored, the bills it raised reversed
 *   markOriginal()  done inside reverse() with the reason and the journal
 *
 * Correct is not offered: a wrong landed cost is reversed and entered again.
 */
class LandedCostCorrector implements DocumentCorrector
{
    public function __construct(
        private readonly LandedCostActionPolicy $policy,
        private readonly ReverseLandedCost $reverseLandedCost,
    ) {}

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function find(int $documentId): ?Model
    {
        return LandedCost::query()->find($documentId);
    }

    public function lockForCommit(int $documentId): Model
    {
        $landedCost = LandedCost::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();
        StockBatch::query()->whereIn('id', $this->batchIds($landedCost))->lockForUpdate()->get(['id']);
        SupplierBill::query()->where('landed_cost_id', $landedCost->id)->lockForUpdate()->get(['id']);

        return $landedCost;
    }

    public function fingerprint(Model $document): string
    {
        /** @var LandedCost $document */
        $lock = AccountingPeriod::current();

        return hash('sha256', (string) json_encode([
            'l' => [$document->id, $document->status->value, $document->incurred_on?->toDateString(), (int) $document->total_paisa, $document->journal_id, $document->reversed_at?->toIso8601String()],
            'batches' => StockBatch::query()->whereIn('id', $this->batchIds($document))->orderBy('id')->get(['id', 'current_quantity', 'unit_cost'])
                ->map(fn ($b) => [$b->id, (string) $b->current_quantity, (string) $b->unit_cost])->all(),
            'bills' => $document->bills()->orderBy('id')->get()->map(fn (SupplierBill $b) => [$b->id, $b->reversed_at?->toIso8601String(), $b->paidTotal()->minor, $b->creditedTotal()->minor, $b->adjustedTotal()->minor])->all(),
            'period' => [$lock?->closed_through?->toDateString(), (bool) $lock?->is_unlocked],
        ]));
    }

    public function validateReplacement(Model $document, array $input): ReplacementData
    {
        throw ValidationException::withMessages(['replacement' => 'A landed cost is not corrected in place. Reverse it and enter it again.']);
    }

    public function replacementInput(ReplacementData $replacement): array
    {
        return $replacement->input;
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var LandedCost $document */
        $on = Tk::date($dates->reversalDate);
        $plan = ReverseLandedCost::plan($document);
        $lines = [];

        foreach ($plan['batches'] as $entry) {
            $name = $entry['batch']->batch_number ?? "#{$entry['batch']->id}";

            if ($entry['remaining'] !== 0) {
                $lines[] = new EffectLine('Batch '.$name.': '.Tk::of(abs($entry['remaining']))." comes off the goods still on hand, back to their cost before this landed cost, on {$on}.", null, null, 'stock');
            }
        }

        $lines[] = new EffectLine("Its journal is mirrored on {$on}: the cost comes off Stock on Hand and the expense heads, and what it owed goes off Accounts Payable or back to the money account.", null, -(int) $document->total_paisa, 'ledger');

        if ($plan['sold'] !== 0) {
            $product = $plan['batches'][0]['batch']->product()->with('unitOfMeasure:id,abbreviation')->first();
            $lines[] = new EffectLine(SoldStock::effectSentence($plan['sold'], $plan['used'], $product?->unitOfMeasure?->abbreviation, $product?->name), 'Stock Cost Corrections on Goods Sold', -$plan['sold'], 'ledger');
        }

        foreach ($document->bills()->whereNull('reversed_at')->get() as $bill) {
            $lines[] = new EffectLine("Bill {$bill->bill_number} it raised (".Tk::of(Money::ofTaka((string) $bill->total)->minor).') is reversed with it.', null, null, 'ledger');
        }

        return $lines;
    }

    public function describeStays(Model $document, DocumentAction $action): array
    {
        /** @var LandedCost $document */
        return ["{$document->number} stays, marked Reversed, with its reason.", 'Its journal stays; a mirror journal undoes it. Sales already made keep the cost they were made at.'];
    }

    public function originalDate(Model $document): Carbon
    {
        /** @var LandedCost $document */
        return $this->policy->documentDate($document);
    }

    public function totalMinor(Model $document): int
    {
        return $this->policy->totalMinor($document);
    }

    public function makerAndLastEditorIds(Model $document): array
    {
        /** @var LandedCost $document */
        return array_values(array_unique(array_filter([(int) $document->created_by, (int) $document->posted_by])));
    }

    public function journalOf(Model $document): ?Journal
    {
        /** @var LandedCost $document */
        return $document->journal_id === null ? null : Journal::query()->find($document->journal_id);
    }

    public function reverse(Model $document, CommitContext $ctx): ?Journal
    {
        /** @var LandedCost $document */
        try {
            return $this->reverseLandedCost->handle($document, $ctx->actor, $ctx->reason->display(), $ctx->dates->reversalDate, $ctx->pass, $ctx->approver);
        } catch (PeriodClosedException|DomainException|InvalidArgumentException|JournalException|RuntimeException $e) {
            throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
        }
    }

    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model
    {
        throw new LogicException('A landed cost has no successor: Correct is not offered.');
    }

    public function journalOfSuccessor(Model $successor): ?Journal
    {
        return null;
    }

    public function markOriginal(Model $document, ?Journal $reversal, CommitContext $ctx): void
    {
        // ReverseLandedCost wrote the reversal columns with the reason.
    }

    public function successorClass(): string
    {
        return LandedCost::class;
    }

    /** Marked, and no bill it raised left standing. */
    public function assertInvariants(Model $original, ?Model $successor): void
    {
        /** @var LandedCost $original */
        $fresh = $original->fresh();

        if (! $fresh->isReversed()) {
            throw new LogicException("{$original->number} was not marked.");
        }

        if ($fresh->bills()->whereNull('reversed_at')->exists()) {
            throw new LogicException("{$original->number} left a bill standing.");
        }
    }

    public function visibleTo(Model $document, User $user): bool
    {
        return $this->policy->canView($document, $user);
    }

    /** @return list<int> */
    private function batchIds(LandedCost $landedCost): array
    {
        return LandedCostAllocation::query()->whereIn('landed_cost_line_id', $landedCost->lines()->select('id'))->whereNotNull('stock_batch_id')->pluck('stock_batch_id')->unique()->values()->all();
    }
}
