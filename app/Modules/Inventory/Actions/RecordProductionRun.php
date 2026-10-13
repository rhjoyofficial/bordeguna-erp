<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Enums\ProductionRunStatus;
use App\Modules\Inventory\Models\BillOfMaterial;
use App\Modules\Inventory\Models\InventorySetting;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductionRun;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\BomSuggestion;
use App\Modules\Inventory\Support\EffectiveDate;
use App\Modules\Inventory\Support\StockValue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The production run: consume THESE inputs (FIFO), produce THIS output batch.
 * The sixth sanctioned stock document (C-MFG-6).
 *
 * ⚠⚠ C-MFG-2: THE RUN NEVER CONVERTS UNITS. Every quantity here is the
 * operator's declared reality in the product's own stock unit. Nothing is
 * computed from a density factor - not the consumption from the output, not
 * the output from the consumption. Where the output product declares its net
 * content, the comparison in yieldCheck() is a FLAG, never a refusal and never
 * an adjustment.
 *
 * ⚠⚠ C-MFG-3: the output batch's unit cost = FIFO-consumed value / output
 * quantity, through the same `ConsumesFifoStock` every other stock-out uses
 * and the same frozen batch costs. Nothing is hand-typed, so the pack's cost
 * of goods sold at sale time traces to the drum's landed cost unbroken.
 *
 * ⚠ C-MFG-4: waste is recorded as a quantity, absorbed as a cost. The value of
 * the waste stays in the output cost by default; the per-run `expense_waste`
 * override routes the waste share to a loss account instead.
 *
 * See MANUFACTURING-PHASE1-PACKING-ARCHITECTURE.md.
 */
class RecordProductionRun
{
    public function __construct(
        private readonly RecordStockIn $recordStockIn,
        private readonly RecordStockOut $recordStockOut,
        private readonly PostProductionRunJournal $posting,
    ) {}

