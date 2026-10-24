<?php

namespace App\Modules\Inventory\Corrections;

use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\SoldStock;
use App\Modules\Inventory\Support\SoldStockShare;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ Corrections wave 1, B4: the batches an adjustment touched can still take
 * its reversal, exactly (FINAL-PLAN 3.3: "Reverse into the same batch. If that
 * batch is gone, a new adjustment with a reason").
 *
 *   found stock (increase)  the batch it created is untouched: nothing has
 *                           moved in or out of it since. The reversal takes
 *                           the units back out on the adjustment's own date,
 *                           and a later sale from that batch would leave its
 *                           history negative in between (the batch-history
 *                           rule), so once anything moved it is refused.
 *   damage or loss          each batch still carries the cost the units left
 *   (decrease)              at: the units go back on at that cost. A batch
 *                           revalued since would put them back at another
 *                           cost than the journal takes back, so it waits.
 *
 * Refused means: record what is really there as a new correction (found stock
 * or damage), with its own reason. That is the plan's "new adjustment".
 */
class AdjustmentStockCheck implements DependencyCheck
{
    /**
     * ⭐ 24 Oct 2026: per found-stock movement, what is left and what was used.
     *
     * @return list<SoldStockShare>
     */
    public static function shares(InventoryAdjustment $adjustment): array
    {
        if ($adjustment->direction !== AdjustmentDirection::Increase) {
            return [];
        }

        $originals = $adjustment->movements()->whereNull('reverses_movement_id')->orderBy('id')->get();

        return $originals->map(fn (StockMovement $m) => SoldStock::share($m))->filter()->values()->all();
    }

    /** What the found stock already used cost, in paisa. */
    public static function usedCostMinor(InventoryAdjustment $adjustment): int
    {
        return array_sum(array_map(fn (SoldStockShare $s) => $s->costMinor, self::shares($adjustment)));
    }

    public function covers(): EffectKind
    {
        return EffectKind::Stock;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! $action->isCorrection() || ! $document instanceof InventoryAdjustment || $document->isReversed() || $document->changesValueOnly()) {
            return null;
        }

        $originals = $document->movements()->whereNull('reverses_movement_id')->with('batch')->get();

        if ($originals->isEmpty()) {
            return new Blocker('no_movements', 'This correction moved no stock. Ask the administrator to check it.', resolvableBy: 'administrator');
        }

        if ($document->direction === AdjustmentDirection::Increase) {
            // ⭐ 24 Oct 2026, the Tk 10,000 rule: found stock partly sold or
            // used since is reversed for what is left; the cost of what was
            // used posts today (SoldStock). Refused only where unsafe.
            foreach (self::shares($document) as $share) {
                if ($share->refusal !== null) {
                    return new Blocker('batch_used', $share->refusal);
                }
            }

            return null;
        }

        foreach ($originals as $movement) {
            /** @var StockMovement $movement */
            $batch = $movement->batch ?? StockBatch::query()->find($movement->batch_id);

            if ($movement->direction !== MovementDirection::Out || $batch === null) {
                return new Blocker('batch_gone', 'A batch this correction took from is gone. Record the stock as found stock instead.');
            }

            if (bccomp((string) $batch->unit_cost, (string) $movement->unit_cost, 4) !== 0) {
                return new Blocker(
                    'batch_cost_changed',
                    "Batch {$batch->batch_number}'s cost has changed since, so the units cannot simply go back on. Record them as found stock instead.",
                );
            }
        }

        return null;
    }
}
