<?php

namespace App\Modules\Sales\Corrections;

use App\Models\User;
use App\Modules\Accounting\Exceptions\JournalException;
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
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Sales\Actions\Corrections\ReverseSampleIssue;
use App\Modules\Sales\Models\SampleIssue;
use App\Modules\Sales\Support\SampleHeads;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/**
 * ⭐ Samples (25 Oct 2026): a given sample reversed through the engine
 * (preview, reason, original date, closed month for the Owner, Tk 20,000
 * second person on cost plus delivery).
 *
 *   reverse()       ReverseSampleIssue: goods back onto their batches, the
 *                   journal mirrored, the feedback follow-up cancelled, the
 *                   sample marked with the reason
 *   markOriginal()  done inside reverse()
 *
 * Correct is not offered: a wrong sample is reversed and given again.
 */
class SampleIssueCorrector implements CountsClosedPeriodJournals, DocumentCorrector
{
    public function __construct(
        private readonly SampleIssueActionPolicy $policy,
        private readonly ReverseSampleIssue $reverseSample,
    ) {}

    public function closedPeriodJournals(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var SampleIssue $document */
        $count = $document->journal_id === null ? 0 : 1;

        return [$count, $count];
    }

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function find(int $documentId): ?Model
    {
        return SampleIssue::query()->find($documentId);
    }

    public function lockForCommit(int $documentId): Model
    {
        $issue = SampleIssue::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();
        $movements = ReverseSampleIssue::movements($issue);
        StockBatch::query()->whereIn('id', $movements->pluck('batch_id'))->lockForUpdate()->get(['id']);
        Journal::query()->where('source_type', $issue->getMorphClass())->where('source_id', $issue->id)->lockForUpdate()->get(['id']);

        return $issue;
    }

    public function fingerprint(Model $document): string
    {
        /** @var SampleIssue $document */
        $movements = ReverseSampleIssue::movements($document);
        $lock = AccountingPeriod::current();

        return hash('sha256', (string) json_encode([
            's' => [$document->id, $document->status, $document->given_on?->toDateString(), (int) $document->cost_total_paisa, (string) $document->delivery_charge, $document->journal_id, $document->reversed_at?->toIso8601String()],
            'batches' => StockBatch::query()->whereIn('id', $movements->pluck('batch_id'))->orderBy('id')->get(['id', 'current_quantity', 'unit_cost'])
                ->map(fn ($b) => [$b->id, (string) $b->current_quantity, (string) $b->unit_cost])->all(),
            'restored' => StockMovement::query()->whereIn('reverses_movement_id', $movements->pluck('id'))->count(),
            'journals' => Journal::query()->where('source_type', $document->getMorphClass())->where('source_id', $document->id)
                ->orderBy('id')->get(['id', 'reverses_journal_id'])->map(fn ($j) => [$j->id, $j->reverses_journal_id])->all(),
            'period' => [$lock?->closed_through?->toDateString(), (bool) $lock?->is_unlocked],
        ]));
    }

    public function validateReplacement(Model $document, array $input): ReplacementData
    {
        throw ValidationException::withMessages(['replacement' => 'A sample is not corrected in place. Reverse it and give it again.']);
    }

