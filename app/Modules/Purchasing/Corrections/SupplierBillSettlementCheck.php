<?php

namespace App\Modules\Purchasing\Corrections;

use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Purchasing\Actions\ReverseSupplierBill;
use App\Modules\Purchasing\Models\SupplierBill;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ Corrections wave 1 (20 Oct 2026): what settled a supplier bill, and
 * whether that lets it be reversed or corrected.
 *
 *   Reverse   refused once anything paid or credited it: the bill would be
 *             reversed with the payment still standing on it (ReverseSupplierBill)
 *   Correct   payments and credit notes MOVE to the corrected bill ("Correct
 *             paid bill", ChatGPT ruling 7: the payment is reversed only when
 *             the payment itself was wrong), so they do not block
 *   both      a live supplier discount, an applied advance, a set-off, or
 *             carriage sent on to a landed cost: each is its own document with
 *             its own effect (stock cost, 1160, the customer side) and is
 *             reversed on its own first
 *
 * ⚠ The sentences come from ReverseSupplierBill::refusal, so the button and
 * the action can never disagree about why.
 */
class SupplierBillSettlementCheck implements DependencyCheck
{
    public function __construct(private readonly ReverseSupplierBill $reverse) {}

    public function covers(): EffectKind
    {
        return EffectKind::Allocation;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! $action->isCorrection() || ! $document instanceof SupplierBill || $document->isReversed()) {
            return null;
        }

        $settled = $document->paidTotal()->minor + $document->creditedTotal()->minor;

        if ($action === DocumentAction::Reverse && $settled > 0) {
            return new Blocker(
                'bill_settled',
                "Bill {$document->bill_number} has been paid or credited. Use Correct to move the payment to a corrected bill, or reverse the payment first.",
            );
        }

        $refusal = $this->reverse->refusal($document, settlementsMove: true);

        return $refusal === null ? null : new Blocker('bill_not_reversible', $refusal);
    }
}
