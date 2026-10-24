<?php

namespace App\Modules\Sales\Corrections\Checks;

use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\CourierLossClaim;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ B13 (24 Oct 2026): what a return's credit already did, which reversing it
 * would leave hanging.
 *
 *   - money went back to the customer for it (a refund, at the counter or
 *     later): that money is real and outside the ERP now (FINAL-PLAN "no money
 *     already sent back");
 *   - a courier brought it back (a parcel outcome, a loss claim): the
 *     courier's answer owns it;
 *   - an invoice it credited has since been credited in full.
 */
class ReturnMoneyCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::Allocation;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! $document instanceof SalesReturn || $document->isReversed() || $document->isMirror() || ! $action->isCorrection()) {
            return null;
        }

        if ($document->refunds()->exists()) {
            return new Blocker('refunded', "Money for {$document->return_number} went back to the customer, so the return cannot be reversed.");
        }

        // ⭐ Integration (25 Oct 2026): credit kept as the customer's advance
        // is reversed there first (Sales > Advances), then the return.
        $advance = $document->advances()->whereNull('reversed_at')->value('number');

        if ($advance !== null) {
            return new Blocker('kept_as_advance', "{$document->return_number}'s credit is kept as advance {$advance}. Reverse the advance first.");
        }

        if (Consignment::query()->where('sales_return_id', $document->id)->exists()
            || CourierLossClaim::query()->where('sales_return_id', $document->id)->exists()) {
            return new Blocker('courier_return', "{$document->return_number} came back through a courier, so the parcel's own answer corrects it.");
        }

        $credited = SalesInvoice::query()
            ->whereIn('id', $document->allocations()->select('sales_invoice_id'))
            ->whereNotNull('reversed_at')
            ->value('invoice_number');

        if ($credited !== null) {
            return new Blocker('invoice_credited', "Invoice {$credited}, which {$document->return_number} credited, has itself been credited since.");
        }

        return null;
    }
}
