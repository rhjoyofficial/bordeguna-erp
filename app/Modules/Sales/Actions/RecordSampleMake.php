<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\LedgerRounding;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Actions\RecordStockOut;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\EffectiveDate;
use App\Modules\Inventory\Support\StockValue;
use App\Modules\Sales\Models\SampleMake;
use App\Support\DocumentNumber;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * ⭐ Make samples from raw materials (Samples, Joy 25 Oct 2026: "sometime we
 * make sample product from raw materials"). A small production run kept
 * simple on purpose: no recipe, no labour, no waste. The person picks the raw
 * material batches and how much of each; they are consumed EXACTLY (reason
 * `production`, each at its own cost) and one sample lot of the output
 * product is created in the same warehouse carrying that total cost.
 *
 *   SMK-2026-27-0001, the lot numbered SMPL-SMK-2026-27-0001
 *
 * ⚠ The value only changes shape, so the ledger hears nothing (the
 * production run's rule, C-MFG-5). Since the batches carry their exact value
 * (26 Oct 2026) the lot states exactly what it consumed (`valueMinor`), so a
 * four-decimal unit cost no longer leaves a residue. postResidue() stays as
 * the guard: should the lot ever differ from its inputs, the difference posts
 * to Rounding Differences against Stock on Hand (LedgerRounding), never
 * silently.
 *
 * ⚠ Not reversed here: a wrong make is corrected with a stock adjustment, as a
 * production run's leftovers are. Give sample takes from the lot like any
 * other batch.
 */
class RecordSampleMake
{
    public function __construct(
        private readonly RecordStockOut $stockOut,
        private readonly RecordStockIn $stockIn,
        private readonly PostJournal $postJournal,
    ) {}

    /**
     * @param  list<array{stock_batch_id: int, quantity: string}>  $inputs
     *
     * @throws ValidationException
     */
    public function handle(mixed $madeOn, Product $output, Warehouse $warehouse, string $quantity, array $inputs, User $by, ?string $note = null, ?string $expiryDate = null): SampleMake
    {
        $date = Carbon::parse($madeOn ?? 'today')->startOfDay();

        if ($date->isFuture()) {
            throw ValidationException::withMessages(['made_on' => 'That date has not happened yet.']);
        }

        AccountingPeriod::assertOpenFor($date);

        if (! $output->type->isStocked()) {
            throw ValidationException::withMessages(['product_id' => 'Choose a product that is kept in stock.']);
        }

        if (preg_match('/^\d+(\.\d{1,4})?$/', trim($quantity)) !== 1 || (float) $quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => "Enter how much {$output->name} was made."]);
        }

        if ($inputs === []) {
            throw ValidationException::withMessages(['inputs' => 'Add the raw materials the samples were made from.']);
        }

        $movedAt = EffectiveDate::resolveConsumingDay($date->toDateString(), 'made_on');

        try {
            return DB::transaction(function () use ($date, $output, $warehouse, $quantity, $inputs, $by, $note, $expiryDate, $movedAt) {
                $make = DocumentNumber::claim(
                    'number',
                    fn () => DocumentNumber::next(SampleMake::query(), 'number', RecordCustomerAdvance::prefix('SMK', (int) $warehouse->business_id, $date)),
                    fn (string $number) => SampleMake::create([
                        'business_id' => $warehouse->business_id,
                        'number' => $number,
                        'made_on' => $date->toDateString(),
                        'product_id' => $output->id,
                        'warehouse_id' => $warehouse->id,
                        'quantity' => number_format((float) $quantity, 4, '.', ''),
                        'note' => $note === null || trim($note) === '' ? null : trim($note),
                        'created_by' => $by->id,
                    ]),
                );

                $consumed = collect();

                foreach (array_values($inputs) as $index => $input) {
                    $batch = StockBatch::query()->with('product')->find($input['stock_batch_id'] ?? 0);

                    if ($batch === null || (int) $batch->warehouse_id !== (int) $warehouse->id) {
                        throw ValidationException::withMessages(["inputs.{$index}.stock_batch_id" => "Choose a batch kept in {$warehouse->name}."]);
                    }

                    if ((int) $batch->product_id === (int) $output->id) {
                        throw ValidationException::withMessages(["inputs.{$index}.stock_batch_id" => "{$output->name} cannot be made from itself."]);
                    }

                    $movements = $this->stockOut->fromBatches(
                        product: $batch->product,
                        warehouse: $warehouse,
                        picks: [['batch_id' => $batch->id, 'quantity' => (string) ($input['quantity'] ?? '0')]],
                        performedBy: $by,
                        note: "Sample make {$make->number}",
                        reason: MovementReason::Production,
                        occurredAt: $movedAt,
                    );

                    foreach ($movements as $movement) {
                        $make->inputs()->create([
                            'product_id' => $batch->product_id,
                            'stock_batch_id' => $batch->id,
                            'quantity' => $movement->quantity,
                            'unit_cost' => $movement->unit_cost,
                            'cost_paisa' => StockValue::of($movement)->absolute()->minor,
                            'stock_movement_id' => $movement->id,
                        ]);
                    }

                    $consumed = $consumed->merge($movements);
                }

                $cost = StockValue::sum($consumed)->absolute();
                $unitCost = number_format($cost->minor / 100 / (float) $quantity, 4, '.', '');

                $in = $this->stockIn->handle(
                    product: $output,
                    warehouse: $warehouse,
                    quantity: number_format((float) $quantity, 4, '.', ''),
                    unitCost: $unitCost,
                    performedBy: $by,
                    batchNumber: "SMPL-{$make->number}",
                    expiryDate: $expiryDate === null || trim($expiryDate) === '' ? null : $expiryDate,
                    note: "Sample lot of {$make->number}",
                    reason: MovementReason::Production,
                    occurredAt: $movedAt,
                    manufacturedDate: $date->toDateString(),
                    // ⭐ 26 Oct 2026 (exact batch value): the lot is worth
                    // exactly what was consumed, not quantity x a 4 dp cost.
                    valueMinor: $cost->minor,
                );

                $make->update(['stock_batch_id' => $in->batch_id, 'stock_movement_id' => $in->id, 'cost_total_paisa' => $cost->minor]);

                $this->postResidue($make, StockValue::sum($consumed->push($in)), $cost, $date, $by);

                return $make->fresh(['inputs', 'batch']);
            });
        } catch (DomainException|InvalidArgumentException|InsufficientStockException $e) {
            throw ValidationException::withMessages(['inputs' => $e->getMessage()]);
        }
    }

    /** The paisa a four-decimal unit cost could not carry, to Rounding Differences. */
    private function postResidue(SampleMake $make, Money $net, Money $scale, Carbon $on, User $by): void
    {
        if ($net->isZero() || AccountMapping::forKeyOn('inventory.stock_on_hand', $on) === null) {
            return;
        }

        $lines = LedgerRounding::balance(
            [JournalLineDraft::signed(AccountMapping::resolve('inventory.stock_on_hand', $on)->id, $net, "Sample lot of {$make->number}")],
            $on,
            "Rounding on sample make {$make->number}",
            $scale,
        );

        $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: $lines,
            memo: "Sample make {$make->number}: rounding of the lot's unit cost",
            source: $make,
        ), $by);
    }
}
