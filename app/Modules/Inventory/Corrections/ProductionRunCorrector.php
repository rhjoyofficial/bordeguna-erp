<?php

namespace App\Modules\Inventory\Corrections;

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
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\PostProductionRunJournal;
use App\Modules\Inventory\Actions\RecordProductionRun;
use App\Modules\Inventory\Actions\ReverseProductionRun;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\ProductionRunStatus;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductionRun;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\ProductionRunForm;
use App\Modules\Inventory\Support\StockValue;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * ⭐ Corrections wave 1, B4 (20 Oct 2026): a confirmed production run's own
 * Reverse and Correct (FINAL-PLAN 3.3).
 *
 *   reverse()          ReverseProductionRun, on the run's own date and time:
 *                      every consumed portion back onto the exact batch it
 *                      came from at its frozen cost, the output out of the
 *                      batch it made, and the mirror of its journal when it
 *                      posted one (most runs post none)
 *   createSuccessor()  RecordProductionRun with the corrected form: the same
 *                      checks as a new run (prepare()), a new run number,
 *                      drafted and confirmed in the same transaction, so the
 *                      inputs it needs come from FIFO after the wrong run gave
 *                      its own back
 *   markOriginal()     reversed_on, the reason and the reversal journal on top
 *                      of the status ReverseProductionRun already set
 *
 * ⚠ A shortfall, a closed date or a missing mapping in the successor rolls the
 * whole thing back with its sentence: the wrong run stays as it was.
 */
class ProductionRunCorrector implements DocumentCorrector
{
    public function __construct(
        private readonly ProductionRunActionPolicy $policy,
        private readonly ReverseProductionRun $reverseRun,
        private readonly RecordProductionRun $recordRun,
        private readonly PostProductionRunJournal $posting,
    ) {}

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function find(int $documentId): ?Model
    {
        return ProductionRun::query()->find($documentId);
    }

    public function lockForCommit(int $documentId): Model
    {
        $run = ProductionRun::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();

        Journal::query()->where('source_type', $run->getMorphClass())->where('source_id', $run->getKey())->lockForUpdate()->get(['id']);
        StockBatch::query()->whereIn('id', $run->movements()->pluck('batch_id'))->lockForUpdate()->get(['id']);

        return $run->load(['lines', 'costs', 'movements', 'outputBatch']);
    }

    public function fingerprint(Model $document): string
    {
        /** @var ProductionRun $document */
        $movements = $document->movements()->orderBy('id')->get(['id', 'batch_id', 'direction', 'quantity', 'unit_cost', 'reverses_movement_id']);
        $lock = AccountingPeriod::current();

        return hash('sha256', (string) json_encode([
            'r' => [
                $document->id, $document->run_number, $document->status?->value, Carbon::parse($document->occurred_at)->toIso8601String(),
                (string) $document->output_quantity, (string) $document->output_value, $document->output_batch_id, $document->reversed_at?->toIso8601String(),
            ],
            'movements' => $movements->map(fn ($m) => [$m->id, $m->batch_id, $m->direction?->value, (string) $m->quantity, (string) $m->unit_cost, $m->reverses_movement_id])->all(),
            'output' => $document->output_batch_id === null ? null : [
                (string) StockBatch::query()->whereKey($document->output_batch_id)->value('current_quantity'),
                StockMovement::query()->where('batch_id', $document->output_batch_id)->max('id'),
            ],
            'journals' => Journal::query()->where('source_type', $document->getMorphClass())->where('source_id', $document->getKey())
                ->orderBy('id')->get(['id', 'reverses_journal_id'])->map(fn ($j) => [$j->id, $j->reverses_journal_id])->all(),
            'period' => [$lock?->closed_through?->toDateString(), (bool) $lock?->is_unlocked],
        ]));
    }

