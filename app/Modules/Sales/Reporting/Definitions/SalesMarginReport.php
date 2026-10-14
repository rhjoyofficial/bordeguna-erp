<?php

namespace App\Modules\Sales\Reporting\Definitions;

use App\Modules\Hrm\Reporting\Contracts\ChartsOnScreen;
use App\Modules\Hrm\Reporting\Contracts\ReportDefinition;
use App\Modules\Hrm\Reporting\ReportColumn;
use App\Modules\Hrm\Reporting\ReportMeta;
use App\Modules\Hrm\Reporting\ReportParameter;
use App\Modules\Hrm\Reporting\ReportParameters;
use App\Modules\Sales\Reporting\Concerns\FiltersByOrderBrand;
use App\Modules\Sales\Reporting\SalesFacts;
use App\Modules\Sales\Support\OrderBrand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Product and brand margin (reports pack, 11 Oct 2026).
 *
 * Net revenue (SalesFacts, the same figure as sales by channel) against the
 * cost of the goods sold, per product or per brand. COGS is the derivation
 * CostOfGoodsSold and the reconciliation already use, `cost_released` less the
 * return's `cogs_relief`, so the cost column ties to the movement on 5100.
 *
 * ⚠ A product sold at ZERO released cost is flagged, not hidden: its margin is
 * 100% only because nobody recorded what it cost (no purchase price, opening
 * stock at nil), and a margin report that showed that as the best product in
 * the range would be believed.
 *
 * ⚠ Margin % is not totalled: a sum of percentages means nothing. The overall
 * margin is in the note, from the totals.
 */
class SalesMarginReport implements ChartsOnScreen, ReportDefinition
{
    use FiltersByOrderBrand;

    public function key(): string
    {
        return 'sales_margin';
    }

    public function name(): string
    {
        return 'Product and brand margin';
    }

    public function description(): string
    {
        return 'Net revenue excluding VAT against cost of goods sold, per product or per brand, with margin and margin %. Flags products sold with no cost recorded. Cost ties to Cost of Goods Sold in the ledger.';
    }

    public function group(): string
    {
        return 'Sales';
    }

    public function permission(): string
    {
        return 'sales.reports.costs';
    }

    public function parameters(): array
    {
        return [
            ReportParameter::dateRange('invoiced', 'Invoiced between', required: false),
            $this->brandParameter(),
            ReportParameter::select('group_by', 'Show', [
                ['value' => 'product', 'label' => 'Per product'],
                ['value' => 'brand', 'label' => 'Per brand'],
            ], default: 'product'),
        ];
    }

    public function columns(?ReportParameters $parameters = null): array
    {
        $first = $this->perBrand($parameters)
            ? [ReportColumn::text('brand', 'Brand', 20), ReportColumn::integer('products', 'Products')]
            : [ReportColumn::text('sku', 'SKU', 14), ReportColumn::text('product', 'Product', 30)];

        return [
            ...$first,
            ReportColumn::decimal('quantity', 'Units (net of returns)'),
            ReportColumn::money('revenue', 'Net revenue, ex-VAT'),
            ReportColumn::money('cogs', 'Cost of goods sold'),
            ReportColumn::money('margin', 'Margin'),
            ReportColumn::decimal('margin_pct', 'Margin %', 9),
            ReportColumn::text('cost_known', 'Cost recorded', 14),
        ];
    }

    public function totalColumns(?ReportParameters $parameters = null): array
    {
        return ['quantity', 'revenue', 'cogs', 'margin'];
    }

