<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Enums\ProductionCostKind;
use App\Modules\Inventory\Enums\ProductionRunStatus;
use App\Modules\Inventory\Exceptions\UnitNotConvertibleException;
use App\Modules\Inventory\Models\BillOfMaterial;
use App\Modules\Inventory\Models\InventorySetting;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductionRun;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\BatchNumbers;
use App\Modules\Inventory\Support\BomSuggestion;
use App\Modules\Inventory\Support\EffectiveDate;
use App\Modules\Inventory\Support\MaxMakeable;
use App\Modules\Inventory\Support\StockValue;
use App\Modules\Inventory\Support\UnitConverter;
use App\Support\DocumentNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The production run: consume THESE inputs (FIFO), produce THIS output batch.
 * The sixth sanctioned stock document (C-MFG-6).
 *
 * ⚠⚠ C-MFG-2: THE RUN NEVER CONVERTS UNITS AT CONFIRM. Every quantity FIFO
 * consumes is in the product's own stock unit. Nothing is computed from a
 * density factor - not the consumption from the output, not the output from
 * the consumption. Where the output product declares its net content, the
 * comparison in yieldCheck() is a FLAG, never a refusal and never an
 * adjustment.
 *
 * ⭐ 17 Oct 2026 (Phase 1 Part B): a line MAY be typed in another unit at
 * draft (500 g on a component stocked in kg). UnitConverter turns it into the
 * stock unit ONCE, at draft, through the global metric factors or the
 * product's own sourced row, and the line FREEZES that factor beside the typed
 * figure. confirm() refuses a line whose factor was never validated.
 *
 * ⚠⚠ C-MFG-3: the output batch's unit cost = FIFO-consumed value / output
 * quantity, through the same `ConsumesFifoStock` every other stock-out uses
 * and the same frozen batch costs. Nothing is hand-typed, so the pack's cost
 * of goods sold at sale time traces to the drum's landed cost unbroken.
 *
 * ⭐ PR3 (18 Oct 2026, Tally's "additional cost"): a run may carry cost lines
 * besides its materials (labour, overhead, packaging labour, other), each an
 * amount and the expense head it comes from. They join the output batch's
 * cost at confirm; PostProductionRunJournal moves them Dr Stock / Cr the head.
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
        private readonly UnitConverter $converter,
    ) {}

    /**
     * Records the run as a draft. Nothing moves until confirm().
     *
     * @param  array<int, array{product: Product, quantity: string, waste?: string, unit?: ?UnitOfMeasure}>  $lines  quantity and waste in `unit` (default: the product's stock unit)
     * @param  array<int, array{kind: ProductionCostKind|string, account: ?Account, amount: string, note?: ?string}>  $costs  PR3: amounts in taka
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
        array $costs = [],
    ): ProductionRun {
        // ⚠ The screen sends a bare date. As midnight it refused every input
        // received earlier today, so today means now and a past day its end.
        $date = EffectiveDate::resolveConsumingDay($occurredAt);

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

        // ⚠⚠ 17 Oct 2026: the goods-receipt and opening-stock rule at the third
        // door stock comes in by. An output that tracks expiry made without an
        // expiry date is a batch the Expiry screen, the nightly write-off sweep
        // and FEFO cannot see. The batch number is the system's own here, so
        // only the date is asked for.
        // ⭐ PR2 (18 Oct 2026): blank, it defaults to the run date plus the
        // product's shelf life when the product states one.
        if ($outputExpiryDate === null || trim($outputExpiryDate) === '') {
            $outputExpiryDate = BatchNumbers::defaultExpiry($outputProduct, Carbon::parse($date)->toDateString());
        }

        if ($outputProduct->tracks_expiry && ($outputExpiryDate === null || trim($outputExpiryDate) === '')) {
            throw ValidationException::withMessages([
                'output_expiry_date' => "{$outputProduct->name} tracks expiry: type the expiry date that will go on the label.",
            ]);
        }

        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'A production run needs at least one input line.',
            ]);
        }

        $seen = [];
        $normalized = [];

        foreach ($lines as $index => $line) {
            $product = $line['product'];
            $waste = $line['waste'] ?? '0';
            $unit = $line['unit'] ?? null;

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

            // ⚠ Into the stock unit ONCE, here, with the factor frozen on the
            // line. A unit the ERP cannot convert, a figure that rounds to
            // zero, or half a jar on a whole-count product is refused by name.
            try {
                $result = $this->converter->toStockUnit($product, (string) $line['quantity'], $unit, Carbon::parse($date));
            } catch (UnitNotConvertibleException $e) {
                throw ValidationException::withMessages(["lines.{$index}.quantity" => $e->getMessage()]);
            }

            // ⚠ Waste is PART of the consumed quantity, never in addition to it:
            // 100 kg consumed of which 2 kg spilled. More waste than consumption
            // is a statement about goods that never left the shelf. Typed in the
            // same unit as the quantity, so it takes the same frozen factor.
            $wasteInStock = $this->scaled($waste) < 0 ? '-1' : UnitConverter::apply($waste, $result->factor);

            if ($this->scaled($wasteInStock) < 0 || $this->scaled($wasteInStock) > $this->scaled($result->quantity)) {
                throw ValidationException::withMessages([
                    "lines.{$index}.waste" => 'Waste cannot be negative or exceed the consumed quantity.',
                ]);
            }

            $normalized[$index] = ['result' => $result, 'waste' => $wasteInStock];
        }

        $costRows = $this->normalizedCosts($costs);

        // C-BOM-3. What the recipe SUGGESTED for this output quantity, worked
        // out here so the suggestion stored on the run can never disagree with
        // the recipe it came from - the screen's pre-fill is a convenience, not
        // the record.
        $standards = $this->standardQuantities($billOfMaterial, $outputQuantity);

        return DB::transaction(function () use ($warehouse, $outputProduct, $outputQuantity, $lines, $createdBy, $date, $outputExpiryDate, $outputMrp, $expenseWaste, $note, $billOfMaterial, $standards, $normalized, $costRows) {
            $run = DocumentNumber::claim('run_number', fn () => $this->nextNumber($warehouse->business_id, $date), fn (string $number) => ProductionRun::create([
                'business_id' => $warehouse->business_id,
                'warehouse_id' => $warehouse->id,
                'run_number' => $number,
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
            ]));

            foreach ($lines as $index => $line) {
                $result = $normalized[$index]['result'];

                $run->lines()->create([
                    'business_id' => $run->business_id,
                    'product_id' => $line['product']->id,
                    'quantity_consumed' => $result->quantity,
                    // What was typed, and the factor that turned it into the
                    // stock figure above. Frozen (C-UOM-4): never looked up again.
                    'entered_quantity' => $result->enteredQuantity,
                    'entered_unit_id' => $result->enteredUnitId,
                    'conversion_factor' => $result->factor,
                    // ⚠ NULL where the recipe never mentioned this component,
                    // which is a different fact from "suggested zero". A line
                    // the operator added by hand is the interesting one.
                    'standard_quantity' => $standards[$line['product']->id] ?? null,
                    'waste_quantity' => $normalized[$index]['waste'],
                ]);
            }

            foreach ($costRows as $row) {
                $run->costs()->create(['business_id' => $run->business_id] + $row);
            }

            return $run->fresh(['lines.product', 'warehouse', 'outputProduct', 'costs']);
        });
    }

    /**
     * PR3. Each cost line checked and turned into a row: a known kind, an
     * active postable EXPENSE head (the chart is installation-wide), and an
     * amount in whole paisa above zero.
     *
     * @param  array<int, array<string, mixed>>  $costs
     * @return list<array{kind: string, account_id: int, amount_paisa: int, note: ?string}>
     */
    private function normalizedCosts(array $costs): array
    {
        $rows = [];

        foreach (array_values($costs) as $index => $cost) {
            $kind = ($cost['kind'] ?? null) instanceof ProductionCostKind
                ? $cost['kind']
                : ProductionCostKind::tryFrom((string) ($cost['kind'] ?? ''));

            if ($kind === null) {
                throw ValidationException::withMessages(["costs.{$index}.kind" => 'Choose what this cost is for.']);
            }

            $account = $cost['account'] ?? null;

            if (! $account instanceof Account || $account->type !== AccountType::Expense || ! $account->is_postable || ! $account->is_active) {
                throw ValidationException::withMessages(["costs.{$index}.account_id" => 'Choose an expense head that takes entries.']);
            }

            try {
                $amount = Money::ofTaka((string) ($cost['amount'] ?? ''));
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(["costs.{$index}.amount" => 'Type the amount in taka.']);
            }

            if ($amount->minor <= 0) {
                throw ValidationException::withMessages(["costs.{$index}.amount" => 'A cost must be more than zero.']);
            }

            $note = trim((string) ($cost['note'] ?? ''));

            $rows[] = [
                'kind' => $kind->value,
                'account_id' => (int) $account->id,
                'amount_paisa' => $amount->minor,
                'note' => $note === '' ? null : $note,
            ];
        }

        return $rows;
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

        $billOfMaterial->loadMissing('lines.componentProduct.unitOfMeasure');

        foreach ($billOfMaterial->lines as $line) {
            $standards[$line->component_product_id] = BomSuggestion::forOutput(
                quantityPerOutput: (string) $line->quantity_per_output,
                outputQuantity: $outputQuantity,
                recipeOutputQuantity: (string) $billOfMaterial->output_quantity,
                wastagePercent: (string) $line->wastage_percent,
                // PR3: whole jars, the same rounding the screen fills with.
                wholeUnits: $line->componentProduct !== null && MaxMakeable::wholeOnly($line->componentProduct),
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
                // ⚠⚠ REPLY-1: "never let new stock or production operations use
                // an unvalidated conversion factor". The factor was frozen at
                // draft by UnitConverter; a line without one (a row written
                // around draft(), or edited by hand) does not consume stock.
                $this->assertFactorValidated($line, $run->occurred_at);

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

            // ⭐ PR3: labour, overhead and the rest join the batch cost here.
            $additional = Money::sum($run->costs()->get()->map(fn ($cost) => $cost->amount())->all());
            $embedded = $embedded->plus($additional);

            $unitCost = $this->unitCostFor($embedded, (string) $run->output_quantity);

            $outputMovement = $this->recordStockIn->handle(
                product: $run->outputProduct,
                warehouse: $run->warehouse,
                quantity: (string) $run->output_quantity,
                unitCost: $unitCost,
                performedBy: $confirmedBy,
                // C-MFG-9, PR2 (18 Oct 2026): the batch number is the shared
                // automatic one (SKU-YYMMDD-seq); the run number stays on the
                // run and the movement note, and the trace links the two.
                batchNumber: BatchNumbers::next($run->outputProduct, $run->occurred_at),
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
                // PR3: the breakdown, stored. Null = a run without cost lines.
                'additional_cost_value' => $additional->isZero() ? null : $additional->toTaka(),
                'output_value' => $embedded->toTaka(),
                'yield_check' => $this->yieldCheck($run),
                'confirmed_by' => $confirmedBy->id,
                'confirmed_at' => Carbon::now(),
            ]);

            // ⚠⚠ In the same transaction as the movements (C-MFG-5). Fully
            // absorbed, this posts NOTHING - correctly; expensed waste posts
            // Dr wastage / Cr Stock; a paisa the 4dp unit cost cannot carry
            // posts to Rounding Differences rather than silently breaking the
            // subledger reconciliation.
            $this->posting->handle($run->fresh(['movements', 'outputProduct', 'costs.account']), $confirmedBy);

            return $run->fresh(['lines.product', 'movements', 'outputBatch', 'outputProduct']);
        });
    }

    /**
     * A line's frozen factor must exist, be positive, and (where the typed
     * unit is not the stock unit) still name a path the ERP knows: the global
     * metric factor, or a product row in force on the run's date. The VALUE
     * stays the frozen one even if the row changed since (C-UOM-4).
     *
     * @throws UnitNotConvertibleException
     */
    private function assertFactorValidated($line, mixed $on): void
    {
        $product = $line->product;

        if ($line->conversion_factor === null || bccomp((string) $line->conversion_factor, '0', 8) <= 0 || $line->entered_unit_id === null) {
            throw UnitNotConvertibleException::unvalidatedFactor($product);
        }

        if ((int) $line->entered_unit_id === (int) $product->unit_of_measure_id) {
            return;
        }

        $product->loadMissing('unitOfMeasure');
        $line->loadMissing('enteredUnit');

        // Throws UnitNotConvertibleException::noPath when nothing converts.
        $this->converter->factor($product, $line->enteredUnit, $product->unitOfMeasure, Carbon::parse($on));
    }

    /**
     * C-MFG-2's reasonableness check, from declared data only and flag-only.
     *
     * The output product may declare its net content per stock unit (a 5 L pack
     * contains 5 of "Litre", C-MFG-14). Input lines stocked in that same unit
     * are then comparable: expected content of the run's output vs what those
     * lines consumed net of recorded waste. The flag means "consumption those
     * two declared facts do not explain" - a drifting yield, an unrecorded
     * spill, or a wrong entry. Never a refusal (D-MFG-5).
     *
     * ⚠ 17 Oct 2026: an input in another unit of the SAME dimension is
     * comparable too: a 500 g jar filled from bulk stocked in kg, a 500 mL
     * bottle from a drum in L. Those are the GLOBAL base factors on the units
     * (UnitConverter::baseFactor, Phase 1 Part B), not a measured density, so
     * kg against L stays not comparable, as before, and so does any count
     * unit against anything.
     *
     * @return array<string, mixed>
     */
    private function yieldCheck(ProductionRun $run): array
    {
        $product = $run->outputProduct;

        if ($product->content_quantity === null || $product->content_unit_id === null) {
            return ['checked' => false, 'reason' => 'no_declared_content'];
        }

        $content = UnitConverter::baseFactor($product->contentUnit);
        $contentFactor = (int) ($content['factor'] ?? 1);

        // Base units (g or mL) in one of the line's stock unit, or null when
        // the line cannot be compared with the declared content at all.
        $factorFor = function ($line) use ($product, $content, $contentFactor): ?int {
            if ($line->product->unit_of_measure_id === $product->content_unit_id) {
                return $contentFactor;
            }

            $unit = UnitConverter::baseFactor($line->product->unitOfMeasure);

            return ($content !== null && $unit !== null && $unit['dimension'] === $content['dimension'])
                ? (int) $unit['factor']
                : null;
        };

        $candidates = $run->lines->filter(fn ($line) => $factorFor($line) !== null);

        if ($candidates->isEmpty()) {
            return [
                'checked' => false,
                'reason' => 'no_input_in_content_unit',
                'content_unit' => $product->contentUnit?->abbreviation,
            ];
        }

        // Scaled x10^8 and in base units (g or mL, or the content unit itself
        // when it is neither): a 4dp quantity times a 4dp content times the
        // unit's size, exactly, in integers.
        $expected = $this->scaled((string) $run->output_quantity) * $this->scaled((string) $product->content_quantity) * $contentFactor;
        $actual = $candidates->sum(
            fn ($line) => ($this->scaled((string) $line->quantity_consumed) - $this->scaled((string) $line->waste_quantity)) * 10000 * $factorFor($line)
        );

        // The units read into the content unit, so the screen can say so.
        $convertedFrom = $candidates
            ->filter(fn ($line) => $line->product->unit_of_measure_id !== $product->content_unit_id)
            ->map(fn ($line) => $line->product->unitOfMeasure?->abbreviation)
            ->filter()->unique()->values()->all();

        $tolerance = (float) InventorySetting::forCurrentBusiness()->productionYieldTolerancePercent();
        // ⚠ Advisory arithmetic for an advisory flag: the deviation decides a
        // warning, never money and never a refusal, so float is honest here.
        $deviation = $expected > 0 ? (($actual - $expected) / $expected) * 100 : 0.0;

        return [
            'checked' => true,
            'content_unit' => $product->contentUnit?->abbreviation,
            // Reported in the content unit, as before.
            'expected_content' => $this->unscaled8(intdiv($expected, $contentFactor)),
            'net_consumed' => $this->unscaled8((int) round($actual / $contentFactor)),
            'converted_from' => $convertedFrom,
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

        // ⚠ Numeric, not text order (14 Oct 2026, SAL-001): see DocumentNumber.
        return DocumentNumber::next(ProductionRun::query(), 'run_number', $prefix);
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
