<?php

namespace App\Modules\Inventory\Corrections;

use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ Corrections wave 1, B4: a transfer on the road still has the movements
 * that took it off its batches, so Cancel can put each portion back exactly.
 */
class TransferOnTheRoadCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::Stock;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! $document instanceof StockTransfer || ! $document->isInTransit()) {
            return null;
        }

        $legs = StockMovement::query()->where('transfer_group_id', $document->transfer_group_id)->where('direction', MovementDirection::Out)->exists();

        return $legs ? null : new Blocker('no_movements', "Transfer #{$document->id} has no stock movements. Ask the administrator to check it.", resolvableBy: 'administrator');
    }
}