    /**
     * Records the run as a draft. Nothing moves until confirm().
     *
     * @param  array<int, array{product: Product, quantity: string, waste?: string}>  $lines
     * @param  BillOfMaterial|null  $billOfMaterial  C-BOM-2, provenance only: the
     *                                               recipe this run was drafted
     *                                               from. It is COPIED, never
     *                                               read back - editing it later
     *                                               must not change what this run
     *                                               says it consumed.
     *
     * @throws ValidationException
     */
    public function draft(
        Warehouse $warehouse,
        Product $outputProduct,
        string $outputQuantity,
        array $lines,
        User $createdBy,
        mixed $occurredAt = null,
        ?string $outputExpiryDate = null,
        ?string $outputMrp = null,
        bool $expenseWaste = false,
        ?string $note = null,
        ?BillOfMaterial $billOfMaterial = null,
    ): ProductionRun {
        $date = EffectiveDate::resolve($occurredAt);

        if (! $warehouse->is_active) {
            throw ValidationException::withMessages([
                'warehouse_id' => 'That warehouse is no longer active.',
            ]);
        }

        // C-MFG-10: the output must be able to hold stock.
        if (! $outputProduct->type->isStocked()) {
            throw ValidationException::withMessages([
                'output_product_id' => "{$outputProduct->name} is a service and cannot be produced into stock.",
            ]);
        }

        if ($this->scaled($outputQuantity) <= 0) {
            throw ValidationException::withMessages([
                'output_quantity' => 'The output quantity must be greater than zero.',
            ]);
        }

        // ⚠ A recipe for a different product would write a standard quantity
        // that no variance means anything against. Refused rather than ignored.
        if ($billOfMaterial !== null && $billOfMaterial->output_product_id !== $outputProduct->id) {
            throw ValidationException::withMessages([
                'bill_of_material_id' => 'That recipe makes a different product.',
            ]);
        }

        if ($outputMrp !== null && (float) $outputMrp < 0) {
            throw ValidationException::withMessages([
                'output_mrp' => 'An MRP cannot be negative.',
            ]);
        }

        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'A production run needs at least one input line.',
            ]);
        }

        $seen = [];

        foreach ($lines as $index => $line) {
            $product = $line['product'];
            $waste = $line['waste'] ?? '0';

            if (! $product->type->isStocked()) {
                throw ValidationException::withMessages([
                    "lines.{$index}.product_id" => "{$product->name} is a service and cannot be consumed from stock.",
                ]);
            }

            // C-MFG-10: the output must not appear among the inputs. A run that
            // consumes 5 L packs to produce 5 L packs is either a mistake or a
            // repack this phase does not model; refuse it plainly.
            if ($product->id === $outputProduct->id) {
                throw ValidationException::withMessages([
                    "lines.{$index}.product_id" => 'The output product cannot also be an input of the same run.',
                ]);
            }

            if (in_array($product->id, $seen, true)) {
                throw ValidationException::withMessages([
                    "lines.{$index}.product_id" => "{$product->name} appears on two lines; combine them into one.",
                ]);
            }

            $seen[] = $product->id;

            if ($this->scaled($line['quantity']) <= 0) {
                throw ValidationException::withMessages([
                    "lines.{$index}.quantity" => 'A consumed quantity must be greater than zero.',
                ]);
            }

            // ⚠ Waste is PART of the consumed quantity, never in addition to it:
            // 100 kg consumed of which 2 kg spilled. More waste than consumption
            // is a statement about goods that never left the shelf.
            if ($this->scaled($waste) < 0 || $this->scaled($waste) > $this->scaled($line['quantity'])) {
                throw ValidationException::withMessages([
                    "lines.{$index}.waste" => 'Waste cannot be negative or exceed the consumed quantity.',
                ]);
            }
        }

        // C-BOM-3. What the recipe SUGGESTED for this output quantity, worked
        // out here so the suggestion stored on the run can never disagree with
        // the recipe it came from - the screen's pre-fill is a convenience, not
        // the record.
        $standards = $this->standardQuantities($billOfMaterial, $outputQuantity);

        return DB::transaction(function () use ($warehouse, $outputProduct, $outputQuantity, $lines, $createdBy, $date, $outputExpiryDate, $outputMrp, $expenseWaste, $note, $billOfMaterial, $standards) {
            $run = ProductionRun::create([
                'business_id' => $warehouse->business_id,
                'warehouse_id' => $warehouse->id,
                'run_number' => $this->nextNumber($warehouse->business_id, $date),
                'status' => ProductionRunStatus::Draft,
                'output_product_id' => $outputProduct->id,
                'output_quantity' => $outputQuantity,
                // C-BOM-2. Provenance. The version is denormalised so the run
                // stays readable in an export, without a join.
                'bill_of_material_id' => $billOfMaterial?->id,
                'bill_of_material_version' => $billOfMaterial?->version,
                'output_expiry_date' => $outputExpiryDate,
                'output_mrp' => $outputMrp,
                'expense_waste' => $expenseWaste,
                'occurred_at' => $date,
                'note' => $note,
                'created_by' => $createdBy->id,
            ]);

            foreach ($lines as $line) {
                $run->lines()->create([
                    'business_id' => $run->business_id,
                    'product_id' => $line['product']->id,
                    'quantity_consumed' => $line['quantity'],
                    // ⚠ NULL where the recipe never mentioned this component,
                    // which is a different fact from "suggested zero". A line
                    // the operator added by hand is the interesting one.
                    'standard_quantity' => $standards[$line['product']->id] ?? null,
                    'waste_quantity' => $line['waste'] ?? '0',
                ]);
            }

            return $run->fresh(['lines.product', 'warehouse', 'outputProduct']);
        });
    }

    /**
     * component product id → the quantity the recipe suggests for this run.
     *
     * @return array<int, string>
     */
    private function standardQuantities(?BillOfMaterial $billOfMaterial, string $outputQuantity): array
    {
        if ($billOfMaterial === null) {
            return [];
        }

        $standards = [];

        foreach ($billOfMaterial->lines as $line) {
            $standards[$line->component_product_id] = BomSuggestion::forOutput(
                quantityPerOutput: (string) $line->quantity_per_output,
                outputQuantity: $outputQuantity,
                recipeOutputQuantity: (string) $billOfMaterial->output_quantity,
                wastagePercent: (string) $line->wastage_percent,
            );
        }

        return $standards;
    }

    /**
     * C-MFG-8: confirm validates stock sufficiency for every input line,
     * consumes, produces, and (where applicable) posts - one DB transaction,
     * all or nothing. A confirmed run is immutable; the correction path is
     * ReverseProductionRun.
     */
    public function confirm(ProductionRun $run, User $confirmedBy): ProductionRun
    {
        if (! $run->isDraft()) {
            throw new RuntimeException("Production run {$run->run_number} has already been ".$run->status->value.'.');
        }

        // ⚠⚠ Re-checked HERE, not only at draft. A month can close while a draft
        // sits, and posting into a closed period is exactly what the lock
        // prevents - the same rule as ApproveInventoryAdjustment.
        AccountingPeriod::assertOpenFor($run->occurred_at);

        return DB::transaction(function () use ($run, $confirmedBy) {
            // ⚠⚠ Re-read under a lock and checked again. The check above reads
            // the caller's copy, so a double-click passed it twice and consumed
            // the inputs and produced the output twice.
            $current = ProductionRun::whereKey($run->id)->lockForUpdate()->first();

            if ($current === null || ! $current->isDraft()) {
                throw new RuntimeException("Production run {$run->run_number} has already been ".($current?->status->value ?? 'removed').'.');
            }

            $movementIds = [];
            $consumedTotal = Money::zero();
            $wasteTotal = Money::zero();
            $lineValues = [];

            foreach ($run->lines as $line) {
                $movements = $this->recordStockOut->handle(
                    product: $line->product,
                    warehouse: $run->warehouse,
                    quantity: (string) $line->quantity_consumed,
                    performedBy: $confirmedBy,
                    note: "Production run {$run->run_number}",
                    reason: MovementReason::Production,
                    occurredAt: $run->occurred_at,
                );

                $movementIds = array_merge($movementIds, $movements->pluck('id')->all());

                // The line's FIFO-consumed value: each batch portion valued and
                // rounded once, summed - the StockValue rule, applied here too.
                $lineValue = StockValue::sum($movements)->negated();

                // C-MFG-11: the waste share at the line's AVERAGE consumed cost,
                // in exact integer arithmetic. 2 kg of a 100 kg line worth
                // 18,000.00 is 360.00, to the paisa.
                $lineWaste = Money::ofMinor($this->proportionMinor(
                    $lineValue->minor,
                    $this->scaled((string) $line->waste_quantity),
                    $this->scaled((string) $line->quantity_consumed),
                ));

                $consumedTotal = $consumedTotal->plus($lineValue);
                $wasteTotal = $wasteTotal->plus($lineWaste);
                $lineValues[$line->id] = ['consumed' => $lineValue, 'waste' => $lineWaste];
            }

            // C-MFG-3 / C-MFG-4: the whole consumed value flows into the output
            // batch - minus the waste share only when this run expenses it.
            $embedded = $run->expense_waste
                ? Money::ofMinor($consumedTotal->minor - $wasteTotal->minor)
                : $consumedTotal;

            $unitCost = $this->unitCostFor($embedded, (string) $run->output_quantity);

            $outputMovement = $this->recordStockIn->handle(
                product: $run->outputProduct,
                warehouse: $run->warehouse,
                quantity: (string) $run->output_quantity,
                unitCost: $unitCost,
                performedBy: $confirmedBy,
                // C-MFG-9: the batch number defaults to the run number.
                batchNumber: $run->run_number,
                expiryDate: $run->output_expiry_date?->toDateString(),
                note: "Production run {$run->run_number}",
                reason: MovementReason::Production,
                occurredAt: $run->occurred_at,
                manufacturedDate: Carbon::parse($run->occurred_at)->toDateString(),
                mrp: $run->output_mrp !== null ? (string) $run->output_mrp : null,
            );

            $movementIds[] = $outputMovement->id;

            // ⚠ Every movement, tagged in one statement - the adjustment engine's
            // lesson about remembering only the first of three cost layers.
            StockMovement::whereIn('id', $movementIds)->update(['production_run_id' => $run->id]);

            foreach ($run->lines as $line) {
                $line->update([
                    'consumed_value' => $lineValues[$line->id]['consumed']->toTaka(),
                    'waste_value' => $lineValues[$line->id]['waste']->toTaka(),
                ]);
            }

            $run->update([
                'status' => ProductionRunStatus::Confirmed,
                'output_batch_id' => $outputMovement->batch_id,
                'consumed_value' => $consumedTotal->toTaka(),
                'waste_value' => $wasteTotal->toTaka(),
                'yield_check' => $this->yieldCheck($run),
                'confirmed_by' => $confirmedBy->id,
                'confirmed_at' => Carbon::now(),
            ]);

            // ⚠⚠ In the same transaction as the movements (C-MFG-5). Fully
            // absorbed, this posts NOTHING - correctly; expensed waste posts
            // Dr wastage / Cr Stock; a paisa the 4dp unit cost cannot carry
            // posts to Rounding Differences rather than silently breaking the
            // subledger reconciliation.
            $this->posting->handle($run->fresh(['movements', 'outputProduct']), $confirmedBy);

            return $run->fresh(['lines.product', 'movements', 'outputBatch', 'outputProduct']);
        });
    }

    /**
     * C-MFG-2's reasonableness check, from declared data only and flag-only.
     *
     * The output product may declare its net content per stock unit (a 5 L pack
     * contains 5 of "Litre", C-MFG-14). Input lines stocked in that same unit
     * are then comparable WITHOUT any conversion: expected content of the run's
     * output vs what those lines consumed net of recorded waste. The flag means
     * "consumption those two declared facts do not explain" - a drifting yield,
     * an unrecorded spill, or a wrong entry. Never a refusal (D-MFG-5).
     *
     * @return array<string, mixed>
     */
    private function yieldCheck(ProductionRun $run): array
    {
        $product = $run->outputProduct;

        if ($product->content_quantity === null || $product->content_unit_id === null) {
            return ['checked' => false, 'reason' => 'no_declared_content'];
        }

        $candidates = $run->lines->filter(
            fn ($line) => $line->product->unit_of_measure_id === $product->content_unit_id
        );

        if ($candidates->isEmpty()) {
            return [
                'checked' => false,
                'reason' => 'no_input_in_content_unit',
                'content_unit' => $product->contentUnit?->abbreviation,
            ];
        }

        // Scaled x10^8: a 4dp quantity times a 4dp content, exactly.
        $expected = $this->scaled((string) $run->output_quantity) * $this->scaled((string) $product->content_quantity);
        $actual = $candidates->sum(
            fn ($line) => ($this->scaled((string) $line->quantity_consumed) - $this->scaled((string) $line->waste_quantity)) * 10000
        );

        $tolerance = (float) InventorySetting::forCurrentBusiness()->productionYieldTolerancePercent();
        // ⚠ Advisory arithmetic for an advisory flag: the deviation decides a
        // warning, never money and never a refusal, so float is honest here.
        $deviation = $expected > 0 ? (($actual - $expected) / $expected) * 100 : 0.0;

        return [
            'checked' => true,
            'content_unit' => $product->contentUnit?->abbreviation,
            'expected_content' => $this->unscaled8($expected),
            'net_consumed' => $this->unscaled8((int) $actual),
            'deviation_percent' => round($deviation, 2),
            'tolerance_percent' => $tolerance,
            'flagged' => abs($deviation) > $tolerance,
        ];
    }

    /**
     * The output batch's unit cost at four decimal places: embedded value over
     * output quantity, in integer arithmetic, rounded half away from zero once.
     */
    private function unitCostFor(Money $embedded, string $outputQuantity): string
    {
        $quantityScaled = $this->scaled($outputQuantity);

        // minor (paisa) * 10^6 / (qty * 10^4) = taka * 10^4.
        $scaled = $this->proportionMinor($embedded->minor, 1000000, $quantityScaled);

        return number_format($scaled / 10000, 4, '.', '');
    }

    /**
     * round(minor * numerator / denominator), half away from zero, in integers.
     * The float version loses paisa above 2^53, which a large run can reach.
     */
    private function proportionMinor(int $minor, int $numerator, int $denominator): int
    {
        if ($denominator === 0 || $numerator === 0 || $minor === 0) {
            return 0;
        }

        $product = $minor * $numerator;
        $negative = $product < 0;
        $product = abs($product);

        $result = intdiv($product, $denominator);

        if (($product % $denominator) * 2 >= $denominator) {
            $result++;
        }

        return $negative ? -$result : $result;
    }

    private function nextNumber(int $businessId, Carbon $date): string
    {
        $business = Business::withoutGlobalScopes()->findOrFail($businessId);
        $prefix = 'PRN-'.FiscalYear::containing($date, $business)->label().'-';

        $latest = ProductionRun::query()
            ->where('run_number', 'like', $prefix.'%')
            ->orderByDesc('run_number')
            ->value('run_number');

        $next = $latest === null ? 1 : ((int) substr($latest, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function scaled(string $quantity): int
    {
        return (int) round(((float) $quantity) * 10000);
    }

    private function unscaled8(int $scaled): string
    {
        return number_format($scaled / 100000000, 4, '.', '');
    }
}
