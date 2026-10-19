<?php

namespace App\Modules\Purchasing\Corrections;

use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ The goods a receipt brought in are still exactly where it put them
 * (corrections wave 1, 19 Oct 2026; FINAL-PLAN 3.3 "Reverse: goods untouched").
 *
 * Taking a receipt back out is only honest while every batch it made still
 * holds every unit it made: nothing sold, used, moved, returned or reserved
 * since. Once some is gone, reversing would go negative or take other units,
 * and the cost of what was sold follows the plan's own rule (Tk 10,000 to
 * today's cost of goods sold, above that the Owner), which no receipt screen
 * decides on its own. So it is refused in a sentence.
 *
 * ⚠ Only REVERSE is decided here. A Correct that changes only the date keeps
 * the same batch and is allowed with part of it sold (GoodsReceiptCorrector
 * checks the one thing that can break: a withdrawal before the new date). A
 * Correct that changes the quantity or the lot asks `touched()` itself.
 */
class GoodsReceiptStockCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::Stock;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if ($action !== DocumentAction::Reverse || ! $document instanceof GoodsReceipt || $document->isReversed()) {
            return null;
        }

        $sentence = self::touched($document);

        return $sentence === null ? null : new Blocker('stock_moved', $sentence, resolvableBy: 'finance');
    }

    /** Null while every batch is untouched; otherwise the one sentence why not. */
    public static function touched(GoodsReceipt $receipt): ?string
    {
        $receipt->loadMissing('lines.stockMovement.batch');

        foreach ($receipt->lines as $line) {
            /** @var GoodsReceiptLine $line */
            $movement = $line->stockMovement;

            if ($movement === null || $movement->batch === null) {
                continue;
            }

            $batch = $movement->batch;
            $name = $batch->batch_number ?? "#{$batch->id}";

            $moved = StockMovement::query()
                ->where('batch_id', $batch->id)
                ->whereKeyNot($movement->id)
                ->where('quantity', '!=', 0)
                ->exists();

            $reserved = StockReservation::query()
                ->where('stock_batch_id', $batch->id)
                ->whereNull('released_at')
                ->exists();

            if ($moved || $reserved || bccomp((string) $batch->current_quantity, (string) $movement->quantity, 4) !== 0) {
                return "Goods from batch {$name} have been used, sold, moved or reserved since, so this receipt cannot simply be reversed. Correct only its date, or ask Finance about a stock correction.";
            }
        }

        return null;
    }
}
