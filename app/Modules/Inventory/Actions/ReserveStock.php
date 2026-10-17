<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Models\Warehouse;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Holds FIFO stock for an approved document (Phase 2, 17 Oct 2026, ruling Q1).
 *
 * Walks the consumable batches of the product in the warehouse in the SAME
 * order consumeFifo issues them, takes what each has free (its balance less
 * the holds already open on it), and writes one reservation per batch
 * touched. All or nothing: short stock throws before anything is written,
 * and the batches are row-locked so two approvals cannot hold the same unit.
 *
 * ⚠ The reservation names the batch so the approved price can be the cost of
 * the batch that really leaves (no "last cost" guess, no average: ruling Q1).
 * A quantity that spans two batches gets two reservations, each with its own
 * cost; the caller decides how to show that (the owner withdrawal splits the
 * line).
 *
 * ⚠ COORDINATED between builders E and F: same path, same signature.
 *
 * @return Collection<int, StockReservation>
 */
class ReserveStock
{
    public function handle(
        Product $product,
        Warehouse $warehouse,
        string $quantity,
        string $reason,
        Model $reference,
        ?User $by = null,
    ): Collection {
        if (! $product->type->isStocked()) {
            throw new DomainException("Product #{$product->id} is a service and cannot hold stock.");
        }

        return DB::transaction(function () use ($product, $warehouse, $quantity, $reason, $reference, $by) {
            $remaining = $this->scale($quantity);

            if ($remaining <= 0) {
                throw new DomainException('A reservation needs a quantity above zero.');
            }

            $batches = StockBatch::query()
                ->where('product_id', $product->id)
                ->where('warehouse_id', $warehouse->id)
                ->consumable()
                ->lockForUpdate()
                ->get();

            $held = StockReservation::heldByBatch($batches->pluck('id'));
            $portions = [];

            foreach ($batches as $batch) {
                if ($remaining <= 0) {
                    break;
                }

                $free = $this->scale((string) $batch->current_quantity) - $this->scale($held[$batch->id] ?? '0');

                if ($free <= 0) {
                    continue;
                }

                $take = min($remaining, $free);
                $portions[] = ['batch' => $batch, 'quantity' => $this->unscale($take)];
                $remaining -= $take;
            }

            if ($remaining > 0) {
                $available = $this->scale($quantity) - $remaining;

                $heldQuantity = (float) StockBatch::query()
                    ->where('product_id', $product->id)
                    ->where('warehouse_id', $warehouse->id)
                    ->where('status', StockBatchStatus::Active)
                    ->whereNotNull('held_at')
                    ->sum('current_quantity');

                if ($heldQuantity > 0) {
                    throw InsufficientStockException::heldStock($product->name, $warehouse->name, (float) $quantity, (float) $this->unscale($available), $heldQuantity);
                }

                throw InsufficientStockException::forProductInWarehouse($product->id, $warehouse->id, (float) $quantity, (float) $this->unscale($available));
            }

            return collect($portions)->map(fn (array $portion) => StockReservation::create([
                'business_id' => $portion['batch']->business_id,
                'stock_batch_id' => $portion['batch']->id,
                'quantity' => $portion['quantity'],
                'reason' => $reason,
                'reference_type' => $reference->getMorphClass(),
                'reference_id' => $reference->getKey(),
                'created_by' => $by?->id,
            ]));
        });
    }

    private function scale(string $quantity): int
    {
        return (int) round(((float) $quantity) * 10000);
    }

    private function unscale(int $scaled): string
    {
        return number_format($scaled / 10000, 4, '.', '');
    }
}
