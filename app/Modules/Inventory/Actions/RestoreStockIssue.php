<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Foundation\Corrections\ClosedPeriodPass;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\EffectiveDate;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Puts returned goods back onto the exact batches a sale consumed (the mirror
 * of ReverseStockReceipt, in the opposite direction).
 *
 * ⚠⚠ Why this takes an explicit PLAN rather than walking capacity itself.
 * ReverseStockReceipt can derive how much a receipt still holds from the batch,
 * because the batch IS the capacity. Here the capacity question is "how much of
 * this sale's consumption has already been returned", and part of the answer
 * lives in WRITE-OFFS - returned goods destroyed on inspection, which consume a
 * layer's returnable share without writing any stock movement. Only the sales
 * return document knows that, so the caller computes the per-movement split and
 * this action executes it. Deriving capacity from movements alone here would
 * let a later return restore the same layer twice, overdrawing GDNI/COGS with
 * every journal balancing.
 *
 * ⚠ What IS enforced here: each restoration reverses a genuine OUT movement, on
 * its own batch, by no more than that movement ever took. The cumulative cap
 * across several returns is the caller's arithmetic, proven by its own re-read
 * under lock - the same trust RecordStockIn extends to a goods receipt's
 * quantity.
 *
 * ⚠ The movement carries `reverses_movement_id`, so the ledger states what it
 * undoes (the §10 auditability rule), and the batch cache follows via
 * StockMovementObserver. A restored batch whose expiry has passed is
 * automatically unsellable - the consumable scope filters on the date, not on
 * status - so expired goods coming back cannot be resold by accident.
 */
class RestoreStockIssue
{
    /**
     * @param  array<int, array{movement: StockMovement, quantity: string}>  $restorations
     * @return Collection<int, StockMovement> the IN movements written, one per entry
     */
    public function handle(
        array $restorations,
        User $performedBy,
        ?string $note = null,
        MovementReason $reason = MovementReason::SalesReturn,
        mixed $occurredAt = null,
        // ⭐ Corrections wave 1 (20 Oct 2026): a reversed challan puts its goods
        // back on the very layers it took; inside a closed month only with the
        // Owner's one-document pass.
        ?ClosedPeriodPass $pass = null,
    ): Collection {
        $restoredAt = EffectiveDate::resolve($occurredAt, 'occurred_at', $pass);

        if ($restorations === []) {
            throw new InvalidArgumentException('Nothing to restore.');
        }

        $written = collect();

        foreach ($restorations as $entry) {
            $issue = $entry['movement'];
            $quantity = $this->scaled($entry['quantity']);

            // ⚠ The same rounds-to-nothing refusal as ReverseStockReceipt: a
            // quantity that scales to zero would write a movement that says
            // something happened when nothing did.
            if ($quantity <= 0) {
                throw new InvalidArgumentException(
                    "A restoration quantity of {$entry['quantity']} rounds to zero at four decimal places; nothing would move."
                );
            }

            if ($issue->direction !== MovementDirection::Out) {
                throw new InvalidArgumentException(
                    "Movement #{$issue->id} is not an issue and cannot be restored as one."
                );
            }

            // ⚠ Per-movement ceiling: even a caller with broken arithmetic can
            // never put back more through one layer than that layer gave out.
            if ($quantity > $this->scaled((string) $issue->quantity)) {
                throw new InvalidArgumentException(
                    "Movement #{$issue->id} issued only {$issue->quantity}; cannot restore {$entry['quantity']} through it."
                );
            }

            $batch = StockBatch::whereKey($issue->batch_id)->lockForUpdate()->first();

            if ($batch === null) {
                throw new InvalidArgumentException(
                    "Movement #{$issue->id} has no batch to restore onto."
                );
            }

            // ⚠ The batch's frozen cost, exactly as ReverseStockReceipt values
            // the opposite direction. The conversion factor froze on the batch
            // too, so by-weight goods come back in the unit they left in.
            $written->push(StockMovement::create([
                'product_id' => $issue->product_id,
                'warehouse_id' => $issue->warehouse_id,
                'batch_id' => $batch->id,
                'direction' => MovementDirection::In,
                'reason' => $reason,
                'quantity' => $this->unscaled($quantity),
                'unit_cost' => $batch->unit_cost,
                'reverses_movement_id' => $issue->id,
                'occurred_at' => $restoredAt,
                'performed_by' => $performedBy->id,
                'note' => $note,
            ]));
        }

        return $written;
    }

    private function scaled(string $quantity): int
    {
        return (int) round(((float) $quantity) * 10000);
    }

    private function unscaled(int $scaled): string
    {
        return number_format($scaled / 10000, 4, '.', '');
    }
}