    public function validateReplacement(Model $document, array $input): ReplacementData
    {
        /** @var ProductionRun $document */
        $data = Validator::make($input, ProductionRunForm::rules((int) app(BusinessContext::class)->id()))->validate();

        if ($this->sameAs($document, $data)) {
            throw ValidationException::withMessages(['lines' => 'Nothing was changed. Change what was wrong, or go back.']);
        }

        $arguments = ProductionRunForm::arguments($data);

        try {
            $this->recordRun->prepare(...$arguments, createdBy: $document->createdBy ?? auth()->user());
        } catch (PeriodClosedException|InvalidArgumentException $e) {
            throw ValidationException::withMessages(['occurred_at' => $e->getMessage()]);
        }

        // ⚠ The successor's own value is only known once FIFO has priced its
        // inputs at confirm; the Tk 20,000 rule reads the run being corrected.
        return new ReplacementData(Carbon::parse($data['occurred_at'])->startOfDay(), $this->totalMinor($document), $data, $data);
    }

    public function replacementInput(ReplacementData $replacement): array
    {
        return $replacement->input;
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var ProductionRun $document */
        $document->loadMissing(['outputProduct.unitOfMeasure', 'outputBatch', 'lines.product']);
        $on = Tk::date($dates->reversalDate);
        $unit = $document->outputProduct?->unitOfMeasure?->abbreviation ?? 'units';
        $made = rtrim(rtrim((string) $document->output_quantity, '0'), '.');
        $inputs = $document->lines->map(fn ($l) => $l->product?->name)->filter()->implode(', ');

        $lines = [
            new EffectLine("{$made} {$unit} of {$document->outputProduct?->name} come out of batch {$document->outputBatch?->batch_number} on {$on}.", null, null, 'stock'),
            new EffectLine("What it used ({$inputs}) goes back onto the batches it came from, at the cost it left at.", null, null, 'stock'),
        ];

        if ($this->journalOf($document) !== null) {
            $lines[] = new EffectLine('Its ledger entry is reversed on the same date.', null, null, 'ledger');
        }

        if ($action === DocumentAction::Correct && $replacement !== null) {
            $quantity = rtrim(rtrim(number_format((float) $replacement->values['output_quantity'], 4, '.', ''), '0'), '.');
            $lines[] = new EffectLine("A new run then makes {$quantity} {$unit} on ".Tk::date($replacement->date).', using its inputs first in, first out, as any run does.', null, null, 'stock');
        }

        return $lines;
    }

    public function describeStays(Model $document, DocumentAction $action): array
    {
        $number = $this->policy->numberOf($document);

        return $action === DocumentAction::Correct
            ? ["{$number} stays, marked Reversed.", "The new run gets its own number and the label \"Correction of {$number}\"."]
            : ["{$number} stays, marked Reversed, with its movements."];
    }

    public function originalDate(Model $document): Carbon
    {
        /** @var ProductionRun $document */
        return $this->policy->originalDate($document);
    }

    public function totalMinor(Model $document): int
    {
        /** @var ProductionRun $document */
        return $this->policy->valueMinor($document);
    }

    public function makerAndLastEditorIds(Model $document): array
    {
        return array_values(array_unique(array_filter([(int) $document->created_by, (int) $document->confirmed_by])));
    }

    public function journalOf(Model $document): ?Journal
    {
        /** @var ProductionRun $document */
        return $this->posting->existingJournal($document);
    }

    public function reverse(Model $document, CommitContext $ctx): ?Journal
    {
        /** @var ProductionRun $document */
        $original = $this->journalOf($document);
        $occurred = Carbon::parse($document->occurred_at);
        $at = $ctx->dates->reversalDate->isSameDay($occurred) ? $occurred : $ctx->dates->reversalDate;

        try {
            $this->reverseRun->handle($document, $ctx->actor, "Reversal of production run {$document->run_number}: {$ctx->reason->display()}", $at);
        } catch (RuntimeException|InvalidArgumentException|DomainException|PeriodClosedException $e) {
            throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
        }

        return $original === null ? null : Journal::query()->where('reverses_journal_id', $original->id)->first();
    }

    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model
    {
        /** @var ProductionRun $document */
        $arguments = ProductionRunForm::arguments($replacement->values);
        $arguments['note'] = trim(($arguments['note'] ?? '').' (Correction of '.$document->run_number.')');

        try {
            $draft = $this->recordRun->draft(...$arguments, createdBy: $ctx->actor);

            return $this->recordRun->confirm($draft, $ctx->actor);
        } catch (RuntimeException|InvalidArgumentException|DomainException|PeriodClosedException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }
    }

