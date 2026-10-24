<?php

namespace App\Modules\Inventory\Corrections;

use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Inventory\Models\CropHarvest;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockReservation;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ Farm harvest (B9, 24 Oct 2026): the batches a harvest made still hold
 * every unit it put there, nothing sold, used, moved or reserved since
 * (GoodsReceiptStockCheck's rule, at the harvest's door).
 *
 * Taking it back out is only honest then: otherwise the batch history would go
 * negative in between, or other units would be taken, and the cost of what
 * was sold is a cost-of-goods question (the plan's Tk 10,000 rule) that no
 * harvest screen decides on its own. Refused in a sentence.
 */
class CropHarvestStockCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::Stock;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! $action->isCorrection() || ! $document instanceof CropHarvest || $document->isReversed()) {
            return null;
        }

        $sentence = self::touched($document);

        return $sentence === null ? null : new Blocker('stock_moved', $sentence, resolvableBy: 'finance');
    }

    /** Null while every batch is untouched; otherwise the one sentence why not. */
    public static function touched(CropHarvest $harvest): ?string
    {
        $harvest->loadMissing('lines.stockMovement.batch');

        foreach ($harvest->lines as $line) {
            $movement = $line->stockMovement;
            $batch = $movement?->batch;

            if ($batch === null) {
                return "A batch {$harvest->number} made is missing. Ask the administrator to check it.";
            }

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
                $name = $batch->batch_number ?? "#{$batch->id}";

                return "Some of batch {$name} has been sold, used, moved or reserved since, so {$harvest->number} cannot simply be reversed. Ask Finance about a stock correction.";
            }
        }

        return null;
    }
}
