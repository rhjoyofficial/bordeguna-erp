<?php

namespace App\Modules\Purchasing\Corrections;

use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Purchasing\Actions\ReverseSupplierAdvance;
use App\Modules\Purchasing\Models\SupplierAdvance;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierDiscount;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Models\SupplierPaymentAllocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Corrections wave 1 (20 Oct 2026): what a supplier payment settled, and
 * whether undoing it is honest (the same refusals ReverseSupplierPayment has,
 * as sentences before the click rather than after it).
 *
 *   a voucher made it   the voucher is what is reversed (finance decision C3)
 *   a discount with it  the settlement discount took cost off batches; it is
 *                       reversed on its own first (ruling P3)
 *   its advance used    the excess kept as an advance was applied or refunded
 *                       (ruling P2)
 *   a bill it paid is gone   a data problem a person must look at
 */
class SupplierPaymentSettlementCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::Allocation;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! $action->isCorrection() || ! $document instanceof SupplierPayment || $document->isReversed()) {
            return null;
        }

        $label = "Payment #{$document->id}";

        if ($document->voucher_id !== null) {
            return new Blocker('voucher_payment', "{$label} was made by a payment voucher. Reverse the voucher under Accounting, Vouchers; the payment goes with it.");
        }

        $discount = SupplierDiscount::query()->where('supplier_payment_id', $document->id)->whereNull('reversed_at')->value('number');

        if ($discount !== null) {
            return new Blocker('payment_discount', "{$label} took settlement discount {$discount}. Reverse the discount on its bill first.");
        }

        $advance = SupplierAdvance::query()->where('supplier_payment_id', $document->id)->where('kind', 'overpayment')->live()->first();

        if ($advance !== null) {
            try {
                ReverseSupplierAdvance::assertNothingDependsOn($advance);
            } catch (ValidationException $e) {
                return new Blocker('advance_used', collect($e->errors())->flatten()->first() ?? "Advance {$advance->number} has been used.");
            }
        }

        $billIds = SupplierPaymentAllocation::query()->where('supplier_payment_id', $document->id)->pluck('supplier_bill_id');

        if (SupplierBill::query()->whereIn('id', $billIds)->whereNotNull('reversed_at')->exists()) {
            return new Blocker('allocations_changed', "{$label} still settles a reversed bill. Ask the administrator to check it.", resolvableBy: 'administrator');
        }

        return null;
    }
}
