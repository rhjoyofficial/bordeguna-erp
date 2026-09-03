<?php

namespace App\Modules\Sales\Reporting\Definitions;

use App\Modules\Hrm\Reporting\Concerns\CountsRows;
use App\Modules\Hrm\Reporting\Contracts\ReportDefinition;
use App\Modules\Hrm\Reporting\ReportColumn;
use App\Modules\Hrm\Reporting\ReportMeta;
use App\Modules\Hrm\Reporting\ReportParameter;
use App\Modules\Hrm\Reporting\ReportParameters;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Sales by brand (Joy, 3 Sep 2026, the interview).
 *
 * His words: LaLaDia, Bionic and the rest are BRANDS - "all transactions
 * happen in Bor de Guna, products selling via brand, there is a calculation
 * how much we sell from brand." This report IS that calculation: invoiced
 * sales grouped by the brand each product carries, for any period.
 *
 * ⚠ It reads invoice lines, it re-derives nothing: quantity and line_net as
 * the invoices recorded them, joined to the product's brand. A product with
 * no brand shows under "No brand" rather than disappearing - a bucket that
 * hides rows would make the brand totals stop footing to total sales.
 *
 * ⚠ Costs are NOT split by brand, deliberately - Joy: hosting and storage
 * are shared, "not calculate for each brand how much use". This is a SALES
 * calculation, and says so.
 */
class SalesByBrandReport implements ReportDefinition
{
    use CountsRows;

    public function key(): string
    {
        return 'sales_by_brand';
    }

    public function name(): string
    {
        return 'Sales by brand';
    }

    public function description(): string
    {
        return 'How much each brand sold in a period - quantity and invoiced value, from the invoices themselves. Costs are shared and deliberately not split by brand.';
    }

    public function group(): string
    {
        return 'Sales';
    }

    public function permission(): string
    {
        return 'sales.reports.view';
    }

    public function parameters(): array
    {
        return [
            ReportParameter::dateRange('invoiced', 'Invoiced between', required: false),
        ];
    }

    public function columns(?ReportParameters $parameters = null): array
    {
        return [
            ReportColumn::text('brand', 'Brand', 24),
            ReportColumn::integer('products_sold', 'Distinct products'),
            ReportColumn::decimal('quantity', 'Units'),
            ReportColumn::money('revenue', 'Invoiced value'),
        ];
    }

    public function totalColumns(?ReportParameters $parameters = null): array
    {
        return ['quantity', 'revenue'];
    }

    public function rows(ReportParameters $parameters): LazyCollection
    {
        return LazyCollection::make(function () use ($parameters) {
            foreach ($this->query($parameters)->orderByDesc('revenue')->cursor() as $row) {
                yield [
                    'brand' => $row->brand ?? 'No brand',
                    'products_sold' => (int) $row->products_sold,
                    'quantity' => round((float) $row->quantity, 2),
                    'revenue' => round((float) $row->revenue, 2),
                ];
            }
        });
    }

    public function estimatedRows(ReportParameters $parameters): int
    {
        return $this->query($parameters)->getCountForPagination();
    }

    public function meta(ReportParameters $parameters): ReportMeta
    {
        $from = $parameters->from('invoiced');
        $to = $parameters->to('invoiced');

        return new ReportMeta(
            title: 'Sales by brand',
            subtitle: $from && $to
                ? $from->format('d M Y').' to '.$to->format('d M Y')
                : 'All invoiced sales to date',
            periodLocked: null,
            lockNote: 'Sales only, from the invoices as recorded. Shared costs (hosting, storage) are deliberately not split by brand.',
        );
    }

    private function query(ReportParameters $parameters): Builder
    {
        return DB::table('sales_invoice_lines')
            // ⚠ Raw tables carry no BusinessScope - the tenant filter must be
            // said out loud, or a second business's sales would leak in.
            ->where('sales_invoice_lines.business_id', app(\App\Modules\Foundation\Support\BusinessContext::class)->id())
            ->join('sales_invoices', 'sales_invoices.id', '=', 'sales_invoice_lines.sales_invoice_id')
            ->join('products', 'products.id', '=', 'sales_invoice_lines.product_id')
            ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
            ->when(
                $parameters->from('invoiced'),
                fn (Builder $q, $from) => $q->whereDate('sales_invoices.invoice_date', '>=', $from->toDateString()),
            )
            ->when(
                $parameters->to('invoiced'),
                fn (Builder $q, $to) => $q->whereDate('sales_invoices.invoice_date', '<=', $to->toDateString()),
            )
            ->groupBy('brands.name')
            ->selectRaw('brands.name as brand')
            ->selectRaw('COUNT(DISTINCT products.id) as products_sold')
            ->selectRaw('SUM(sales_invoice_lines.quantity) as quantity')
            ->selectRaw('SUM(sales_invoice_lines.line_net) as revenue');
    }
}
