<?php

namespace App\Modules\Sales\Corrections\Checks;

use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Sales\Models\CustomerAdvance;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ B11 (24 Oct 2026): a customer advance is undone only in DEPENDENCY ORDER.
 *
 *   - an advance that an invoice used, or that was partly paid back, is
 *     refused until those uses and refunds are reversed: otherwise the invoice
 *     would stay paid by money the ledger no longer holds;
 *   - money moved from a cancelled order goes back to that order, which must
 *     still be finished for billing (it always is: a cancelled order stays so).
 *
 * A use on an invoice and a refund depend on nothing: reversing them only
 * gives the advance back. They declare the same effect and pass here.
 */
class CustomerAdvanceUseCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::Allocation;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! $document instanceof CustomerAdvance || $document->isReversed() || ! $action->isCorrection()) {
            return null;
        }

        $used = $document->applications()->live()->pluck('number')
            ->concat($document->refunds()->live()->pluck('number'));

        if ($used->isNotEmpty()) {
            return new Blocker(
                'advance_used',
                "Advance {$document->number} has been used on {$used->implode(', ')}; reverse those first.",
            );
        }

        if ($document->isFromOrder() && $document->sales_order_id !== null) {
            $order = SalesOrder::query()->find($document->sales_order_id);

            if ($order !== null && ! $order->isFinishedForBilling()) {
                return new Blocker('order_open', "Order {$order->order_number} is open again, so its money cannot go back to it now.");
            }
        }

        return null;
    }
}