    public function rows(ReportParameters $parameters): LazyCollection
    {
        return LazyCollection::make(function () use ($parameters) {
            $perBrand = $this->perBrand($parameters);
            $facts = new SalesFacts(
                businessId: $this->businessId(),
                from: $parameters->from('invoiced'),
                to: $parameters->to('invoiced'),
                brand: $this->brandFilter($parameters),
            );

            $groups = [];

            foreach ($facts->invoiceLines()->concat($facts->returnLines()) as $fact) {
                $key = $perBrand ? (string) ($fact['brand_id'] ?? 'none') : (string) ($fact['product_id'] ?? 'none');

                $groups[$key] ??= [
                    'brand_id' => $fact['brand_id'], 'product_id' => $fact['product_id'], 'products' => [],
                    'quantity' => 0.0, 'revenue' => 0.0, 'cogs' => 0.0, 'sold_lines' => 0, 'zero_cost_lines' => 0,
                ];

                $groups[$key]['quantity'] += $fact['quantity'];
                $groups[$key]['revenue'] += $fact['net'];
                $groups[$key]['cogs'] += $fact['cogs'];

                if ($fact['product_id'] !== null) {
                    $groups[$key]['products'][$fact['product_id']] = true;
                }

                if ($fact['kind'] === 'invoice' && $fact['quantity'] > 0) {
                    $groups[$key]['sold_lines']++;
                    $groups[$key]['zero_cost_lines'] += $fact['zero_cost'] ? 1 : 0;
                }
            }

            $names = $perBrand ? $this->brandNames() : [];
            $products = $perBrand ? [] : $this->products(array_filter(array_column($groups, 'product_id')));

            uasort($groups, fn (array $a, array $b) => ($b['revenue'] - $b['cogs']) <=> ($a['revenue'] - $a['cogs']));

            foreach ($groups as $group) {
                $revenue = round($group['revenue'], 2);
                $cogs = round($group['cogs'], 2);
                $margin = round($revenue - $cogs, 2);

                $first = $perBrand
                    ? ['brand' => self::brandLabel($group['brand_id'], $names), 'products' => count($group['products'])]
                    : [
                        'sku' => $products[$group['product_id']]->sku ?? '',
                        'product' => $products[$group['product_id']]->name ?? 'Unknown product',
                    ];

                yield [
                    ...$first,
                    'quantity' => round($group['quantity'], 4),
                    'revenue' => $revenue,
                    'cogs' => $cogs,
                    'margin' => $margin,
                    'margin_pct' => $revenue == 0.0 ? null : round($margin / $revenue * 100, 2),
                    'cost_known' => match (true) {
                        $group['sold_lines'] === 0 => 'Returns only',
                        $group['zero_cost_lines'] === 0 => 'Yes',
                        $group['zero_cost_lines'] === $group['sold_lines'] => 'No cost recorded',
                        default => 'Partly',
                    },
                ];
            }
        });
    }

    public function estimatedRows(ReportParameters $parameters): int
    {
        if ($this->perBrand($parameters)) {
            return count(OrderBrand::names($this->businessId())) + 1;
        }

        return (int) DB::table('products')->where('business_id', $this->businessId())->count();
    }

    public function countRows(ReportParameters $parameters): int
    {
        return $this->rows($parameters)->count();
    }

    public function meta(ReportParameters $parameters): ReportMeta
    {
        return new ReportMeta(
            title: $this->name(),
            subtitle: $this->periodSubtitle($parameters, 'invoiced', 'All invoiced sales to date'),
            note: 'Basis: invoiced. Net revenue is goods sold excluding VAT, less discounts and returns (Product Sales 4101, under Sales Revenue 4100, + Returns 4200 + Discounts 4210), the same figure as Sales by brand, channel and customer type; delivery charges are not product revenue. '
                .'Cost of goods sold is the cost released by each invoice less the cost given back by returns, the figure posted to Cost of Goods Sold (5100). '
                .'Goods sent but not yet invoiced are not here. "No cost recorded" means the goods left at nil cost, so their margin is not real. '
                .'Brand is the order\'s brand.',
        );
    }

    public function chart(ReportParameters $parameters): ?array
    {
        return [
            'type' => 'bar',
            'title' => 'Revenue, cost and margin',
            'label' => $this->perBrand($parameters) ? 'brand' : 'product',
            'series' => [
                ['key' => 'revenue', 'label' => 'Net revenue'],
                ['key' => 'cogs', 'label' => 'Cost of goods sold'],
                ['key' => 'margin', 'label' => 'Margin'],
            ],
            'limit' => 10,
        ];
    }

    private function perBrand(?ReportParameters $parameters): bool
    {
        return $parameters?->string('group_by') === 'brand';
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, object>
     */
    private function products(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('products')
            ->where('business_id', $this->businessId())
            ->whereIn('id', array_values(array_unique($ids)))
            ->get(['id', 'sku', 'name'])
            ->keyBy('id')
            ->all();
    }
}
