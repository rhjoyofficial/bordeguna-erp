<?php

namespace App\Modules\Inventory\Corrections;

use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Models\ProductionRun;
use App\Modules\Inventory\Support\SoldStock;
use App\Modules\Inventory\Support\SoldStockShare;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ Corrections wave 1, B4: the batch a run made can still give back what the
 * run put in it, on the run's own date, without turning the batch's history
 * negative in between.
 *
 * ⭐ 24 Oct 2026 (wave 1 gaps, the Tk 10,000 rule): output partly sold or used
 * no longer refuses Reverse and Correct. What is left comes back out; what was
 * sold stays sold at the cost it left at; the difference posts today to cost
 * of sales (ProductionRunCorrector; above Tk 10,000 only the Owner). Refused
 * only where unsafe, in SoldStock's sentence: reserved for an order, moved to
 * another warehouse, its cost changed since, or a history that would turn
 * negative.
 */
class ProductionOutputCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::Stock;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! $action->isCorrection() || ! $document instanceof ProductionRun || ! $document->isConfirmed()) {
            return null;
        }

        if ($document->outputBatch === null) {
            return new Blocker('no_output_batch', "{$document->run_number} has no output batch. Ask the administrator to check it.", resolvableBy: 'administrator');
        }

        $share = self::share($document);

        if ($share === null) {
            return new Blocker('no_output_batch', "{$document->run_number} has no output movement. Ask the administrator to check it.", resolvableBy: 'administrator');
        }

        return $share->refusal === null ? null : new Blocker('output_used', $share->refusal);
    }

    /** What is left of the run's output, and what of it was sold or used since. */
    public static function share(ProductionRun $run): ?SoldStockShare
    {
        $output = $run->movements()
            ->where('direction', MovementDirection::In)
            ->whereNull('reverses_movement_id')
            ->orderBy('id')
            ->first();

        return $output === null ? null : SoldStock::share($output);
    }
}
