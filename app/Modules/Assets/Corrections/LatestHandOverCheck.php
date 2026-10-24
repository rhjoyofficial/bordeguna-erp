<?php

namespace App\Modules\Assets\Corrections;

use App\Modules\Assets\Actions\ReverseAssetMovement;
use App\Modules\Assets\Models\AssetMovement;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ Corrections wave 3, B11 (24 Oct 2026): the custody check of a hand-over
 * (v2, C14: every declared effect has a check). Custody can only be put back
 * while nothing happened to the things since: the hand-over is still the
 * latest movement of each, inside 7 days, recorded with its "before".
 */
final class LatestHandOverCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::Custody;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        /** @var AssetMovement $document */
        $sentence = $action === DocumentAction::Cancel
            ? ReverseAssetMovement::cancelBlocker($document)
            : ReverseAssetMovement::blocker($document);

        return $sentence === null ? null : new Blocker('custody_moved_on', $sentence);
    }
}
