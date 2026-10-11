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
            ->whereBetween('invoice_date', [$from, $to])
            ->sum(DB::raw('total - tax_total'));

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
