<?php

namespace App\Modules\Accounting\Corrections;

use App\Models\User;
use App\Modules\Accounting\Actions\ReverseOwnerWithdrawal;
use App\Modules\Accounting\Exceptions\JournalException;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\OwnerWithdrawal;
use App\Modules\Accounting\Models\OwnerWithdrawalLine;
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
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockValue;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/**
 * ⭐ Initial plan B24 (Joy's Q5 = A, 25 Oct 2026): a posted owner withdrawal
 * reversed through the engine (preview, reason, original date, closed month
 * for the Owner, Tk 20,000 second person on the cost total).
 *
 *   reverse()       ReverseOwnerWithdrawal: the goods back onto the batches
 *                   they left, the drawings journal mirrored
 *   markOriginal()  done inside reverse() with the reason and the journal
 *
 * Correct is not offered: a wrong withdrawal is reversed and written again.
 */
class OwnerWithdrawalCorrector implements DocumentCorrector
{
    public function __construct(
        private readonly OwnerWithdrawalActionPolicy $policy,
        private readonly ReverseOwnerWithdrawal $reverseWithdrawal,
    ) {}

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function find(int $documentId): ?Model
    {
        return OwnerWithdrawal::query()->find($documentId);
    }

    public function lockForCommit(int $documentId): Model
    {
        $withdrawal = OwnerWithdrawal::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();
        StockBatch::query()->whereIn('id', $withdrawal->lines()->whereNotNull('stock_batch_id')->select('stock_batch_id'))->lockForUpdate()->get(['id']);

        return $withdrawal;
    }

    public function fingerprint(Model $document): string
    {
        /** @var OwnerWithdrawal $document */
        $lock = AccountingPeriod::current();
        $movements = $document->lines()->whereNotNull('stock_movement_id')->pluck('stock_movement_id');

        return hash('sha256', (string) json_encode([
            'w' => [$document->id, $document->status->value, $document->withdrawn_on?->toDateString(), (int) $document->cost_total_paisa, $document->journal_id, $document->reversed_at?->toIso8601String()],
            'batches' => StockBatch::query()->whereIn('id', $document->lines()->select('stock_batch_id'))->orderBy('id')->get(['id', 'current_quantity', 'unit_cost'])
                ->map(fn ($b) => [$b->id, (string) $b->current_quantity, (string) $b->unit_cost])->all(),
            'restored' => StockMovement::query()->whereIn('reverses_movement_id', $movements)->count(),
            'period' => [$lock?->closed_through?->toDateString(), (bool) $lock?->is_unlocked],
        ]));
    }

    public function validateReplacement(Model $document, array $input): ReplacementData
    {
        throw ValidationException::withMessages(['replacement' => 'An owner withdrawal is not corrected in place. Reverse it and write it again.']);
    }

    public function replacementInput(ReplacementData $replacement): array
    {
        return $replacement->input;
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var OwnerWithdrawal $document */
        $on = Tk::date($dates->reversalDate);
        $warehouse = $document->warehouse?->name ?? 'the warehouse';
        $lines = [];

        foreach ($document->lines()->with(['product:id,name', 'batch:id,batch_number'])->get() as $line) {
            /** @var OwnerWithdrawalLine $line */
            $quantity = rtrim(rtrim((string) $line->quantity, '0'), '.');
            $lines[] = new EffectLine("{$line->product?->name}: {$quantity} goes back into {$warehouse}, onto batch {$line->batch?->batch_number}, at the cost it left at (".Tk::of((int) $line->cost_paisa)."), on {$on}.", null, null, 'stock');
        }

        $total = Tk::of((int) $document->cost_total_paisa);
        $lines[] = new EffectLine("Owner's Drawings comes down by {$total} and Stock on Hand goes back up by {$total}, on {$on}.", "Owner's Drawings", -(int) $document->cost_total_paisa, 'ledger');

        return $lines;
    }

    public function describeStays(Model $document, DocumentAction $action): array
    {
        /** @var OwnerWithdrawal $document */
        return ["{$document->number} stays, marked Reversed, with its reason.", 'Its journal stays; a mirror journal undoes it.'];
    }

    public function originalDate(Model $document): Carbon
    {
        /** @var OwnerWithdrawal $document */
        return $this->policy->documentDate($document);
    }

    public function totalMinor(Model $document): int
    {
        return $this->policy->totalMinor($document);
    }

    public function makerAndLastEditorIds(Model $document): array
    {
        /** @var OwnerWithdrawal $document */
        return array_values(array_unique(array_filter([(int) $document->created_by, (int) $document->posted_by])));
    }

    public function journalOf(Model $document): ?Journal
    {
        /** @var OwnerWithdrawal $document */
        return $document->journal_id === null ? null : Journal::query()->find($document->journal_id);
    }

    public function reverse(Model $document, CommitContext $ctx): ?Journal
    {
        /** @var OwnerWithdrawal $document */
        try {
            return $this->reverseWithdrawal->handle($document, $ctx->actor, $ctx->reason->display(), $ctx->dates->reversalDate, $ctx->pass);
        } catch (PeriodClosedException|DomainException|InvalidArgumentException|JournalException $e) {
            throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
        }
    }

    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model
    {
        throw new LogicException('An owner withdrawal has no successor: Correct is not offered.');
    }

    public function journalOfSuccessor(Model $successor): ?Journal
    {
        return null;
    }

    public function markOriginal(Model $document, ?Journal $reversal, CommitContext $ctx): void
    {
        // ReverseOwnerWithdrawal wrote the reversal columns with the reason.
    }

    public function successorClass(): string
    {
        return OwnerWithdrawal::class;
    }

    /**
     * Marked; every line's movement put back whole; and the stock that came
     * back is worth exactly what the mirror journal gave Stock on Hand.
     */
    public function assertInvariants(Model $original, ?Model $successor): void
    {
        /** @var OwnerWithdrawal $original */
        $fresh = $original->fresh();

        if (! $fresh->isReversed()) {
            throw new LogicException("{$original->number} was not marked.");
        }

        $value = Money::zero();

        foreach ($fresh->lines as $line) {
            $back = StockMovement::query()->where('reverses_movement_id', $line->stock_movement_id)->get();

            if (bccomp((string) $back->sum('quantity'), (string) $line->quantity, 4) !== 0) {
                throw new LogicException("{$original->number} left goods out of the warehouse.");
            }

            foreach ($back as $movement) {
                $value = $value->plus(StockValue::of($movement)->absolute());
            }
        }

        if ($value->minor !== (int) $fresh->cost_total_paisa) {
            throw new LogicException("{$original->number} put goods back at another cost than they left at.");
        }
    }

    public function visibleTo(Model $document, User $user): bool
    {
        return $this->policy->canView($document, $user);
    }
}
