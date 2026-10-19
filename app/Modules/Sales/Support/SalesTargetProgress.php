<?php

namespace App\Modules\Sales\Support;

use Illuminate\Support\Facades\DB;

/**
 * What one seller actually sold in a window, measured the way the sales
 * targets screen measures it: invoices they raised, ex-VAT, minus the returns
 * against those invoices. Shared since 11 Oct 2026 so the targets screen and
 * the sales dashboard can never disagree about the same month.
 *
 * ⚠ Operational (documents), not the ledger: a target is per person and the
 * ledger does not know who raised an invoice.
 */
final class SalesTargetProgress
{
    public static function actualFor(int $businessId, int $userId, string $from, string $to): float
    {
        $invoiced = (float) DB::table('sales_invoices')
            ->where('business_id', $businessId)
            ->where('created_by', $userId)
            ->where('is_opening', false)
            ->whereNull('reversed_at')
            // ⭐ Phase 2 (17 Oct 2026): handing a colleague their staff purchase
            // is not a sale towards anybody's target.
            ->where('is_staff_sale', false)
            ->where('is_owner_sale', false)
            ->whereBetween('invoice_date', [$from, $to])
            ->sum(DB::raw('total - tax_total'));

        // ⚠ Bug hunt 16 Oct 2026: a delivery charge is not a sale. It rides
        // on the invoice total as a line of its own, and returns (goods only)
        // never took it back, so a seller's progress counted the courier fee.
        $deliveryCharges = (float) DB::table('sales_invoice_lines')
            ->join('sales_invoices', 'sales_invoices.id', '=', 'sales_invoice_lines.sales_invoice_id')
            ->where('sales_invoices.business_id', $businessId)
            ->where('sales_invoices.created_by', $userId)
            ->where('sales_invoices.is_opening', false)
            ->whereNull('sales_invoices.reversed_at')
            ->whereBetween('sales_invoices.invoice_date', [$from, $to])
            ->whereNotNull('sales_invoice_lines.billed_delivery_for_order_id')
            ->sum('sales_invoice_lines.line_net');

        $invoiced -= $deliveryCharges;

        $returned = (float) DB::table('sales_return_invoice_lines as returned')
            ->join('sales_return_lines', 'sales_return_lines.id', '=', 'returned.sales_return_line_id')
            ->join('sales_returns', 'sales_returns.id', '=', 'sales_return_lines.sales_return_id')
            ->join('sales_invoice_lines', 'sales_invoice_lines.id', '=', 'returned.sales_invoice_line_id')
            ->join('sales_invoices', 'sales_invoices.id', '=', 'sales_invoice_lines.sales_invoice_id')
            ->where('returned.business_id', $businessId)
            ->where('sales_invoices.created_by', $userId)
            ->whereBetween('sales_returns.returned_at', [$from, $to])
            ->sum('returned.net');

        return round($invoiced - $returned, 2);
    }
}