    public function journalOfSuccessor(Model $successor): ?Journal
    {
        /** @var ProductionRun $successor */
        return $this->posting->existingJournal($successor);
    }

    public function markOriginal(Model $document, ?Journal $reversal, CommitContext $ctx): void
    {
        $document->refresh()->forceFill([
            'status' => ProductionRunStatus::Reversed,
            'reversed_on' => $ctx->dates->reversalDate,
            'reversal_reason' => $ctx->reason->display(),
            'reversal_journal_id' => $reversal?->id,
        ])->save();
    }

    public function successorClass(): string
    {
        return ProductionRun::class;
    }

    /**
     * This type's own invariants: the run is Reversed; every portion it took
     * has its mirror on the same batch; its output batch is back to nothing;
     * the stock value of all its movements nets to zero; a successor is
     * confirmed.
     */
    public function assertInvariants(Model $original, ?Model $successor): void
    {
        /** @var ProductionRun $original */
        if ($original->status !== ProductionRunStatus::Reversed) {
            throw new LogicException("{$original->run_number} was not marked.");
        }

        $movements = $original->movements()->get();

        foreach ($movements->whereNull('reverses_movement_id') as $movement) {
            $mirrors = $movements->where('reverses_movement_id', $movement->id);

            if ($mirrors->isEmpty() || $mirrors->contains(fn ($m) => (int) $m->batch_id !== (int) $movement->batch_id || $m->direction === $movement->direction)) {
                throw new LogicException("{$original->run_number}'s reversal does not mirror its movements.");
            }
        }

        if (! StockValue::sum($movements)->isZero() || (float) StockBatch::query()->whereKey($original->output_batch_id)->value('current_quantity') !== 0.0) {
            throw new LogicException("{$original->run_number}'s reversal does not take back what it moved.");
        }

        if ($successor !== null && ! $successor->isConfirmed()) {
            throw new LogicException('The corrected run was not confirmed.');
        }

        // Inputs back in; output out.
        $outputs = $movements->whereNull('reverses_movement_id')->where('direction', MovementDirection::In);

        if ($outputs->count() !== 1) {
            throw new LogicException("{$original->run_number} has no single output movement.");
        }
    }

    public function visibleTo(Model $document, User $user): bool
    {
        return $this->policy->canView($document, $user);
    }

    /** @param array<string, mixed> $data */
    private function sameAs(ProductionRun $run, array $data): bool
    {
        // A blank unit is the product's own stock unit.
        $units = Product::query()->whereIn('id', collect($data['lines'])->pluck('product_id')->merge($run->lines()->pluck('product_id')))->pluck('unit_of_measure_id', 'id');
        $unit = fn (int $productId, int $unitId) => $unitId !== 0 ? $unitId : (int) ($units[$productId] ?? 0);
        $norm = fn (array $form) => json_encode([
            (int) $form['warehouse_id'], (int) $form['output_product_id'], round((float) $form['output_quantity'], 4),
            Carbon::parse($form['occurred_at'])->toDateString(), (string) ($form['output_expiry_date'] ?? ''), round((float) ($form['output_mrp'] ?? 0), 4),
            (bool) ($form['expense_waste'] ?? false), trim((string) ($form['note'] ?? '')),
            collect($form['lines'])->map(fn ($l) => [(int) $l['product_id'], round((float) $l['quantity'], 4), round((float) ($l['waste'] ?? 0), 4), $unit((int) $l['product_id'], (int) ($l['unit_id'] ?? 0))])->values()->all(),
            collect($form['costs'] ?? [])->map(fn ($c) => [(string) $c['kind'], (int) $c['account_id'], round((float) $c['amount'], 2)])->values()->all(),
        ]);

        return $norm(ProductionRunForm::of($run)) === $norm($data);
    }
}
