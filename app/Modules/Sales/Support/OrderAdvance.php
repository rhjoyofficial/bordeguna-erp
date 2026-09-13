<?php

namespace App\Modules\Sales\Support;

use App\Modules\Accounting\Support\Money;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\CustomerReceiptAllocation;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;

/**
 * Money a customer paid for an order, taken by that order's invoice
 * (SSLCommerz slice P1, Joy 13 September).
 *
 * ⭐⭐ AN ALLOCATION AND NOTHING ELSE. The receipt already credited 1140 when
 * the money arrived and the invoice debits 1140 when it is raised, so the
 * ledger is right the moment both exist; what is missing is only the statement
 * that this money paid this bill. Writing a journal here would move money from
 * 1140 to 1140.
 *
 * ⚠ The caller holds the order's lock. Two invoices billing parts of one order
 * at once must not both take the same money, and the RE-READ of what the order
 * holds happens inside that lock, which is what makes it correct on MySQL -
 * `lockForUpdate` is a no-op on SQLite.
 */
final class OrderAdvance
{
    /**
     * Allocate what the order holds to the invoice, up to what the invoice still
     * owes, oldest receipt first. Returns what was applied.
     */
    public static function applyTo(SalesInvoice $invoice, SalesOrder $order): Money
    {
        if ($invoice->customer_id !== $order->customer_id) {
            return Money::zero();
        }

        $held = $order->advanceHeld();
        $owed = $invoice->outstanding();
        $take = min($held->minor, $owed->minor);

        if ($take <= 0) {
            return Money::zero();
        }

        $remaining = $take;

        $receipts = CustomerReceipt::query()
            ->where('sales_order_id', $order->id)
            ->orderBy('received_on')
            ->orderBy('id')
            ->get();

        foreach ($receipts as $receipt) {
            if ($remaining <= 0) {
                break;
            }

            // ⚠ Per receipt, what no invoice has taken. A refund comes out of the
            // order as a whole, which is why the total taken is bounded by
            // advanceHeld() above and not by the sum of these.
            $part = min($receipt->unallocated()->minor, $remaining);

            if ($part <= 0) {
                continue;
            }

            CustomerReceiptAllocation::create([
                'business_id' => $invoice->business_id,
                'customer_receipt_id' => $receipt->id,
                'sales_invoice_id' => $invoice->id,
                'amount' => Money::ofMinor($part)->toTaka(),
            ]);

            $remaining -= $part;
        }

        return Money::ofMinor($take - $remaining);
    }
}
