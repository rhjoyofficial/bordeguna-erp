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
 * ⭐ No live supplier credit note settles the return (FINAL-PLAN 3.3, "No
 * supplier credit note uses it"): the note is reversed first.
 */
class PurchaseReturnCreditCheck implements DependencyCheck
{
    public function __construct(private readonly ReversePurchaseReturn $reverse) {}

    public function covers(): EffectKind
    {
        return EffectKind::Allocation;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! $action->isCorrection() || ! $document instanceof PurchaseReturn || $document->isReversed()) {
            return null;
        }

        $refusal = $this->reverse->creditRefusal($document);

        return $refusal === null ? null : new Blocker('return_credited', $refusal);
    }
}
