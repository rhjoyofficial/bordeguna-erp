<?php

namespace App\Modules\Purchasing\Corrections;

use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Purchasing\Models\SupplierDiscount;
use App\Modules\Purchasing\Models\SupplierDiscountAllocation;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ The batches a discount lowered still hold the units it lowered (ruling
 * P3, 18 Oct 2026).
 *
 * Reversing puts the on-hand share back on each batch. That is exact only
 * while the units it was taken off are all still there: once some have been
 * sold or used at the discounted cost, putting the whole share back would load
 * their part onto the units that remain, and the batch's unit cost would be
 * wrong although the totals tie. So a reversal waits for a person: Finance
 * decides the treatment (ruling P3: never silently restate).
 *
 * ⚠ Any quantity movement on the batch after the discount's own movement
 * counts, in or out. A value-only movement (another landed cost or discount)
 * does not: the share goes back onto the same units either way.
 */
class SupplierDiscountStockCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::Stock;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! $action->isCorrection() || ! $document instanceof SupplierDiscount || $document->isReversed()) {
            return null;
        }

        $moved = $document->allocations()->with('batch:id,batch_number')->get()
            ->filter(fn (SupplierDiscountAllocation $allocation) => $allocation->stock_movement_id !== null
                && StockMovement::query()
                    ->where('batch_id', $allocation->stock_batch_id)
                    ->where('id', '>', $allocation->stock_movement_id)
                    ->where('quantity', '!=', 0)
                    ->exists())
            ->map(fn (SupplierDiscountAllocation $allocation) => $allocation->batch?->batch_number ?? "#{$allocation->stock_batch_id}");

        if ($moved->isEmpty()) {
            return null;
        }

        return new Blocker(
            'stock_moved',
            'Stock has moved in '.$moved->unique()->implode(', ')." since {$document->number} lowered its cost, so the cost cannot simply go back on. Ask Finance to decide how to correct it.",
            resolvableBy: 'finance',
        );
    }
}
