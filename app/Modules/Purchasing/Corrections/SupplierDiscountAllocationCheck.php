<?php

namespace App\Modules\Purchasing\Corrections;

use App\Modules\Accounting\Models\PartyAdjustmentAllocation;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Purchasing\Models\SupplierDiscount;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ A discount's allocation is still exactly what it posted (18 Oct 2026).
 *
 * Reversing a discount reopens its bill by removing the party adjustment it
 * keeps. That is only honest while the adjustment is there, on the discount's
 * own bill, for the discount's own amount: anything else is a data problem a
 * person must look at, never undone by a guess.
 */
class SupplierDiscountAllocationCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::Allocation;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! $action->isCorrection() || ! $document instanceof SupplierDiscount || $document->isReversed()) {
            return null;
        }

        $allocated = $document->party_adjustment_id === null
            ? 0
            : Money::ofTaka((string) (PartyAdjustmentAllocation::query()
                ->where('party_adjustment_id', $document->party_adjustment_id)
                ->where('supplier_bill_id', $document->supplier_bill_id)
                ->sum('amount') ?: '0'))->minor;

        if ($allocated === $document->money()->minor) {
            return null;
        }

        return new Blocker(
            'allocations_changed',
            "The bill {$document->number} reduced no longer matches it. Ask the administrator to check it.",
            resolvableBy: 'administrator',
        );
    }
}
