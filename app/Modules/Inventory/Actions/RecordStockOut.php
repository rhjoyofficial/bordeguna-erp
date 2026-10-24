<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Foundation\Corrections\ClosedPeriodPass;
use App\Modules\Inventory\Actions\Concerns\ConsumesFifoStock;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\EffectiveDate;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RecordStockOut
{
    use ConsumesFifoStock;

    /**
     * @return Collection<int, StockMovement> one movement per batch touched
     */
    public function handle(
        Product $product,
        Warehouse $warehouse,
        string $quantity,
        User $performedBy,
        ?string $note = null,
        MovementReason $reason = MovementReason::Usage,
        ?int $reasonId = null,
        mixed $occurredAt = null,
        // ⭐ Phase 2 (17 Oct 2026): the approved document whose reserved
        // batch slices this issue takes first (see ConsumesFifoStock).
        ?Model $reservedFor = null,
        // ⭐ 24 Oct 2026: the Owner's closed-month exception, from a production
        // run correction only (EffectiveDate::resolve). Null for every other caller.
        ?ClosedPeriodPass $pass = null,
    ): Collection {
        if (! $product->type->isStocked()) {
            throw new DomainException("Product #{$product->id} is a service and cannot hold stock.");
        }

        // ⭐ Initial plan B7 (Joy, 24 Oct 2026; Q7 = A): nothing is sold,
        // used, issued or made from a Not for sale (quarantine) warehouse.
        // A write-off (Adjustment) and a purchase return still take from it.
        $this->assertWarehouseTakes($warehouse, $reason);

        $movedAt = EffectiveDate::resolve($occurredAt, pass: $pass);

        return DB::transaction(function () use ($product, $warehouse, $quantity, $performedBy, $note, $reason, $reasonId, $movedAt, $reservedFor) {
            $consumed = $this->consumeFifo($product, $warehouse, $quantity, $movedAt, $reservedFor);

            return $consumed->map(fn ($portion) => $this->movement($product, $warehouse, $portion['batch'], $portion['quantity'], $reason, $reasonId, $performedBy, $note, $movedAt));
        });
    }

    /**
     * ⭐ Initial plan B8 (Joy, 24 Oct 2026; Q8 = B, R31): take EXACTLY the
     * batches a person picked, never FIFO. Each pick is checked again under a
     * lock: this product, this warehouse, active, not on hold, not past its
     * expiry on the day it is taken, not received after that day, and no more
     * than it holds once stock held for somebody else is set aside. One
     * refusal stops the lot (the caller's transaction rolls it all back).
     *
     * @param  list<array{batch_id: int, quantity: string}>  $picks
     * @return Collection<int, StockMovement> one movement per pick
     */
    public function fromBatches(
        Product $product,
        Warehouse $warehouse,
        array $picks,
        User $performedBy,
        ?string $note = null,
        MovementReason $reason = MovementReason::Usage,
        mixed $occurredAt = null,
        ?ClosedPeriodPass $pass = null,
    ): Collection {
        if (! $product->type->isStocked()) {
            throw new DomainException("Product #{$product->id} is a service and cannot hold stock.");
        }

        $this->assertWarehouseTakes($warehouse, $reason);

        $movedAt = EffectiveDate::resolve($occurredAt, pass: $pass);

        return DB::transaction(function () use ($product, $warehouse, $picks, $performedBy, $note, $reason, $movedAt) {
            $movements = collect();

            foreach ($picks as $pick) {
                $batch = StockBatch::query()->whereKey($pick['batch_id'])->lockForUpdate()->first();
                $label = ($batch?->batch_number ?? '') !== '' ? $batch->batch_number : "#{$pick['batch_id']}";

                if ($batch === null || (int) $batch->product_id !== (int) $product->id || (int) $batch->warehouse_id !== (int) $warehouse->id) {
                    throw new DomainException("Batch {$label} is not {$product->name} in {$warehouse->name}. Choose one of the batches listed.");
                }

                if ($batch->status !== StockBatchStatus::Active || $batch->held_at !== null) {
                    throw new DomainException("Batch {$label} of {$product->name} is on hold or closed, so it cannot be used. Release it first, or choose another batch.");
                }

                if ($batch->expiry_date !== null && $batch->expiry_date->toDateString() < $movedAt->toDateString()) {
                    throw new DomainException("Batch {$label} of {$product->name} expired on {$batch->expiry_date->format('d/m/Y')}, so it cannot be used. Choose another batch, or write it off.");
                }

                if ($batch->received_at !== null) {
                    EffectiveDate::assertNotBefore($movedAt, $batch->received_at, $batch->id);
                }

                $wanted = (int) round(((float) $pick['quantity']) * 10000);
                $held = StockReservation::liveByBatch([$batch->id])[$batch->id] ?? '0';
                $free = (int) round(((float) $batch->current_quantity) * 10000) - (int) round(((float) $held) * 10000);

                if ($wanted <= 0) {
                    throw new InvalidArgumentException("A quantity of {$pick['quantity']} from batch {$label} rounds to nothing.");
                }

                if ($wanted > $free) {
                    throw InsufficientStockException::inBatch((int) $product->id, (int) $warehouse->id, (string) $label, $wanted / 10000, max(0, $free) / 10000);
                }

                $movements->push($this->movement($product, $warehouse, $batch, number_format($wanted / 10000, 4, '.', ''), $reason, null, $performedBy, $note, $movedAt));
            }

            return $movements;
        });
    }

    private function movement(Product $product, Warehouse $warehouse, StockBatch $batch, string $quantity, MovementReason $reason, ?int $reasonId, User $performedBy, ?string $note, mixed $movedAt): StockMovement
    {
        return StockMovement::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'batch_id' => $batch->id,
            'direction' => MovementDirection::Out,
            'reason' => $reason,
            'reason_id' => $reasonId,
            'quantity' => $quantity,
            'unit_cost' => $batch->unit_cost,
            'performed_by' => $performedBy->id,
            'note' => $note,
            'occurred_at' => $movedAt,
        ]);
    }

    /** ⭐ B7: nothing is sold, used, issued or made from a Not for sale warehouse. */
    private function assertWarehouseTakes(Warehouse $warehouse, MovementReason $reason): void
    {
        $use = [
            MovementReason::Sale->value => 'sale, challan or counter bill',
            MovementReason::Usage->value => 'issue',
            MovementReason::Production->value => 'production run',
            MovementReason::StaffReplacement->value => 'staff replacement',
            MovementReason::OwnerWithdrawal->value => 'owner withdrawal',
        ];

        if (isset($use[$reason->value])) {
            $warehouse->assertTakesStockFor($use[$reason->value]);
        }
    }
}
