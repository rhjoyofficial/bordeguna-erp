<?php

namespace App\Modules\Accounting\Corrections;

use App\Modules\Accounting\Models\PartyAdjustmentAllocation;
use App\Modules\Accounting\Models\SetOff;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ A set-off's allocations are still exactly what it posted (18 Oct 2026).
 *
 * Reversing a set-off reopens its bills and invoices by removing the `set_off`
 * party adjustment it posted. That is only honest while the adjustment is
 * there and still allocates the set-off's own amount: one that went missing or
 * changed is a data problem a person must look at, never something to undo
 * by a guess.
 */
class SetOffAllocationCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::Allocation;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! $action->isCorrection() || ! $document instanceof SetOff || $document->isReversed() || ! $document->isPosted()) {
            return null;
        }

        $allocated = $document->party_adjustment_id === null
            ? 0
            : Money::ofTaka((string) (PartyAdjustmentAllocation::query()->where('party_adjustment_id', $document->party_adjustment_id)->sum('amount') ?: '0'))->minor;

        // Each side carries the whole amount: the bills once, the invoices once.
        if ($allocated === 2 * $document->money()->minor) {
            return null;
        }

        return new Blocker(
            'allocations_changed',
            "The bills and invoices {$document->number} cleared no longer match it. Ask the administrator to check it.",
            resolvableBy: 'administrator',
        );
    }
}