    public function replacementInput(ReplacementData $replacement): array
    {
        return $replacement->input;
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var SampleIssue $document */
        $on = Tk::date($dates->reversalDate);
        $warehouse = $document->warehouse?->name ?? 'the warehouse';
        $lines = [];

        foreach (ReverseSampleIssue::movements($document)->load('batch:id,batch_number') as $movement) {
            $quantity = rtrim(rtrim((string) $movement->quantity, '0'), '.');
            $lines[] = new EffectLine("{$movement->product?->name}: {$quantity} goes back into {$warehouse}, onto batch {$movement->batch?->batch_number}, at the cost it left at, on {$on}.", null, null, 'stock');
        }

        if ($document->journal_id !== null) {
            $cost = (int) $document->cost_total_paisa;

            if ($cost > 0) {
                $head = SampleHeads::goods($this->policy->documentDate($document))->name;
                $lines[] = new EffectLine("{$head} comes down by ".Tk::of($cost).' and Stock on Hand goes back up by '.Tk::of($cost).", on {$on}.", $head, -$cost, 'ledger');
            }

            $delivery = Money::ofTaka((string) $document->delivery_charge)->minor;

            if ($delivery > 0) {
                $to = $document->courierMoneyAccount?->name ?? $document->deliveryMoneyAccount?->name ?? 'the account';
                $lines[] = new EffectLine('The delivery charge of '.Tk::of($delivery).($document->courier_money_account_id !== null ? " is no longer owed to {$to}" : " goes back to {$to}").", on {$on}.", 'Sample Delivery', -$delivery, 'ledger');
            }
        }

        if ($document->crm_task_id !== null) {
            $lines[] = new EffectLine('Its feedback follow-up is cancelled, if still open.', null, null, 'allocation');
        }

        return $lines;
    }

    public function describeStays(Model $document, DocumentAction $action): array
    {
        /** @var SampleIssue $document */
        return ["{$document->number} stays, marked Reversed, with its reason.", 'Its journal stays; a mirror journal undoes it.'];
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
        /** @var SampleIssue $document */
        return array_values(array_unique(array_filter([(int) $document->given_by, (int) $document->posted_by])));
    }

    public function journalOf(Model $document): ?Journal
    {
        /** @var SampleIssue $document */
        return $document->journal_id === null ? null : Journal::query()->find($document->journal_id);
    }

    public function reverse(Model $document, CommitContext $ctx): ?Journal
    {
        /** @var SampleIssue $document */
        try {
            return $this->reverseSample->handle($document, $ctx->actor, $ctx->reason->display(), $ctx->dates->reversalDate, $ctx->pass);
        } catch (PeriodClosedException|DomainException|InvalidArgumentException|JournalException $e) {
            throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
        }
    }

    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model
    {
        throw new LogicException('A sample has no successor: Correct is not offered.');
    }

    public function journalOfSuccessor(Model $successor): ?Journal
    {
        return null;
    }

    public function markOriginal(Model $document, ?Journal $reversal, CommitContext $ctx): void
    {
        // ReverseSampleIssue wrote the reversal columns with the reason.
    }

    public function successorClass(): string
    {
        return SampleIssue::class;
    }

    /**
     * Marked; every movement put back whole on its own batch; the goods came
     * back worth exactly what they left at; the journal, when there was one,
     * mirrored.
     */
    public function assertInvariants(Model $original, ?Model $successor): void
    {
        /** @var SampleIssue $original */
        $fresh = $original->fresh();

        if (! $fresh->isReversed()) {
            throw new LogicException("{$original->number} was not marked.");
        }

        $value = Money::zero();

        foreach (ReverseSampleIssue::movements($fresh) as $out) {
            $back = StockMovement::query()->where('reverses_movement_id', $out->id)->get();

            if ($back->contains(fn ($m) => (int) $m->batch_id !== (int) $out->batch_id) || bccomp((string) $back->sum('quantity'), (string) $out->quantity, 4) !== 0) {
                throw new LogicException("{$original->number} left goods out of the warehouse.");
            }

            foreach ($back as $movement) {
                $value = $value->plus(Money::ofProduct((string) $movement->quantity, (string) $movement->unit_cost));
            }
        }

        if ($value->minor !== (int) $fresh->cost_total_paisa) {
            throw new LogicException("{$original->number} put goods back at another cost than they left at.");
        }

        if ($fresh->journal_id !== null && ($fresh->reversal_journal_id === null || ! Journal::query()->findOrFail($fresh->journal_id)->hasBeenReversed())) {
            throw new LogicException("{$original->number}'s journal was not reversed.");
        }
    }

    public function visibleTo(Model $document, User $user): bool
    {
        return $this->policy->canView($document, $user);
    }
}
