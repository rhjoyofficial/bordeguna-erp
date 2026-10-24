<?php

namespace App\Modules\Sales\Support;

use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\BatchHistory;
use App\Modules\Sales\Enums\SalesReturnDisposition;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnLineMovement;
use Illuminate\Support\Collection;

/**
 * ⭐ IP3 meets IP14 and IP6 (integration, 25 Oct 2026): a return puts its goods
 * back onto the batches the sale took them from, then RecordSalesReturn's
 * placeRestored() moves the good share to the chosen selling warehouse and
 * the damaged share into quarantine (MoveBatchToWarehouse: an ordinary
 * transfer, out of the restored batch, into a new batch of the same number).
 *
 * Reversing the return must follow those goods: it takes them back from the
 * batch they were moved INTO (the quarantine or the other selling warehouse),
 * which leaves the restored batch exactly where it was before the return
 * (restored, then moved away), and only what stayed on the restored batch is
 * taken back there. It is refused only when goods have since been sold, used
 * or moved on.
 *
 * The moves are found by what placeRestored() wrote: a Transfer out of a
 * restored batch carrying the return's own note.
 */
final class ReturnPlacements
{
    /**
     * The return's own placement moves.
     *
     * @return Collection<int, array{out: StockMovement, in: StockMovement}>
     */
    public static function moves(SalesReturn $return): Collection
    {
        $restored = self::links($return)->pluck('restored_movement_id');

        if ($restored->isEmpty()) {
            return collect();
        }

        $batches = StockMovement::query()->whereIn('id', $restored)->pluck('batch_id')->filter()->unique();

        return StockMovement::query()
            ->whereIn('batch_id', $batches)
            ->where('direction', MovementDirection::Out)
            ->where('reason', MovementReason::Transfer)
            ->whereNotNull('transfer_group_id')
            ->whereIn('note', ["Damaged, returned on {$return->return_number}", "Good, returned on {$return->return_number}"])
            ->orderBy('id')
            ->get()
            ->map(function (StockMovement $out) {
                $in = StockMovement::query()
                    ->where('transfer_group_id', $out->transfer_group_id)
                    ->where('direction', MovementDirection::In)
                    ->first();

                return $in === null ? null : ['out' => $out, 'in' => $in];
            })
            ->filter()
            ->values();
    }

    /**
     * Where each unit a return put back is now, link by link: the batches its
     * goods were moved into first, then what stayed on the restored batch.
     * Each step is a receipt-like movement to take back from, and how much.
     *
     * @return list<array{link: SalesReturnLineMovement, from: StockMovement, scaled: int}>
     */
    public static function plan(SalesReturn $return): array
    {
        $left = [];
        $legs = [];

        foreach (self::moves($return) as $move) {
            $legs[(int) $move['out']->batch_id][] = $move['in'];
            $left[$move['in']->id] = (int) round((float) $move['in']->quantity * 10000);
        }

        $plan = [];

        foreach (self::links($return) as $link) {
            $restored = StockMovement::query()->find($link->restored_movement_id);

            if ($restored === null) {
                continue;
            }

            $need = (int) round((float) $link->quantity * 10000);

            foreach ($legs[(int) $restored->batch_id] ?? [] as $in) {
                $take = min($need, $left[$in->id]);

                if ($take <= 0) {
                    continue;
                }

                $plan[] = ['link' => $link, 'from' => $in, 'scaled' => $take];
                $left[$in->id] -= $take;
                $need -= $take;
            }

            if ($need > 0) {
                $plan[] = ['link' => $link, 'from' => $restored, 'scaled' => $need];
            }
        }

        return $plan;
    }

    /** The sentence refusing the reversal, or null when every unit is still where the return left it. */
    public static function blocker(SalesReturn $return): ?string
    {
        $wanted = [];

        foreach (self::plan($return) as $step) {
            $id = $step['from']->id;
            $wanted[$id] = ['from' => $step['from'], 'scaled' => ($wanted[$id]['scaled'] ?? 0) + $step['scaled']];
        }

        foreach ($wanted as ['from' => $from, 'scaled' => $scaled]) {
            $batch = StockBatch::query()->find($from->batch_id);
            $name = $batch?->batch_number ?? "#{$from->batch_id}";

            if ($batch === null || (int) round((float) $batch->current_quantity * 10000) < $scaled || BatchHistory::lowestScaledFrom($from) < $scaled) {
                return "Goods that came back on {$return->return_number} have been sold, used or moved from batch {$name} since, so the return cannot be reversed.";
            }

            if (bccomp((string) $batch->unit_cost, (string) $from->unit_cost, 4) !== 0) {
                return "The cost of batch {$name} changed after {$return->return_number} put goods back, so the return cannot be reversed exactly.";
            }
        }

        return null;
    }

    /** @return Collection<int, SalesReturnLineMovement> */
    private static function links(SalesReturn $return): Collection
    {
        return SalesReturnLineMovement::query()
            ->whereIn('sales_return_line_id', $return->lines()->select('id'))
            ->where('disposition', SalesReturnDisposition::Stock)
            ->whereNotNull('restored_movement_id')
            ->orderBy('id')
            ->get();
    }
}
