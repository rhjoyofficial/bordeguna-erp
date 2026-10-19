<?php

namespace App\Modules\Inventory\Corrections;

use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Inventory\Models\OpeningStock;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ Corrections wave 1, B4: every line still points at the batch it made.
 * What each line may change (a batch already used since the opening, a cost
 * changed since) is decided per line by OpeningStockCorrector::validateReplacement,
 * where the person can see which line and why.
 */
class OpeningStockBatchCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::Stock;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! $action->isCorrection() || ! $document instanceof OpeningStock || $document->isReversed()) {
            return null;
        }

        $broken = $document->lines()->with('stockMovement.batch')->get()
            ->contains(fn ($line) => $line->stockMovement?->batch === null);

        return $broken
            ? new Blocker('batch_gone', 'A batch this opening stock made is missing. Ask the administrator to check it.', resolvableBy: 'administrator')
            : null;
    }
}
