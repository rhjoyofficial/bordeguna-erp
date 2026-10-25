<?php

namespace App\Modules\Sales\Corrections\Checks;

use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Sales\Actions\Corrections\ReverseSampleIssue;
use App\Modules\Sales\Models\SampleIssue;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ Samples (25 Oct 2026): a given sample's goods can go back onto their
 * batches only at the cost they left at (ReverseSampleIssue::blocker).
 */
class SampleStockCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::Stock;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! $document instanceof SampleIssue || ! $document->isPosted() || $document->isReversed()) {
            return null;
        }

        $sentence = ReverseSampleIssue::blocker($document);

        return $sentence === null ? null : new Blocker('goods_cannot_go_back', $sentence);
    }
}
