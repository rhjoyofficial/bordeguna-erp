<?php

namespace App\Modules\Sales\Actions\Corrections;

use App\Models\User;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\ClosedPeriodPass;
use App\Modules\Inventory\Actions\RestoreStockIssue;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\Corrections\Checks\SalesLedgerCheck;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\DeliveryLine;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Modules\Sales\Support\OrderReservations;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * ⭐ Taking a challan back (corrections wave 1, 20 Oct 2026, FINAL-PLAN 3.2):
 * the goods never really left, or left on the wrong challan.
 *
 *   undo()  1. every FIFO layer the challan took goes back onto the SAME batch
 *              at the SAME cost (Inventory's RestoreStockIssue, a movement that
 *              names the issue it reverses: Sales never writes stock itself);
 *           2. the GDNI journal (Dr GDNI / Cr Stock on Hand) is mirrored on the
 *              date the correction rules give (its own date by default);
 *           3. the quantity goes back on each order line, and each order's
 *              status is worked out again (a delivered order is open again);
 *              a closed or cancelled order keeps its status, the quantity
 *              simply stops being delivered
 *   mark()  the Reversible columns
 *
 * ⚠⚠ The stock leg and the ledger leg must be worth the same to the paisa, or
 * 1150 and the batches stop agreeing: the restored movement is valued at the
 * batch's cost, the journal at the cost the challan took. So a batch whose cost
 * changed since (a landed cost, a supplier discount, a revaluation) is refused
 * by the policy (DeliveryStockCheck) and refused again here.
 *
 * ⚠ Called only from inside a correction's transaction: the policy, the dates
 * and the Owner's pass are decided there.
 */
class UndoDelivery
{
    public function __construct(
        private readonly ReverseJournal $reverseJournal,
        private readonly RestoreStockIssue $restore,
    ) {}

    public function undo(Delivery $delivery, User $by, Carbon $on, string $memo, ?ClosedPeriodPass $pass = null): ?Journal
    {
        $delivery->load('lines.movements');
        $journal = SalesLedgerCheck::journalOf($delivery);

        if ($journal === null && SalesLedgerCheck::expectsJournal($delivery)) {
            throw new LogicException("Challan {$delivery->delivery_number} has no single ledger entry to reverse.");
        }

        // The goods come back at the moment they left when the reversal keeps
        // the challan's own day; on any other day, now.
        $at = $on->isSameDay($delivery->delivered_at) ? $delivery->delivered_at->copy() : Carbon::now();
        $given = [];
        $orders = [];

        foreach ($delivery->lines as $line) {
            /** @var DeliveryLine $line */
            $restorations = [];

            foreach ($line->movements as $movement) {
                /** @var StockMovement $movement */
                $batch = StockBatch::query()->whereKey($movement->batch_id)->lockForUpdate()->first();

                if ($batch === null || bccomp((string) $batch->unit_cost, (string) $movement->unit_cost, 4) !== 0) {
                    throw new LogicException("Challan {$delivery->delivery_number}: a batch it took from changed its cost since.");
                }

                $restorations[] = ['movement' => $movement, 'quantity' => (string) $movement->quantity];
            }

            if ($restorations !== []) {
                $this->restore->handle(
                    restorations: $restorations,
                    performedBy: $by,
                    note: "Challan {$delivery->delivery_number} reversed",
                    reason: MovementReason::Sale,
                    occurredAt: $at,
                    pass: $pass,
                );
            }

            if ($line->sales_order_line_id !== null) {
                $orderLine = SalesOrderLine::query()->whereKey($line->sales_order_line_id)->lockForUpdate()->firstOrFail();
                $back = min((float) $line->quantity, (float) $orderLine->quantity_delivered);

                $orderLine->forceFill([
                    'quantity_delivered' => number_format((float) $orderLine->quantity_delivered - $back, 4, '.', ''),
                ])->save();

                $orders[$orderLine->sales_order_id] = true;
                $given[] = [
                    'sales_order_line_id' => (int) $orderLine->id,
                    'sales_order_id' => (int) $orderLine->sales_order_id,
                    'quantity' => number_format($back, 4, '.', ''),
                ];
            }
        }

        foreach (array_keys($orders) as $orderId) {
            $order = SalesOrder::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();

            // ⚠ Closed short and Cancelled are explicit decisions; giving a
            // quantity back does not undo them.
            if (! in_array($order->status, [SalesOrderStatus::Closed, SalesOrderStatus::Cancelled], true)) {
                RecordDelivery::recomputeStatus($order);

                // ⭐ B17 (25 Oct 2026, Q4 = A): what is owed again is held
                // again, as far as the shelf allows.
                app(OrderReservations::class)->resync($order, $by, "Challan {$delivery->delivery_number} reversed");
            }
        }

        $delivery->forceFill(['reversed_quantities' => $given])->save();

        return $journal === null ? null : $this->reverseJournal->handle($journal->load('lines'), $by, $on, $memo, $pass);
    }

    public function mark(Delivery $delivery, User $by, Carbon $on, string $reason, ?Journal $reversal): void
    {
        $delivery->forceFill([
            'reversed_on' => $on,
            'reversed_at' => now(),
            'reversed_by' => $by->id,
            'reversal_reason' => $reason,
            'reversal_journal_id' => $reversal?->id,
        ])->save();
    }
}
