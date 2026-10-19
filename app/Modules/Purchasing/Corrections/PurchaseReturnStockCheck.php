<?php

namespace App\Modules\Purchasing\Corrections;

use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Purchasing\Actions\ReversePurchaseReturn;
use App\Modules\Purchasing\Models\PurchaseReturn;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ Every batch the return relieved is still there at the cost the goods left
 * at, so they go back onto the same batch at the same cost (ReversePurchaseReturn).
 */
class PurchaseReturnStockCheck implements DependencyCheck
{
    public function __construct(private readonly ReversePurchaseReturn $reverse) {}

    public function covers(): EffectKind
    {
        return EffectKind::Stock;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! $action->isCorrection() || ! $document instanceof PurchaseReturn || $document->isReversed()) {
            return null;
        }

        $refusal = $this->reverse->stockRefusal($document);

        return $refusal === null ? null : new Blocker('return_stock_moved', $refusal);
    }
}
