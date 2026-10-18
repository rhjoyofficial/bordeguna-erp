<?php

namespace App\Modules\Purchasing\Support;

use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierCreditNote;

/**
 * Which supplier a Goods Received Not Invoiced line names, if any.
 *
 * ⭐ Supplier ledger (A1, ChatGPT ruling P1, 18 Oct 2026): from now on every
 * GRNI line a purchase posts names the supplier as its party - the receipt's
 * credit, the bill's debit, a return's debit and a credit note's credit - so
 * the supplier ledger can show "received from them, not yet billed" and the
 * owner never reads a zero balance while a delivery is still owed for.
 *
 * ⚠⚠ ONE ORDER, ONE WAY. A purchase order whose earlier receipts posted GRNI
 * with NO party (everything before this change) keeps posting untagged until
 * it is done. Tagging only the later half would leave the supplier's GRNI
 * section with a bill's debit and no receipt's credit to clear (a negative
 * "received not billed"), while the untagged pool kept the receipt it never
 * relieves: two wrong figures where there was one honest gap. Those orders
 * show in the "GRNI not tagged to a supplier" report instead, through the
 * exact receipt -> order -> supplier link, and posted lines are never
 * rewritten (they are immutable).
 */
final class GrniParty
{
    /** The supplier a GRNI line for this order names, or null while the order is still untagged. */
    public static function forOrder(?PurchaseOrder $order, int $grniAccountId): ?Supplier
    {
        if ($order === null || self::orderIsUntagged($order, $grniAccountId)) {
            return null;
        }

        return $order->supplier;
    }

    /**
     * A bill's GRNI relief names its supplier unless any order behind it is
     * still on the untagged path (see the class note).
     */
    public static function forBill(SupplierBill $bill, int $grniAccountId): ?Supplier
    {
        $orderIds = PurchaseOrderLine::query()
            ->whereIn('id', $bill->lines()->whereNotNull('purchase_order_line_id')->pluck('purchase_order_line_id'))
            ->pluck('purchase_order_id')
            ->push($bill->purchase_order_id)
            ->filter()
            ->unique();

        foreach (PurchaseOrder::query()->whereIn('id', $orderIds)->get() as $order) {
            if (self::orderIsUntagged($order, $grniAccountId)) {
                return null;
            }
        }

        return $bill->supplier;
    }

    /**
     * A credit note moves a late return's GRNI claim onto Accounts Payable, so
     * it follows the return's order; one with no return behind it names the
     * supplier.
     */
    public static function forCreditNote(SupplierCreditNote $creditNote, int $grniAccountId): ?Supplier
    {
        $order = $creditNote->purchaseReturn?->purchaseOrder;

        if ($order !== null && self::orderIsUntagged($order, $grniAccountId)) {
            return null;
        }

        return $creditNote->supplier;
    }

    /** True when a receipt of this order already put an untagged line on GRNI. */
    public static function orderIsUntagged(PurchaseOrder $order, int $grniAccountId): bool
    {
        $receiptIds = GoodsReceipt::query()->where('purchase_order_id', $order->id)->pluck('id');

        if ($receiptIds->isEmpty()) {
            return false;
        }

        return JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.account_id', $grniAccountId)
            ->whereNull('journal_lines.party_id')
            ->where('journals.source_type', (new GoodsReceipt)->getMorphClass())
            ->whereIn('journals.source_id', $receiptIds)
            ->exists();
    }
}
