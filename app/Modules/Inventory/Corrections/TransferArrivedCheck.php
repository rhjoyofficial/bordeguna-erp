<?php

namespace App\Modules\Inventory\Corrections;

use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Inventory\Actions\ReverseStockTransfer;
use App\Modules\Inventory\Models\StockTransfer;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ Initial plan B24 (25 Oct 2026): a transfer that arrived is reversible only
 * while every unit it brought is still untouched where it landed
 * (ReverseStockTransfer::blocker).
 */
class TransferArrivedCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::Stock;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! $document instanceof StockTransfer || ! $document->isReceived() || $document->isReversed() || $action !== DocumentAction::Reverse) {
            return null;
        }

        $sentence = ReverseStockTransfer::blocker($document);

        return $sentence === null ? null : new Blocker('goods_used_at_destination', $sentence);
    }
}
