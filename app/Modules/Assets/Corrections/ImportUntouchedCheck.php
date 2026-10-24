<?php

namespace App\Modules\Assets\Corrections;

use App\Modules\Assets\Actions\ReverseAssetImport;
use App\Modules\Assets\Models\AssetImportBatch;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ Corrections wave 3, B11 (24 Oct 2026): the custody check of an import
 * (v2, C14). A reversal goes ahead when at least one of the sheet's things is
 * still untouched; the used ones are kept and listed, never forced.
 */
final class ImportUntouchedCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::Custody;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        /** @var AssetImportBatch $document */
        $plan = app(ReverseAssetImport::class)->plan($document);

        return $plan['remove'] === [] && $plan['keep'] !== []
            ? new Blocker('all_things_used', "Every thing of import {$document->number} has been used since. Handle them one by one.")
            : null;
    }
}
