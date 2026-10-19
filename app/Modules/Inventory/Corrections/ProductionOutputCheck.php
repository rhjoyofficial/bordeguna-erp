<?php

namespace App\Modules\Inventory\Corrections;

use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Inventory\Models\ProductionRun;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ Corrections wave 1, B4: the batch a run made is untouched, so its output
 * can be taken back out on the run's own date without turning the batch's
 * history negative in between (the rule ReverseProductionRun already keeps:
 * "untouched means untouched"). Anything else in or out of it since, a sale, a
 * transfer, a write-off, refuses Reverse and Correct with the way forward.
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

        $batch = $document->outputBatch;

        if ($batch === null) {
            return new Blocker('no_output_batch', "{$document->run_number} has no output batch. Ask the administrator to check it.", resolvableBy: 'administrator');
        }

        $touched = StockMovement::query()
            ->where('batch_id', $batch->id)
            ->where(fn ($q) => $q->whereNull('production_run_id')->orWhere('production_run_id', '!=', $document->id))
            ->where('quantity', '!=', 0)
            ->exists();

        if (! $touched && bccomp((string) $batch->current_quantity, (string) $batch->initial_quantity, 4) === 0) {
            return null;
        }

        return new Blocker(
            'output_used',
            "Some of {$document->run_number}'s output has been sold or used since, so it cannot be undone here. Put the difference right with a stock correction.",
        );
    }
}
