<?php

namespace App\Modules\Sales\Corrections;

use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Sales\Actions\Sellers\ReverseSellerTransfer;
use App\Modules\Sales\Models\SellerTransfer;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ Initial plan B21: a seller's hand-over or return is reversible only while
 * every item it moved is still where it put it (ReverseSellerTransfer::blocker).
 */
class SellerTransferStockCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::Stock;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! $document instanceof SellerTransfer || $document->isReversed()) {
            return null;
        }

        $sentence = ReverseSellerTransfer::blocker($document);

        return $sentence === null ? null : new Blocker('goods_moved_on', $sentence);
    }
}
