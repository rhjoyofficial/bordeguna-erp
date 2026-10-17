<?php

namespace App\Support\Dashboards\Widgets;

use App\Events\Dashboards\DashboardEventType;
use App\Modules\Sales\Support\OrderBrand;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\Widget;
use App\Support\Dashboards\WidgetContext;
use Illuminate\Support\Facades\DB;

/**
 * The best sellers in the range by invoiced value (line net, ex-VAT), from the
 * invoices themselves: operational, labelled so.
 */
class TopProductsWidget extends Widget
{
    private const LIMIT = 8;

    public function key(): string
    {
        return 'top_products';
    }

    /** Money on the card: masked with أمان until the eye is tapped (14 Oct 2026). */
    public function sensitive(): bool
    {
        return true;
    }

    public function title(): string
    {
        return 'Top products';
    }

    public function description(): ?string
    {
        return 'Invoiced value per product in the range (line totals after line discounts, before VAT). Opening invoices excluded.';
    }

    public function category(): string
    {
        return 'Sales';
    }

    public function dashboards(): array
    {
        return [DashboardKey::Owner, DashboardKey::Manager];
    }

    public function permission(): ?string
    {
        return 'sales.reports.view';
    }

    public function size(): string
    {
        return 'md';
    }

    public function dateBasis(): ?string
    {
        return 'invoice date';
    }

    public function cacheSeconds(): int
    {
        return 60;
    }

    public function refreshSeconds(): int
    {
        return 120;
    }

    public function drillDown(WidgetContext $context): ?string
    {
        return '/sales/invoices';
    }

    public function refreshOn(): array
    {
        return [DashboardEventType::ORDER_APPROVED];
    }

    /** ⭐ Follows the dashboard's Brand switcher (Joy, 11 Oct 2026). */
    public function brandAware(): bool
    {
        return true;
    }

    public function data(WidgetContext $context): array
    {
        $rows = DB::table('sales_invoice_lines')
            ->join('sales_invoices', 'sales_invoices.id', '=', 'sales_invoice_lines.sales_invoice_id')
            ->join('products', 'products.id', '=', 'sales_invoice_lines.product_id')
            ->where('sales_invoices.business_id', $context->businessId)
            ->where('sales_invoices.is_opening', false)
            // ⭐ Phase 2 (17 Oct 2026): staff purchases at cost are not retail sales.
            ->where('sales_invoices.is_staff_sale', false)
            ->whereBetween('sales_invoices.invoice_date', [$context->range->fromDate(), $context->range->toDate()])
            // ⭐ The brand of the ORDER the line was billed from (through its
            // challan line). A direct invoice has no order, so no brand.
            ->tap(fn ($q) => OrderBrand::onInvoiceLines($q, $context->brandId))
            ->groupBy('products.id', 'products.name')
            ->selectRaw('products.id, products.name, SUM(sales_invoice_lines.line_net) as value, SUM(sales_invoice_lines.quantity) as quantity')
            ->orderByDesc('value')
            ->limit(self::LIMIT)
            ->get();

        return [
            'kind' => 'breakdown',
            'chart' => 'bar',
            'unit' => 'money',
            'items' => $rows->map(fn ($row) => [
                'label' => $row->name,
                'value' => round((float) $row->value, 2),
                'hint' => rtrim(rtrim(number_format((float) $row->quantity, 2, '.', ''), '0'), '.').' sold',
            ])->all(),
            'basis' => 'operational',
        ];
    }
}
