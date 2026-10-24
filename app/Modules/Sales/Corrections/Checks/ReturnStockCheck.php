<?php

namespace App\Modules\Sales\Corrections\Checks;

use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Sales\Enums\SalesReturnDisposition;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnLineMovement;
use App\Modules\Sales\Support\ReturnPlacements;
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

            if ($in === null || StockBatch::query()->find($in->batch_id) === null) {
                return new Blocker('stock_missing', "The stock return {$document->return_number} put back cannot be found. Ask the administrator to check it.");
            }
        }

        // ⭐ IP3 meets IP14 / IP6 (25 Oct 2026): goods the return moved on to
        // another selling warehouse or into quarantine are followed there;
        // refused only when sold, used or moved on since, or re-costed.
        $sentence = ReturnPlacements::blocker($document);

        if ($sentence !== null) {
            return new Blocker(str_contains($sentence, 'cost of batch') ? 'cost_changed' : 'stock_used', $sentence);
        }

        return null;
    }
}
