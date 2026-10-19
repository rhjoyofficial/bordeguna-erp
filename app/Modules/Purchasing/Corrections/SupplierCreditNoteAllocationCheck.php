<?php

namespace App\Modules\Purchasing\Corrections;

use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierCreditNote;
use App\Modules\Purchasing\Models\SupplierCreditNoteAllocation;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ Corrections wave 1 (20 Oct 2026): a credit note's allocations are still
 * on live bills of its supplier. Reversing it reopens those bills by exactly
 * what it credited; a credit standing on a reversed bill is a data problem a
 * person must look at, never undone by a guess.
 */
class SupplierCreditNoteAllocationCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::Allocation;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! $action->isCorrection() || ! $document instanceof SupplierCreditNote || $document->isReversed()) {
            return null;
        }

        $billIds = SupplierCreditNoteAllocation::query()->where('supplier_credit_note_id', $document->id)->pluck('supplier_bill_id');

        if (SupplierBill::query()->whereIn('id', $billIds)->whereNotNull('reversed_at')->exists()) {
            return new Blocker('allocations_changed', "Credit note {$document->credit_note_number} still credits a reversed bill. Ask the administrator to check it.", resolvableBy: 'administrator');
        }

        return null;
    }
}
