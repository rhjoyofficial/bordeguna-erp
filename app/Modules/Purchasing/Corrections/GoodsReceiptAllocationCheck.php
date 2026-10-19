<?php

namespace App\Modules\Purchasing\Corrections;

use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\SupplierBillLine;
use App\Modules\Purchasing\Models\SupplierDiscount;
use App\Modules\Purchasing\Support\PurchaseLineEntry;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ What hangs off a receipt's lines (corrections wave 1, 19 Oct 2026):
 * landed costs, supplier discounts and supplier bills.
 *
 *  - A landed cost or a supplier discount moved value onto (or off) the
 *    batches this receipt made. Neither has a reversal that puts the receipt
 *    back as it was, so Reverse and Correct both wait until it is gone: a
 *    waiting landed cost is deleted or finished, a discount reversed, a posted
 *    landed cost goes to Finance. Refused in a sentence that says which.
 *  - A bill: Reverse waits for the bill to be reversed (the goods would leave
 *    while the supplier is still owed for them). A Correct of the DATE keeps
 *    the bill and moves its link to the corrected receipt; a Correct of the
 *    quantity or the lot asks `billed()` itself.
 */
class GoodsReceiptAllocationCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::Allocation;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! $action->isCorrection() || ! $document instanceof GoodsReceipt || $document->isReversed()) {
            return null;
        }

        $lineIds = $document->lines()->pluck('id')->all();

        $landed = LandedCost::query()
            ->where(fn ($q) => $q->where('goods_receipt_id', $document->id)
                ->orWhereHas('lines', fn ($l) => $l->whereIn('goods_receipt_line_id', $lineIds)))
            ->orderBy('id')
            ->first();

        if ($landed !== null) {
            return $landed->isPosted()
                ? new Blocker('landed_cost_posted', "Landed cost {$landed->number} was posted on this receipt, so the receipt cannot be changed here. Ask Finance to correct it.", resolvableBy: 'finance')
                : new Blocker('landed_cost_draft', "Landed cost {$landed->number} is still open on this receipt. Delete or finish it first.");
        }

        $discount = SupplierDiscount::query()
            ->whereNull('reversed_at')
            ->whereHas('allocations', fn ($a) => $a->whereIn('goods_receipt_line_id', $lineIds))
            ->orderBy('id')
            ->first();

        if ($discount !== null) {
            return new Blocker('discount_on_receipt', "Supplier discount {$discount->number} lowered the cost of these goods. Reverse the discount first.");
        }

        if ($action === DocumentAction::Reverse && ($sentence = self::billed($document)) !== null) {
            return new Blocker('billed', $sentence);
        }

        return null;
    }

    /**
     * Null while no live bill covers the receipt; otherwise the one sentence.
     * A bill names the receipt line (since 19 Oct 2026), or an older bill on
     * the order line leaves less unbilled than this receipt brought.
     */
    public static function billed(GoodsReceipt $receipt): ?string
    {
        $receipt->loadMissing('lines');
        $lineIds = $receipt->lines->pluck('id')->all();

        $named = SupplierBillLine::query()
            ->whereIn('goods_receipt_line_id', $lineIds)
            ->whereHas('bill', fn ($bill) => $bill->whereNull('reversed_at'))
            ->with('bill:id,bill_number')
            ->first();

        if ($named !== null) {
            return "Bill {$named->bill?->bill_number} covers this receipt. Reverse the bill first, or correct only the date.";
        }

        foreach ($receipt->lines as $line) {
            $orderLine = PurchaseOrderLine::query()->find($line->purchase_order_line_id);

            if ($orderLine === null) {
                continue;
            }

            $left = PurchaseLineEntry::scaled((string) $orderLine->quantity_received)
                - PurchaseLineEntry::scaled((string) $orderLine->quantity_returned)
                - PurchaseLineEntry::scaled((string) $line->quantity_received);

            if (PurchaseLineEntry::scaled((string) $orderLine->quantity_invoiced) > $left) {
                return 'The order is already billed for these goods. Reverse the bill first, or correct only the date.';
            }
        }

        return null;
    }
}
