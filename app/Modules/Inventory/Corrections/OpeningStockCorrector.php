<?php

namespace App\Modules\Inventory\Corrections;

use App\Models\User;
use App\Modules\Accounting\Actions\ReverseJournal;
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
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordOpeningStock;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Actions\RevalueStockBatch;
use App\Modules\Inventory\Actions\ReverseStockReceipt;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\OpeningStock;
use App\Modules\Inventory\Models\OpeningStockLine;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\BatchHistory;
use App\Modules\Inventory\Support\BatchNumbers;
use App\Modules\Inventory\Support\EffectiveDate;
use App\Modules\Inventory\Support\StockValue;
use App\Modules\Purchasing\Support\ReceivedBatchDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/**
 * ⭐ Corrections wave 1, B4 (20 Oct 2026): opening stock is CORRECTED into
 * Opening Balance Equity on the opening date (FINAL-PLAN 3.3 and 4.2).
 *
 * The trigger case: a count that missed 10 jars on the opening day. Adding
 * them later must raise Stock on Hand and Opening Balance Equity on that day,
 * never put 10 jars of "profit" through Inventory Write-offs (5200), which is
 * what the only fix until now (a found-stock adjustment) did.
 *
 *   reverse()          ReverseJournal on the original's one Opening Balance
 *                      journal, on its own date; the original stops being the
 *                      warehouse's live opening stock
 *   createSuccessor()  the corrected opening stock (same warehouse, same
 *                      date, `corrects_id` = the original), line by line:
 *                        batch number / expiry  fixed on the batch itself
 *                                               (same physical goods)
 *                        more units             an opening receipt of the
 *                                               difference INTO THE SAME BATCH
 *                        fewer units, or 0      the difference back out of the
 *                                               same batch (ReverseStockReceipt)
 *                        another cost           a revaluation of the untouched
 *                                               batch (RevalueStockBatch)
 *                        a forgotten product    a new batch (RecordStockIn)
 *                      then its journal through RecordOpeningStock::postJournal,
 *                      the same Opening Balance rule as the first entry
 *   markOriginal()     the Reversible columns; the original, its lines and its
 *                      journal stay, marked Corrected
 *
 * ⚠⚠ Every movement either document writes is tagged `opening_stock_id`, and
 * each corrected line's value is the sum of ITS movements' values, so the new
 * journal is the stock movements to the paisa (the subledger reconciliation
 * keeps agreeing with Stock on Hand) and "used since the opening" is one
 * question: any quantity movement on the batch that is NOT tagged.
 *
 * ⚠ The stock rules stay (FINAL-PLAN 6.6.3): a line can only come down by
 * what its batch never went below since the opening (the batch history may
 * not turn negative at any point); a cost can only change while the batch is
 * untouched, because stock already sold at the old cost is a cost-of-goods
 * question this screen does not answer (the plan's Tk 10,000 rule belongs to
 * the accountant's revaluation); a batch whose cost moved since (a landed
 * cost) changes only its label details here. Each refusal is a sentence on the
 * line it is about.
 *
 * RecordOpeningStock's comment about 3900 stays true: Opening Balance Equity
 * is still found by its slug, never through an `inventory.` mapping key.
 */
class OpeningStockCorrector implements DocumentCorrector
{
    public function __construct(
        private readonly OpeningStockActionPolicy $policy,
        private readonly ReverseJournal $reverseJournal,
        private readonly RecordOpeningStock $recordOpening,
        private readonly RecordStockIn $recordStockIn,
        private readonly ReverseStockReceipt $takeBack,
        private readonly RevalueStockBatch $revalue,
    ) {}

    public function policy(): DocumentActionPolicy
    {
        return $this->policy;
    }

    public function find(int $documentId): ?Model
    {
        return OpeningStock::query()->find($documentId);
    }

    public function lockForCommit(int $documentId): Model
    {
        $opening = OpeningStock::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();

        Journal::query()
            ->where('source_type', $opening->getMorphClass())
            ->where('source_id', $opening->getKey())
            ->lockForUpdate()
            ->get(['id']);

        $batchIds = StockMovement::query()->whereIn('id', $opening->lines()->pluck('stock_movement_id'))->pluck('batch_id');
        StockBatch::query()->whereIn('id', $batchIds)->lockForUpdate()->get(['id']);

        return $opening->load(['lines.stockMovement.batch', 'lines.product', 'warehouse']);
    }

