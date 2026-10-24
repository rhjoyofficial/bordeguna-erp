<?php

namespace App\Modules\Purchasing\Corrections;

use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Purchasing\Actions\ReverseLandedCost;
use App\Modules\Purchasing\Models\LandedCost;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ Initial plan B24 (25 Oct 2026): a posted landed cost is reversible only
 * while its cost can come off the batches it went onto, exactly, and the
 * bills it raised are still unpaid (ReverseLandedCost::blocker).
 */
class LandedCostStockCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::Stock;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! $document instanceof LandedCost || ! $document->isPosted() || $document->isReversed()) {
            return null;
        }

        $sentence = ReverseLandedCost::blocker($document);

        return $sentence === null ? null : new Blocker(str_starts_with($sentence, 'Bill ') ? 'bill_settled' : 'cost_moved_on', $sentence);
    }
}
