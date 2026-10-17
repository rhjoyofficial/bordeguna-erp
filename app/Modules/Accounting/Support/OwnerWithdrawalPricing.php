<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\OwnerWithdrawalLine;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Support\ProductPricing;
use Illuminate\Support\Carbon;

/**
 * The two prices an owner withdrawal line carries (Phase 2, 17 Oct 2026).
 *
 *  - COST: the unit cost of the FIFO batch that will leave. Frozen at
 *    approval from the reserved batch (AuthoriseOwnerWithdrawal). Before
 *    approval the screen shows an ESTIMATE from the batches free today,
 *    labelled as such (ruling Q1: an estimate is never shown as the price).
 *  - REFERENCE: the product's MRP, else the selling price in force (today's
 *    confirmed price for a market product), else none. Snapshotted beside
 *    the cost and the difference kept per line for the tax adviser (D8).
 *    Evidence only: nothing is booked at the reference price, and the ERP
 *    never calls it a sale.
 *
 * ⚠ ONE definition with the staff purchase: `ProductPricing::capReference`
 * (builder G's `pricing_mode` decides; a market product never falls back to
 * its MRP). This only maps its label onto the line's `reference_source`.
 */
final class OwnerWithdrawalPricing
{
    /**
     * @return array{source: string, price: ?string}
     */
    public function reference(Product $product, string $quantity, Carbon $on): array
    {
        $reference = ProductPricing::capReference($product, $on);

        if ($reference === null) {
            return ['source' => OwnerWithdrawalLine::REFERENCE_NONE, 'price' => null];
        }

        return [
            'source' => $reference['label'] === ProductPricing::LABEL_MRP ? OwnerWithdrawalLine::REFERENCE_MRP : OwnerWithdrawalLine::REFERENCE_SELLING_PRICE,
            'price' => number_format((float) $reference['amount'], 4, '.', ''),
        ];
    }

    /**
     * What approval WOULD reserve today: FIFO portions of the batches free in
     * the warehouse (balance less open holds), each with its cost. Read-only;
     * no locks. `short` is what nothing on the shelf can cover.
     *
     * @return array{portions: list<array{batch_id: int, batch_number: ?string, quantity: string, unit_cost: string, cost_paisa: int}>, cost_paisa: int, short: string}
     */
    public function estimate(Product $product, Warehouse $warehouse, string $quantity): array
    {
        $remaining = self::scale($quantity);
        $portions = [];
        $costPaisa = 0;

        $batches = StockBatch::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->consumable()
            ->get();

        $held = StockReservation::liveByBatch($batches->pluck('id')->all());

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $free = self::scale((string) $batch->current_quantity) - self::scale($held[$batch->id] ?? '0');

            if ($free <= 0) {
                continue;
            }

            $take = min($remaining, $free);
            $portionQuantity = self::unscale($take);
            $portionCost = Money::ofProduct($portionQuantity, (string) $batch->unit_cost)->minor;

            $portions[] = [
                'batch_id' => (int) $batch->id,
                'batch_number' => $batch->batch_number,
                'quantity' => $portionQuantity,
                'unit_cost' => (string) $batch->unit_cost,
                'cost_paisa' => $portionCost,
            ];
            $costPaisa += $portionCost;
            $remaining -= $take;
        }

        return ['portions' => $portions, 'cost_paisa' => $costPaisa, 'short' => self::unscale(max(0, $remaining))];
    }

    public static function scale(string $quantity): int
    {
        return (int) round(((float) $quantity) * 10000);
    }

    public static function unscale(int $scaled): string
    {
        return number_format($scaled / 10000, 4, '.', '');
    }
}