    public function fingerprint(Model $document): string
    {
        /** @var OpeningStock $document */
        $lines = $document->lines()->with('stockMovement')->orderBy('id')->get();
        $batchIds = $lines->map(fn (OpeningStockLine $l) => $l->stockMovement?->batch_id)->filter()->values();
        $lock = AccountingPeriod::current();

        return hash('sha256', (string) json_encode([
            'o' => [$document->id, $document->warehouse_id, $document->live_warehouse_id, Carbon::parse($document->as_at)->toDateString(), $document->reversed_at?->toIso8601String()],
            'lines' => $lines->map(fn (OpeningStockLine $l) => [
                $l->id, $l->product_id, (string) $l->quantity, (string) $l->unit_cost, $l->batch_number,
                $l->expiry_date?->toDateString(), $l->value_paisa, $l->stock_movement_id,
            ])->all(),
            'batches' => StockBatch::query()->whereIn('id', $batchIds)->orderBy('id')->get(['id', 'current_quantity', 'unit_cost', 'batch_number', 'expiry_date'])
                ->map(fn ($b) => [$b->id, (string) $b->current_quantity, (string) $b->unit_cost, $b->batch_number, (string) $b->expiry_date])->all(),
            'last_on_batches' => StockMovement::query()->whereIn('batch_id', $batchIds)->max('id'),
            'journals' => Journal::query()->where('source_type', $document->getMorphClass())->where('source_id', $document->getKey())
                ->orderBy('id')->get(['id', 'reverses_journal_id'])->map(fn ($j) => [$j->id, $j->reverses_journal_id])->all(),
            'period' => [$lock?->closed_through?->toDateString(), (bool) $lock?->is_unlocked],
        ]));
    }

