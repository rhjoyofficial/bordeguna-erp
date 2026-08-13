<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Accounting\Support\Money;
use App\Modules\Inventory\Models\OpeningStock;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\OpeningStockImportPlan;
use Illuminate\Support\Carbon;

/**
 * Works out exactly what an opening stock file would do, and writes nothing.
 *
 * ⚠⚠ RE-DERIVED FROM THE LIVE DATABASE EVERY TIME IT RUNS. Joy's rule against
 * stale mappings: the plan is never cached, never carried between sessions, and
 * is recomputed against production at the moment of the final preview. A plan
 * built yesterday describes yesterday's catalogue.
 *
 * C-OPN-1  A warehouse that ALREADY has opening stock is an ERROR, and the whole
 *          file is refused. The engine's own guard would catch it, but only
 *          after the earlier warehouses had been written - and the one thing
 *          worse than no opening stock is opening stock for three warehouses
 *          out of four, because the document cannot be written again.
 * C-OPN-2  Matching on SKU and WAREHOUSE CODE, both ASCII. Names are shown for
 *          human verification and never matched on.
 * C-OPN-3  The same product twice in the same warehouse is an ERROR unless the
 *          rows carry different batch numbers. Two anonymous lines for one
 *          product are a double count, and a double count in opening stock
 *          balances perfectly: it debits stock and credits equity twice, just as
 *          correctly as once.
 * C-OPN-4  `unit` is checked against the product's unit of measure when the file
 *          supplies it. A count sheet reading "kg" against a product held in
 *          pieces is the mistake this column exists to catch.
 * C-OPN-5  A zero unit cost is allowed but WARNED. Donated or sampled goods are
 *          real; a whole file of them is a missing column.
 */
class PlanOpeningStockImport
{
    public function handle(array $rows, Carbon $asAt): OpeningStockImportPlan
    {
        $plan = new OpeningStockImportPlan;

        $products = Product::query()->with('unitOfMeasure')->get()->keyBy(fn (Product $p) => strtoupper($p->sku));
        $warehouses = Warehouse::query()->get()->keyBy(fn (Warehouse $w) => strtoupper((string) $w->code));

        // C-OPN-1. Read ONCE, up front, from the live database.
        $alreadyRecorded = OpeningStock::query()->pluck('warehouse_id')->flip();

        $seen = [];

        foreach ($rows as $row) {
            $line = $row['line'];
            $values = $row['values'];

            $sku = strtoupper($values['sku'] ?? '');
            $warehouseCode = strtoupper($values['warehouse'] ?? '');

            if ($sku === '') {
                $plan->addError($line, '', 'The row has no SKU.');

                continue;
            }

            $product = $products[$sku] ?? null;

            if ($product === null) {
                $plan->addError($line, $sku, 'No product with this SKU exists in this business. An opening stock file never creates products.');

                continue;
            }

            if (! $product->is_active) {
                $plan->addError($line, $sku, 'Product is inactive. Counting stock of a product excluded from go-live is a contradiction, not a warning.');

                continue;
            }

            if (! $product->type->isStocked()) {
                $plan->addError($line, $sku, "{$product->name} is a service and cannot hold stock.");

                continue;
            }

            $warehouse = $warehouses[$warehouseCode] ?? null;

            if ($warehouse === null) {
                $plan->addError($line, $sku, "No warehouse with code '{$warehouseCode}' exists in this business.");

                continue;
            }

            if (! $warehouse->is_active) {
                $plan->addError($line, $sku, "Warehouse {$warehouse->name} is no longer active.");

                continue;
            }

            // C-OPN-1, per row so the message names the warehouse.
            if ($alreadyRecorded->has($warehouse->id)) {
                $plan->addError($line, $sku, "Opening stock has ALREADY been recorded for {$warehouse->name}. It is written once per warehouse, ever; correct it with an inventory adjustment instead.");

                continue;
            }

            // C-OPN-4.
            $unit = $values['unit'] ?? '';
            $expected = (string) $product->unitOfMeasure?->abbreviation;

            if ($unit !== '' && $expected !== '' && strcasecmp($unit, $expected) !== 0) {
                $plan->addError($line, $sku, "The count sheet says '{$unit}' but {$product->name} is held in '{$expected}'. A quantity in the wrong unit is not a rounding difference.");

                continue;
            }

            $quantity = $values['quantity'] ?? '';

            if (! is_numeric($quantity) || (float) $quantity <= 0) {
                $plan->addError($line, $sku, "quantity is '{$quantity}'. An opening stock line must count more than zero.");

                continue;
            }

            $unitCost = $values['unit_cost'] ?? '';

            if (! is_numeric($unitCost) || (float) $unitCost < 0) {
                $plan->addError($line, $sku, "unit_cost is '{$unitCost}'. It must be a number of zero or more, and it is never guessed.");

                continue;
            }

            // C-OPN-5.
            if ((float) $unitCost === 0.0) {
                $plan->addWarning($line, $sku, 'Unit cost is zero. Real for donated or sampled goods; wrong if the cost was simply not supplied.');
            }

            $batchNumber = ($values['batch_number'] ?? '') !== '' ? $values['batch_number'] : null;

            // C-OPN-3.
            $key = $warehouse->id.'|'.$product->id.'|'.($batchNumber ?? '');

            if (isset($seen[$key])) {
                $plan->addError($line, $sku, "This product is already counted for {$warehouse->name} on line {$seen[$key]}. Give the rows different batch numbers if they are genuinely separate layers.");

                continue;
            }

            $seen[$key] = $line;

            $expiry = ($values['expiry_date'] ?? '') !== '' ? $values['expiry_date'] : null;

            if ($expiry !== null && ! $this->isDate($expiry)) {
                $plan->addError($line, $sku, "expiry_date is '{$expiry}'. Use YYYY-MM-DD.");

                continue;
            }

            $plan->warehouses[$warehouse->id] ??= [
                'warehouseId' => $warehouse->id,
                'warehouseName' => $warehouse->name,
                'warehouseCode' => (string) $warehouse->code,
                'lines' => [],
            ];

            $plan->warehouses[$warehouse->id]['lines'][] = [
                'line' => $line,
                'sku' => $sku,
                'productId' => $product->id,
                'productName' => $product->name,
                'quantity' => $this->scale($quantity),
                'unitCost' => $this->scale($unitCost),
                // ⚠ Through Money, per line, rounded once - the same rule the
                // posting engine follows. A total assembled any other way can
                // differ from the journal by paisa, and the accountant's opening
                // trial balance has to match this figure exactly.
                'value' => Money::ofProduct($this->scale($quantity), $this->scale($unitCost)),
                'batchNumber' => $batchNumber,
                'expiryDate' => $expiry,
            ];
        }

        return $plan;
    }

    private function isDate(string $value): bool
    {
        try {
            Carbon::createFromFormat('Y-m-d', $value);

            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    private function scale(string $value): string
    {
        return number_format((float) $value, 4, '.', '');
    }
}
