<?php

namespace App\Modules\Accounting\Corrections;

use App\Modules\Accounting\Actions\ReverseOwnerWithdrawal;
use App\Modules\Accounting\Models\OwnerWithdrawal;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ Initial plan B24 (25 Oct 2026): a posted owner withdrawal's goods can go
 * back onto their batches only at the cost they left at
 * (ReverseOwnerWithdrawal::blocker).
 */
class OwnerWithdrawalStockCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::Stock;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! $document instanceof OwnerWithdrawal || ! $document->isPosted() || $document->isReversed()) {
            return null;
        }

        $sentence = ReverseOwnerWithdrawal::blocker($document);

        return $sentence === null ? null : new Blocker('goods_cannot_go_back', $sentence);
    }
}