    /**
     * The corrected count, checked line by line with the rules above. The
     * replacement carries the plan createSuccessor() executes, worked out from
     * the locked original, so what the preview said is what is written.
     *
     * Input: `note`, and `lines`: every line of the original by `line_id`
     * (quantity 0 takes it out) plus new lines without one.
     */
    public function validateReplacement(Model $document, array $input): ReplacementData
    {
        /** @var OpeningStock $document */
        $businessId = (int) app(BusinessContext::class)->id();
        $data = Validator::make($input, [
            'note' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_id' => ['nullable', 'integer'],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('business_id', $businessId)],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'lines.*.batch_number' => ['nullable', 'string', 'max:100'],
            'lines.*.expiry_date' => ['nullable', 'date'],
        ], [
            'lines.required' => 'The opening stock needs its lines.',
            'lines.*.product_id.required' => 'Choose the product.',
            'lines.*.quantity.required' => 'Enter how many.',
            'lines.*.unit_cost.required' => 'Enter the cost per unit.',
        ])->validate();

        $document->loadMissing(['lines.stockMovement.batch', 'lines.product']);
        $existing = $document->lines->keyBy('id');
        $products = Product::query()->whereIn('id', array_column($data['lines'], 'product_id'))->get()->keyBy('id');
        $seen = [];
        $plan = [];
        $errors = [];
        $changed = trim((string) ($data['note'] ?? '')) !== trim((string) $document->note);
        $totalMinor = 0;

        foreach ($data['lines'] as $index => $row) {
            $product = $products[(int) $row['product_id']];
            $q1 = $this->qty((string) $row['quantity']);
            $c1 = $this->cost((string) $row['unit_cost']);
            $b1 = $this->text($row['batch_number'] ?? null);
            $e1 = $this->text($row['expiry_date'] ?? null) === null ? null : Carbon::parse($row['expiry_date'])->toDateString();
            $lineId = isset($row['line_id']) && $row['line_id'] !== null ? (int) $row['line_id'] : null;

            if ($lineId === null) {
                if ($q1 === 0) {
                    continue;
                }

                if (! $product->type->isStocked()) {
                    $errors["lines.{$index}.product_id"] = "{$product->name} is a service and cannot hold stock.";

                    continue;
                }

                foreach (ReceivedBatchDates::required((bool) $product->tracks_expiry, $b1 ?? 'automatic', $e1) as $field => $problem) {
                    $errors["lines.{$index}.{$field}"] = $problem;
                }

                $value = Money::ofProduct($this->unscaled($q1), $this->costString($c1))->minor;
                $totalMinor += $value;
                $changed = true;
                $plan[] = ['kind' => 'new', 'product_id' => $product->id, 'quantity' => $this->unscaled($q1), 'unit_cost' => $this->costString($c1), 'batch_number' => $b1, 'expiry_date' => $e1, 'estimate' => $value];

                continue;
            }

            /** @var OpeningStockLine|null $line */
            $line = $existing->get($lineId);

            if ($line === null || isset($seen[$lineId])) {
                $errors["lines.{$index}.product_id"] = 'This line is not part of '.$this->policy->numberOf($document).'.';

                continue;
            }

            $seen[$lineId] = true;

            if ((int) $line->product_id !== (int) $product->id) {
                $errors["lines.{$index}.product_id"] = 'A line keeps its product. Set this one to 0 and add the right product as a new line.';

                continue;
            }

            $movement = $line->stockMovement;
            $batch = $movement?->batch;

            if ($movement === null || $batch === null) {
                $errors["lines.{$index}.product_id"] = 'This line has lost its batch. Ask the administrator to check it.';

                continue;
            }

            $q0 = $this->qty((string) $line->quantity);
            $c0 = $this->cost((string) $line->unit_cost);
            $b0 = $this->text($line->batch_number);
            $e0 = $line->expiry_date?->toDateString();
            $name = $batch->batch_number ?? "#{$batch->id}";
            $value = $line->value()->minor;
            $op = [
                'kind' => 'existing', 'line_id' => $line->id, 'product_id' => $product->id,
                'from' => $this->unscaled($q0), 'quantity' => $this->unscaled($q1), 'old_cost' => $this->costString($c0), 'unit_cost' => $this->costString($q1 === 0 ? $c0 : $c1),
                'old_batch_number' => $b0, 'batch_number' => $b1 ?? $b0, 'old_expiry_date' => $e0, 'expiry_date' => $e1,
            ];

            $costChanged = $q1 > 0 && $c1 !== $c0;
            $detailsChanged = ($b1 !== null && $b1 !== $b0) || $e1 !== $e0;
            $batchCostMoved = $this->cost((string) $batch->unit_cost) !== $c0;

            if (($q1 !== $q0 || $costChanged) && $batchCostMoved) {
                $errors["lines.{$index}.quantity"] = "Batch {$name}'s cost has changed since the opening (a landed cost or a revaluation), so only its batch number and expiry can be fixed here. Add missing units as a new line.";

                continue;
            }

            if ($costChanged && BatchHistory::usedSinceOpening($movement)) {
                $errors["lines.{$index}.unit_cost"] = "Batch {$name} has been sold or moved since the opening at the old cost, so its cost cannot change here. Ask the accountant for a revaluation.";

                continue;
            }

            if ($q1 < $q0) {
                $room = BatchHistory::lowestScaledFrom($movement);

                if ($room < $q0 - $q1) {
                    $errors["lines.{$index}.quantity"] = 'Batch '.$name.' never held fewer than '.$this->unscaled(max(0, $room)).' since the opening, so it can come down by at most that. Record the rest as damage or loss.';

                    continue;
                }
            }

            if ($q1 > 0) {
                foreach (ReceivedBatchDates::required((bool) $product->tracks_expiry, $op['batch_number'] ?? 'automatic', $e1) as $field => $problem) {
                    $errors["lines.{$index}.{$field}"] = $problem;
                }
            }

            if ($q1 === $q0 && ! $costChanged && ! $detailsChanged) {
                $op['kind'] = 'kept';
            } else {
                $changed = true;
            }

            // The estimate the preview and the Tk 20,000 rule read; the
            // written figure comes from the movements themselves.
            $estimate = $q1 === 0 ? 0 : ($costChanged ? Money::ofProduct($this->unscaled($q0), $this->costString($c1))->minor : $value);

            if ($q1 !== $q0 && $q1 > 0) {
                $delta = Money::ofProduct($this->unscaled(abs($q1 - $q0)), $this->costString($costChanged ? $c1 : $c0))->minor;
                $estimate += $q1 > $q0 ? $delta : -$delta;
            }

            $op['estimate'] = $estimate;
            $totalMinor += $estimate;
            $plan[] = $op;
        }

        foreach ($existing as $id => $line) {
            if (! isset($seen[$id]) && ! isset($errors['lines'])) {
                $errors['lines'] = 'Every line of the opening stock stays on the form. Set a quantity of 0 to take one out.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if (! $changed) {
            throw ValidationException::withMessages(['lines' => 'Nothing was changed. Change what was wrong, or go back.']);
        }

        if (collect($plan)->every(fn (array $op) => $op['kind'] === 'existing' && $op['quantity'] === '0.0000')) {
            throw ValidationException::withMessages(['lines' => 'An opening stock keeps at least one line with a quantity.']);
        }

        return new ReplacementData(
            Carbon::parse($document->as_at)->startOfDay(),
            $totalMinor,
            ['note' => $this->text($data['note'] ?? null), 'lines' => $plan],
            $data,
        );
    }

    public function replacementInput(ReplacementData $replacement): array
    {
        return $replacement->input;
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var OpeningStock $document */
        $document->loadMissing(['lines.product.unitOfMeasure', 'lines.stockMovement.batch', 'warehouse']);
        $on = Tk::date($dates->reversalDate);
        $products = Product::query()->with('unitOfMeasure')->whereIn('id', collect($replacement?->values['lines'] ?? [])->pluck('product_id'))->get()->keyBy('id');
        $lines = [];

        foreach ($replacement?->values['lines'] ?? [] as $op) {
            $product = $products[$op['product_id']] ?? null;
            $unit = $product?->unitOfMeasure?->abbreviation ?? 'units';
            $name = $product?->name ?? 'A product';
            $trim = fn (string $q) => rtrim(rtrim($q, '0'), '.');

            if ($op['kind'] === 'new') {
                $lines[] = new EffectLine("{$name}: {$trim($op['quantity'])} {$unit} added at Tk {$op['unit_cost']} each, as a new batch dated {$on}.", null, null, 'stock');

                continue;
            }

            if ($op['kind'] === 'kept') {
                continue;
            }

            $parts = [];

            if ($op['quantity'] !== $op['from']) {
                $parts[] = $op['quantity'] === '0.0000'
                    ? "taken out ({$trim($op['from'])} {$unit} back out of its batch)"
                    : "{$trim($op['from'])} to {$trim($op['quantity'])} {$unit}";
            }

            if ($op['unit_cost'] !== $op['old_cost']) {
                $parts[] = "cost Tk {$op['old_cost']} to Tk {$op['unit_cost']}";
            }

            if ($op['batch_number'] !== $op['old_batch_number'] || $op['expiry_date'] !== $op['old_expiry_date']) {
                $parts[] = 'batch details fixed';
            }

            $lines[] = new EffectLine("{$name}: ".implode(', ', $parts).'.', null, null, 'stock');
        }

        $before = $this->totalMinor($document);
        $after = $replacement?->totalMinor ?? $before;
        $difference = $after - $before;

        if ($difference !== 0) {
            $lines[] = new EffectLine(
                'Stock on Hand and Opening Balance Equity both go '.($difference > 0 ? 'up' : 'down').' by about '.Tk::of(abs($difference))." on {$on}. Nothing goes to Inventory Write-offs.",
                'Opening Balance Equity',
                $difference,
                'ledger',
            );
        }

        return $lines;
    }

    public function describeStays(Model $document, DocumentAction $action): array
    {
        $number = $this->policy->numberOf($document);

        return [
            "{$number} stays, marked Corrected.",
            "The corrected opening stock gets a new number and the label \"Correction of {$number}\".",
            'Batches keep their history: only the difference moves.',
        ];
    }

    public function originalDate(Model $document): Carbon
    {
        /** @var OpeningStock $document */
        return $this->policy->originalDate($document);
    }

    public function totalMinor(Model $document): int
    {
        /** @var OpeningStock $document */
        return $this->policy->valueMinor($document);
    }

    public function makerAndLastEditorIds(Model $document): array
    {
        return array_values(array_filter([(int) $document->created_by]));
    }

    public function journalOf(Model $document): ?Journal
    {
        /** @var OpeningStock $document */
        return $this->policy->journalOf($document);
    }

    public function reverse(Model $document, CommitContext $ctx): ?Journal
    {
        /** @var OpeningStock $document */
        // It stops being the warehouse's live opening stock first, so its
        // successor can take the place (one live per warehouse, in the index).
        $document->forceFill(['live_warehouse_id' => null])->save();

        $journal = $this->journalOf($document);

        if ($journal === null) {
            return null;
        }

        try {
            return $this->reverseJournal->handle(
                $journal->load('lines'),
                $ctx->actor,
                $ctx->dates->reversalDate,
                "Correction of {$this->policy->numberOf($document)}: {$ctx->reason->display()}",
                $ctx->pass,
            );
        } catch (PeriodClosedException $e) {
            throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
        }
    }

    public function createSuccessor(Model $document, ReplacementData $replacement, CommitContext $ctx): Model
    {
        /** @var OpeningStock $document */
        $number = $this->policy->numberOf($document);
        $asAt = Carbon::parse($document->as_at)->startOfDay();
        $note = "Opening stock correction of {$number}: {$ctx->reason->display()}";

        try {
            // The opening date, through the one rule every stock path uses
            // (future, period lock, or the Owner's pass for this correction).
            EffectiveDate::resolve($asAt, 'as_at', $ctx->pass);

            $successor = OpeningStock::create([
                'business_id' => $document->business_id,
                'warehouse_id' => $document->warehouse_id,
                'as_at' => $asAt,
                'note' => $replacement->values['note'],
                'corrects_id' => $document->id,
                'created_by' => $ctx->actor->id,
            ]);

            $lines = $document->lines()->with(['stockMovement.batch', 'product'])->get()->keyBy('id');

            foreach ($replacement->values['lines'] as $op) {
                if ($op['kind'] === 'new') {
                    $this->addLine($successor, $op, $ctx, $note, $asAt);

                    continue;
                }

                $this->carryLine($successor, $lines[$op['line_id']], $op, $ctx, $note);
            }
        } catch (InsufficientStockException|InvalidArgumentException|PeriodClosedException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        $this->recordOpening->postJournal($successor->fresh(['lines.stockMovement', 'warehouse']), $ctx->actor, $ctx->pass);

        return $successor->fresh(['lines', 'warehouse']);
    }

    public function journalOfSuccessor(Model $successor): ?Journal
    {
        /** @var OpeningStock $successor */
        return $this->policy->journalOf($successor);
    }

    public function markOriginal(Model $document, ?Journal $reversal, CommitContext $ctx): void
    {
        $document->forceFill([
            'live_warehouse_id' => null,
            'reversed_on' => $ctx->dates->reversalDate,
            'reversed_at' => now(),
            'reversed_by' => $ctx->actor->id,
            'reversal_reason' => $ctx->reason->display(),
            'reversal_journal_id' => $reversal?->id,
        ])->save();
    }

    public function successorClass(): string
    {
        return OpeningStock::class;
    }

    /**
     * This type's own invariants: the original is marked and no longer live,
     * the successor is the live one and names it; every successor line holds
     * exactly what the opening movements on its batch net to (quantity and
     * value); the successor's journal is its lines to the paisa; and what
     * the correction moved is exactly the difference between the two
     * documents (so Opening Balance Equity and Stock on Hand moved together).
     */
    public function assertInvariants(Model $original, ?Model $successor): void
    {
        /** @var OpeningStock $original */
        /** @var OpeningStock|null $successor */
        if (! $original->isReversed() || $original->live_warehouse_id !== null || $successor === null) {
            throw new LogicException("{$this->policy->numberOf($original)} was not marked.");
        }

        if ((int) $successor->corrects_id !== (int) $original->id || (int) $successor->live_warehouse_id !== (int) $successor->warehouse_id) {
            throw new LogicException('The corrected opening stock does not take the original\'s place.');
        }

        $successor->load('lines.stockMovement');
        $original->load('lines.stockMovement');

        foreach ($successor->lines as $line) {
            $openingMovements = StockMovement::query()->where('batch_id', $line->stockMovement?->batch_id)->whereNotNull('opening_stock_id')->get();
            $quantity = $openingMovements->sum(fn ($m) => ($m->direction === MovementDirection::In ? 1 : -1) * (int) round((float) $m->quantity * 10000));

            if ($quantity !== $this->qty((string) $line->quantity) || StockValue::sum($openingMovements)->minor !== $line->value()->minor) {
                throw new LogicException("A corrected opening line of {$line->product_id} does not match its batch.");
            }
        }

        $moved = StockValue::sum(StockMovement::query()->where('opening_stock_id', $successor->id)->get());

        if ($successor->totalValue()->minor - $original->totalValue()->minor !== $moved->minor) {
            throw new LogicException('The corrected opening stock does not add up to what was moved.');
        }

        $journal = $this->policy->journalOf($successor);

        if ($journal !== null && $journal->totalDebits()->minor !== $successor->totalValue()->absolute()->minor) {
            throw new LogicException('The corrected opening stock\'s journal does not match its lines.');
        }
    }

    public function visibleTo(Model $document, User $user): bool
    {
        return $this->policy->canView($document, $user);
    }

    // ─── the line work ──────────────────────────────────────────────────────

    /** A forgotten product (or more of one in a batch of its own): a new opening batch. */
    private function addLine(OpeningStock $successor, array $op, CommitContext $ctx, string $note, Carbon $asAt): void
    {
        $product = Product::query()->findOrFail($op['product_id']);
        $batchNumber = BatchNumbers::resolve($product, $op['batch_number'], $asAt);

        $movement = $this->recordStockIn->handle(
            product: $product,
            warehouse: $successor->warehouse,
            quantity: $op['quantity'],
            unitCost: $op['unit_cost'],
            performedBy: $ctx->actor,
            batchNumber: $batchNumber,
            expiryDate: $op['expiry_date'],
            note: $note,
            occurredAt: $asAt,
            pass: $ctx->pass,
        );

        $this->tag([$movement->id], $successor);

        $successor->lines()->create([
            'business_id' => $successor->business_id,
            'product_id' => $product->id,
            'quantity' => $op['quantity'],
            'unit_cost' => $op['unit_cost'],
            'batch_number' => $batchNumber,
            'expiry_date' => $op['expiry_date'],
            'value_paisa' => StockValue::of($movement->fresh())->minor,
            'stock_movement_id' => $movement->id,
        ]);
    }

    /** An existing line onto the successor, with whatever changed on its batch. */
    private function carryLine(OpeningStock $successor, OpeningStockLine $line, array $op, CommitContext $ctx, string $note): void
    {
        $receipt = $line->stockMovement;
        $batch = StockBatch::query()->whereKey($receipt->batch_id)->lockForUpdate()->firstOrFail();
        $at = Carbon::parse($receipt->occurred_at);
        $value = $line->value()->minor;
        $from = $this->qty($op['from']);
        $to = $this->qty($op['quantity']);

        // Same physical goods, a typo on the label: fixed on the batch itself.
        if ($op['kind'] !== 'kept' && ($op['batch_number'] !== $op['old_batch_number'] || $op['expiry_date'] !== $op['old_expiry_date'])) {
            $attributes = ['batch_number' => $op['batch_number'], 'expiry_date' => $op['expiry_date']];

            // A date moved out of the past brings an expired batch back.
            if ($batch->status === StockBatchStatus::Expired && ($op['expiry_date'] === null || $op['expiry_date'] >= Carbon::today()->toDateString())) {
                $attributes['status'] = (float) $batch->current_quantity > 0 ? StockBatchStatus::Active : StockBatchStatus::Depleted;
            }

            $batch->forceFill($attributes)->save();
        }

        if ($to === 0) {
            $value += $this->takeOut($receipt, $from, $successor, $ctx, $note, $at);

            // ⚠ A line valued from several movements can differ by a paisa
            // from one movement of its whole quantity. The batch is empty of
            // this opening now, so that paisa leaves with it rather than
            // staying on as a value with no units.
            if ($value !== 0) {
                $movement = $this->revalue->handle(
                    batch: $batch->refresh(),
                    direction: $value > 0 ? MovementDirection::Out : MovementDirection::In,
                    newUnitCost: (string) $batch->unit_cost,
                    valueDelta: Money::ofMinor(abs($value))->toTaka(),
                    performedBy: $ctx->actor->id,
                    note: $note,
                    occurredAt: $at,
                    reason: MovementReason::Adjustment,
                );
                $this->tag([$movement->id], $successor);
            }

            return;
        }

        if ($op['unit_cost'] !== $op['old_cost']) {
            EffectiveDate::resolve($at, 'as_at', $ctx->pass);
            $target = Money::ofProduct($this->unscaled($from), $op['unit_cost'])->minor;
            $delta = $target - $value;

            if ($delta !== 0) {
                $movement = $this->revalue->handle(
                    batch: $batch,
                    direction: $delta > 0 ? MovementDirection::In : MovementDirection::Out,
                    newUnitCost: $op['unit_cost'],
                    valueDelta: Money::ofMinor(abs($delta))->toTaka(),
                    performedBy: $ctx->actor->id,
                    note: $note,
                    occurredAt: $at,
                    reason: MovementReason::Adjustment,
                );
                $this->tag([$movement->id], $successor);
                $value += StockValue::of($movement->fresh())->minor;
            } else {
                StockBatch::withoutGlobalScopes()->whereKey($batch->id)->update(['unit_cost' => $op['unit_cost']]);
            }

            $batch->refresh();
        }

        if ($to > $from) {
            // ⚠ More of the SAME batch counted on the opening day: an opening
            // receipt of the difference into it, at the batch's cost, on the
            // opening moment. Nothing earlier than an In can go negative.
            EffectiveDate::resolve($at, 'as_at', $ctx->pass);

            $movement = StockMovement::create([
                'product_id' => $batch->product_id,
                'warehouse_id' => $batch->warehouse_id,
                'batch_id' => $batch->id,
                'direction' => MovementDirection::In,
                'reason' => MovementReason::Adjustment,
                'quantity' => $this->unscaled($to - $from),
                'unit_cost' => $batch->unit_cost,
                'performed_by' => $ctx->actor->id,
                'note' => $note,
                'occurred_at' => $at,
                'opening_stock_id' => $successor->id,
            ]);

            // What the batch was received with, as the corrected count says.
            StockBatch::withoutGlobalScopes()->whereKey($batch->id)->update([
                'initial_quantity' => $this->unscaled($this->qty((string) $batch->initial_quantity) + $to - $from),
            ]);

            $value += StockValue::of($movement)->minor;
        } elseif ($to < $from) {
            $value += $this->takeOut($receipt, $from - $to, $successor, $ctx, $note, $at);
        }

        $successor->lines()->create([
            'business_id' => $successor->business_id,
            'product_id' => $line->product_id,
            'quantity' => $op['quantity'],
            'unit_cost' => $op['unit_cost'],
            'batch_number' => $op['batch_number'],
            'expiry_date' => $op['expiry_date'],
            'value_paisa' => $value,
            'stock_movement_id' => $receipt->id,
        ]);
    }

    /** Units the opening count overstated, back out of the batch they were counted into. Returns the (negative) value moved. */
    private function takeOut(StockMovement $receipt, int $scaled, OpeningStock $successor, CommitContext $ctx, string $note, Carbon $at): int
    {
        if (BatchHistory::lowestScaledFrom($receipt) < $scaled) {
            throw new InvalidArgumentException('A batch was used since the preview, so its count cannot come down that far. Please look again.');
        }

        $written = $this->takeBack->handle(
            receipts: [$receipt],
            quantity: $this->unscaled($scaled),
            performedBy: $ctx->actor,
            note: $note,
            reason: MovementReason::Adjustment,
            occurredAt: $at,
            pass: $ctx->pass,
        )->map(fn ($reversed) => $reversed->movement);

        $this->tag($written->pluck('id')->all(), $successor);

        return StockValue::sum($written)->minor;
    }

    /** @param list<int> $ids */
    private function tag(array $ids, OpeningStock $successor): void
    {
        StockMovement::query()->whereIn('id', $ids)->update(['opening_stock_id' => $successor->id]);
    }

    // ─── numbers ────────────────────────────────────────────────────────────

    private function qty(string $quantity): int
    {
        return (int) round((float) $quantity * 10000);
    }

    private function unscaled(int $scaled): string
    {
        return number_format($scaled / 10000, 4, '.', '');
    }

    private function cost(string $cost): int
    {
        return (int) round((float) $cost * 10000);
    }

    private function costString(int $scaled): string
    {
        return number_format($scaled / 10000, 4, '.', '');
    }

    private function text(mixed $value): ?string
    {
        $value = $value === null ? '' : trim((string) $value);

        return $value === '' ? null : $value;
    }
}
