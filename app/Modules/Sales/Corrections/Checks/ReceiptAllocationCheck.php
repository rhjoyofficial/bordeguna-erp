<?php

namespace App\Modules\Sales\Corrections\Checks;

use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Sales\Corrections\SalesDependencies;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ What a customer receipt paid, and whether taking it back is honest
 * (corrections wave 1, 20 Oct 2026).
 *
 *   - an invoice it pays is cleared by a set-off: refused until the set-off is
 *     reversed (SalesDependencies, Joy's brief);
 *   - money paid for an ORDER that the order has since paid back (a refund by
 *     hand or through SSLCommerz): taking the receipt away would leave the
 *     order holding less than nothing, so it is refused.
 *
 * Re-allocate asks the first rule for the invoices it moves money off.
 */
class ReceiptAllocationCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::Allocation;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! $document instanceof CustomerReceipt || $document->isReversed() || ! in_array($action, [DocumentAction::Reverse, DocumentAction::Correct], true)) {
            return null;
        }

        $invoiceIds = $document->allocations()->pluck('sales_invoice_id')->map(fn ($id) => (int) $id)->all();

        $what = $action === DocumentAction::Correct ? 'correcting this receipt' : 'reversing this receipt';

        if (($setOff = SalesDependencies::setOffBlocker($invoiceIds, $what)) !== null) {
            return $setOff;
        }

        if ($document->sales_order_id !== null) {
            $order = SalesOrder::query()->find($document->sales_order_id);
            $unallocated = $document->unallocated();

            // What the order would hold without this receipt's unapplied part.
            if ($order !== null && $order->advanceHeld()->minor - $unallocated->minor < 0) {
                return new Blocker(
                    'advance_refunded',
                    "Order {$order->order_number} already paid back part of this money to the customer, so the receipt cannot be taken away.",
                    resolvableBy: 'accountant',
                );
            }
        }

        return null;
    }

    /** The paisa a receipt has on each invoice, keyed by invoice id. @return array<int, int> */
    public static function byInvoice(CustomerReceipt $receipt): array
    {
        $out = [];

        foreach ($receipt->allocations()->get(['sales_invoice_id', 'amount']) as $allocation) {
            $id = (int) $allocation->sales_invoice_id;
            $out[$id] = ($out[$id] ?? 0) + Money::ofTaka((string) $allocation->amount)->minor;
        }

        return $out;
    }
}
