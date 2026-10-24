<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Enums\TransferStatus;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\EffectiveDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Initial plan B7 (Joy, 24 Oct 2026; Q7 = A): moves part of ONE named batch
 * to another warehouse in one step, same batch number, same cost, same
 * expiry. The two ways in and out of a Not for sale (quarantine) warehouse:
 *
 *  - Move back to stock (MoveStockBackToSale): what turned out reusable goes
 *    from quarantine to a selling warehouse, with a reason;
 *  - a damaged sales return (RecordSalesReturn): goods restored onto the batch
 *    the sale took them from, then moved into quarantine.
 *
 * It is an ordinary stock transfer (StockTransfer, out leg here, in leg by
 * ReceiveStockTransfer the same moment), so the batch trace, the transfers
 * list and the transfer corrections see it like any other. ⚠ A transfer
 * posts nothing: stock moving between two warehouses of one business changes
 * nothing the ledger can see, and a quarantined bottle is still stock at its
 * cost until it is written off.
 *
 * ⚠ Named, never FIFO: the person chose the batch on the screen (or the
 * return knows it), and FIFO would never pick from quarantine anyway.
 */
class MoveBatchToWarehouse
{
    public function __construct(private readonly ReceiveStockTransfer $receive) {}

    public function handle(
        StockBatch $batch,
        Warehouse $target,
        string $quantity,
        Reason $reason,
        User $performedBy,
        ?string $note = null,
        mixed $occurredAt = null,
    ): StockTransfer {
        $movedAt = EffectiveDate::resolve($occurredAt);

        return DB::transaction(function () use ($batch, $target, $quantity, $reason, $performedBy, $note, $movedAt, $occurredAt) {
            // ⚠ Re-read under a lock: two clicks, or two people, moving the
            // last jars of one batch must not both pass the check below.
            $current = StockBatch::query()->whereKey($batch->id)->lockForUpdate()->first();

            if ($current === null) {
                throw ValidationException::withMessages(['stock_batch_id' => 'That batch no longer exists.']);
            }

            if ((int) $current->warehouse_id === (int) $target->id) {
                throw ValidationException::withMessages(['target_warehouse_id' => 'Choose another warehouse: the batch is already there.']);
            }

            if (! $target->is_active) {
                throw ValidationException::withMessages(['target_warehouse_id' => "{$target->name} is switched off."]);
            }

            $scaled = (int) round(((float) $quantity) * 10000);

            if ($scaled <= 0) {
                throw ValidationException::withMessages(['quantity' => 'Enter a quantity above zero.']);
            }

            $held = (int) round(((float) (StockReservation::liveByBatch([$current->id])[$current->id] ?? '0')) * 10000);
            $free = (int) round(((float) $current->current_quantity) * 10000) - $held;

            if ($scaled > $free) {
                throw ValidationException::withMessages([
                    'quantity' => "Batch {$current->batch_number} holds only ".Product::plainQuantity(number_format(max(0, $free) / 10000, 4, '.', '')).' that can be moved.',
                ]);
            }

            if ($current->received_at !== null) {
                EffectiveDate::assertNotBefore($movedAt, $current->received_at, $current->id);
            }

            $group = (string) Str::uuid();
            $plain = number_format($scaled / 10000, 4, '.', '');

            StockMovement::create([
                'product_id' => $current->product_id,
                'warehouse_id' => $current->warehouse_id,
                'batch_id' => $current->id,
                'direction' => MovementDirection::Out,
                'reason' => MovementReason::Transfer,
                'reason_id' => $reason->id,
                'quantity' => $plain,
                'unit_cost' => $current->unit_cost,
                'occurred_at' => $movedAt,
                'transfer_group_id' => $group,
                'performed_by' => $performedBy->id,
                'note' => $note,
            ]);

            $transfer = StockTransfer::create([
                'transfer_group_id' => $group,
                'product_id' => $current->product_id,
                'source_warehouse_id' => $current->warehouse_id,
                'target_warehouse_id' => $target->id,
                'quantity' => $plain,
                'reason_id' => $reason->id,
                'note' => $note,
                'status' => TransferStatus::InTransit,
                'dispatched_by' => $performedBy->id,
                'dispatched_at' => now(),
            ]);

            // The same moment: nothing is on the road between two rooms.
            $this->receive->handle($transfer, $performedBy, $occurredAt);

            return $transfer->fresh();
        });
    }

    /** The transfer reason a move is filed under, created once per business. */
    public static function reasonNamed(string $name): Reason
    {
        return Reason::query()->firstOrCreate(
            ['context' => 'inventory.stock_transfer', 'name' => $name],
            ['is_active' => true],
        );
    }
}
