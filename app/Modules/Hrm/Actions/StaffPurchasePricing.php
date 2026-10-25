<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Hrm\Exceptions\StaffPurchaseNeedsReapproval;
use App\Modules\Hrm\Models\StaffPurchaseLine;
use App\Modules\Hrm\Models\StaffPurchaseRequest;
use App\Modules\Hrm\Support\StaffPurchasePolicy;
use App\Modules\Inventory\Actions\ReserveStock;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Models\Warehouse;

/**
 * ⭐ THE STAFF PRICE IS A STRATEGY, NOT A TYPED ROW (ChatGPT ruling Q1,
 * Phase 2, 17 Oct 2026).
 *
 * At APPROVAL this reserves the FIFO batch slice(s) that will be issued from
 * the approved warehouse and freezes on the line:
 *   staff_price        = that batch's unit cost (bought goods: the GRN cost
 *                        including landed cost already capitalised on it;
 *                        made goods: the production output batch cost, D4),
 *   cost_basis_batch_id, cost_snapshot (every slice, its cost, when).
 * Before approval a screen may show an ESTIMATE from the current FIFO head,
 * labelled as such. No last-cost fallback, no average, ever.
 *
 * At HANDOVER `assertReservationsHold` re-reads every reserved slice: gone,
 * held, expired, short or repriced means the request goes back for
 * re-approval with the new price, never a silent recompute.
 *
 * A line priced at the REFERENCE price (the month's second unit of a SKU,
 * rule 3) still reserves its stock, so availability is honest; its price is
 * the frozen cap reference, not a cost.
 */
class StaffPurchasePricing
{
    public function __construct(private readonly ReserveStock $reserve) {}

    /**
     * The estimated staff price: the cost of the FIFO head batch that is not
     * held for somebody else. Null when nothing can be issued.
     *
     * @return array{amount: string, batch_id: int}|null
     */
    public function estimate(Product $product, Warehouse $warehouse): ?array
    {
        $batches = StockBatch::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->consumable()
            ->get(['stock_batches.id', 'stock_batches.unit_cost', 'stock_batches.current_quantity']);

        $held = StockReservation::liveByBatch($batches->pluck('id')->all());

        foreach ($batches as $batch) {
            if ((float) $batch->current_quantity - (float) ($held[$batch->id] ?? '0') > 0) {
                return ['amount' => number_format((float) $batch->unit_cost, 2, '.', ''), 'batch_id' => (int) $batch->id];
            }
        }

        return null;
    }

    /**
     * Estimates for many products in two queries (the self-service list).
     *
     * @param  array<int, int>  $productIds
     * @return array<int, string> product id => estimated staff price
     */
    public function estimateMany(array $productIds, Warehouse $warehouse): array
    {
        if ($productIds === []) {
            return [];
        }

        $batches = StockBatch::query()
            ->whereIn('product_id', $productIds)
            ->where('warehouse_id', $warehouse->id)
            ->consumable()
            ->get(['stock_batches.id', 'stock_batches.product_id', 'stock_batches.unit_cost', 'stock_batches.current_quantity']);

        $held = StockReservation::liveByBatch($batches->pluck('id')->all());
        $estimates = [];

        foreach ($batches as $batch) {
            $productId = (int) $batch->product_id;

            if (isset($estimates[$productId])) {
                continue;
            }

            if ((float) $batch->current_quantity - (float) ($held[$batch->id] ?? '0') > 0) {
                $estimates[$productId] = number_format((float) $batch->unit_cost, 2, '.', '');
            }
        }

        return $estimates;
    }

