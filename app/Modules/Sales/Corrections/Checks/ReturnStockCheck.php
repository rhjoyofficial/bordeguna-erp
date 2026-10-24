<?php

namespace App\Modules\Sales\Corrections\Checks;

use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\BatchHistory;
use App\Modules\Sales\Enums\SalesReturnDisposition;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnLineMovement;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ B13 (24 Oct 2026): a return is reversed only while the goods it put back
 * are STILL IN THOSE BATCHES (FINAL-PLAN "Sales return"), at the cost they came
 * back at.
 *
 *   - every unit put back must still be there: the batch's history from that
 *     movement onward never ran below it (BatchHistory), the rule every stock
 *     correction keeps, so the batch can never go negative or move before it
 *     existed;
 *   - the batch's cost must be the one the goods came back at, or the stock
 *     leg and the mirrored journal would differ by the paisa (a landed cost or
 *     a revaluation since).
 *
 * Written-off units moved no stock, so they have nothing to check here.
 */
class ReturnStockCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::Stock;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! $document instanceof SalesReturn || $document->isReversed() || $document->isMirror() || ! $action->isCorrection()) {
            return null;
        }

        $links = SalesReturnLineMovement::query()
            ->whereIn('sales_return_line_id', $document->lines()->select('id'))
            ->whereNotNull('restored_movement_id')
            ->get();

        foreach ($links as $link) {
            if ($link->disposition !== SalesReturnDisposition::Stock) {
                continue;
            }

            $in = StockMovement::query()->find($link->restored_movement_id);
            $batch = $in === null ? null : StockBatch::query()->find($in->batch_id);
            $name = $batch?->batch_number ?? ($batch === null ? 'its batch' : "#{$batch->id}");

            if ($in === null || $batch === null) {
                return new Blocker('stock_missing', "The stock return {$document->return_number} put back cannot be found. Ask the administrator to check it.");
            }

            $wanted = (int) round((float) $link->quantity * 10000);

            if ((int) round((float) $batch->current_quantity * 10000) < $wanted || BatchHistory::lowestScaledFrom($in) < $wanted) {
                return new Blocker('stock_used', "Goods that came back on {$document->return_number} have been sold or used from batch {$name} since, so the return cannot be reversed.");
            }

            if (bccomp((string) $batch->unit_cost, (string) $in->unit_cost, 4) !== 0) {
                return new Blocker('cost_changed', "The cost of batch {$name} changed after {$document->return_number} put goods back, so the return cannot be reversed exactly.");
            }
        }

        return null;
    }
}