    /**
     * Reserve each line's stock in the approved warehouse and freeze its price.
     * Inside the caller's transaction, after the limits were re-checked.
     *
     * @throws InsufficientStockException
     */
    public function reserveAndFreeze(StaffPurchaseRequest $request, Warehouse $warehouse, User $by): void
    {
        foreach ($request->lines()->with('product')->get() as $line) {
            /** @var StaffPurchaseLine $line */
            $slices = $this->reserve->handle(
                $line->product,
                $warehouse,
                (string) $line->quantity,
                StaffPurchasePolicy::RESERVATION_REASON,
                $request,
                $by,
            )->load('batch');

            $quantity = 0.0;
            $value = 0.0;
            $batches = [];

            foreach ($slices as $slice) {
                $quantity += (float) $slice->quantity;
                $value += (float) $slice->quantity * (float) $slice->batch->unit_cost;
                $batches[] = [
                    'batch_id' => (int) $slice->batch->id,
                    'batch_number' => $slice->batch->batch_number,
                    'unit_cost' => number_format((float) $slice->batch->unit_cost, 4, '.', ''),
                    'quantity' => number_format((float) $slice->quantity, 4, '.', ''),
                    'reservation_id' => (int) $slice->id,
                ];
            }

            // ⚠ Weighted over the slices: one unit a month means one slice in
            // practice, but a fractional or larger quantity must not pick a cost
            // at random (ruling Q1: "the service must handle it safely").
            $unitCost = $quantity > 0 ? $value / $quantity : 0.0;

            $line->forceFill([
                'staff_price' => $line->isAtStaffPrice()
                    ? number_format($unitCost, 2, '.', '')
                    : number_format((float) $line->cap_reference_price, 2, '.', ''),
                'cost_basis_batch_id' => $batches[0]['batch_id'] ?? null,
                'cost_snapshot' => [
                    // ⭐ Answer 6: a custom line keeps its typed price (held in cap_reference_price).
                    'basis' => $line->isAtStaffPrice() ? StaffPurchasePolicy::PRICE_BASIS : $line->priced_at,
                    'unit_cost' => number_format($unitCost, 4, '.', ''),
                    'warehouse_id' => $warehouse->id,
                    'frozen_at' => now()->toDateTimeString(),
                    'batches' => $batches,
                ],
                'price_frozen_at' => now(),
            ])->save();
        }

        $request->forceFill([
            'warehouse_id' => $warehouse->id,
            'price_basis' => StaffPurchasePolicy::PRICE_BASIS,
        ])->save();
    }

    /**
     * Re-read every reserved slice right before the goods leave.
     *
     * @throws StaffPurchaseNeedsReapproval with the reason, when the approved price no longer holds
     */
    public function assertReservationsHold(StaffPurchaseRequest $request): void
    {
        $reservations = $request->reservations()->live()->get();

        if ($reservations->isEmpty()) {
            throw new StaffPurchaseNeedsReapproval("Application {$request->number} holds no reserved stock any more.");
        }

        $lines = $request->lines()->with('product')->get()->keyBy('product_id');

        foreach ($reservations as $reservation) {
            /** @var StockBatch|null $batch */
            $batch = StockBatch::query()->whereKey($reservation->stock_batch_id)->consumable()->lockForUpdate()->first();

            if ($batch === null) {
                throw new StaffPurchaseNeedsReapproval("The reserved batch #{$reservation->stock_batch_id} can no longer be issued (gone, on hold or expired).");
            }

            $heldByOthers = StockReservation::liveByBatch([$batch->id], $request->getMorphClass(), (int) $request->getKey());
            $available = (float) $batch->current_quantity - (float) ($heldByOthers[$batch->id] ?? '0');

            if ($available + 0.00005 < (float) $reservation->quantity) {
                throw new StaffPurchaseNeedsReapproval("Batch {$this->label($batch)} holds {$available} and the application reserved {$reservation->quantity}.");
            }

            $line = $lines->get($batch->product_id);
            $frozen = $this->frozenCostOf($line, (int) $batch->id);

            if ($line !== null && $line->isAtStaffPrice() && $frozen !== null && bccomp($frozen, (string) $batch->unit_cost, 4) !== 0) {
                throw new StaffPurchaseNeedsReapproval(sprintf(
                    'Batch %s was repriced from %s to %s after approval.',
                    $this->label($batch),
                    number_format((float) $frozen, 2),
                    number_format((float) $batch->unit_cost, 2),
                ));
            }
        }
    }

    /**
     * After the sale: the movements that left must be the slices that were
     * reserved and priced. A mismatch is a defect in the issue path, and the
     * sale must not stand at a price that belongs to another batch.
     *
     * @param  array<int, int>  $issuedBatchIds
     *
     * @throws StaffPurchaseNeedsReapproval
     */
    public function assertIssuedFromReserved(StaffPurchaseRequest $request, array $issuedBatchIds): void
    {
        $reserved = $request->reservations()->live()->pluck('stock_batch_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $issued = collect($issuedBatchIds)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        if ($reserved !== $issued) {
            throw new StaffPurchaseNeedsReapproval(sprintf(
                'The goods were issued from batch(es) %s but the approved price belongs to %s.',
                implode(', ', $issued) ?: 'none',
                implode(', ', $reserved) ?: 'none',
            ));
        }
    }

    private function frozenCostOf(?StaffPurchaseLine $line, int $batchId): ?string
    {
        foreach ($line?->cost_snapshot['batches'] ?? [] as $slice) {
            if ((int) ($slice['batch_id'] ?? 0) === $batchId) {
                return (string) $slice['unit_cost'];
            }
        }

        return null;
    }

    private function label(StockBatch $batch): string
    {
        return $batch->batch_number ?? "#{$batch->id}";
    }
}
