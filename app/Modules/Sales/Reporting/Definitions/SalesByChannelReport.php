<?php

namespace App\Modules\Sales\Reporting\Definitions;

use App\Modules\Hrm\Reporting\Contracts\ChartsOnScreen;
use App\Modules\Hrm\Reporting\Contracts\ReportDefinition;
use App\Modules\Hrm\Reporting\ReportColumn;
use App\Modules\Hrm\Reporting\ReportMeta;
use App\Modules\Hrm\Reporting\ReportParameter;
use App\Modules\Hrm\Reporting\ReportParameters;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Reporting\Concerns\FiltersByOrderBrand;
use App\Modules\Sales\Reporting\SalesFacts;
use App\Modules\Sales\Support\OrderBrand;
use Illuminate\Support\LazyCollection;

/**
 * Sales by brand, channel and customer type (reports pack, 11 Oct 2026).
 *
 * The audit's first gap: nothing answered "how much did LaLaDia sell on the
 * website to retail customers this month". Basis INVOICED, net of discounts
 * and returns, excluding VAT, from SalesFacts, so the net revenue column ties
 * to the period movement on 4110 Product Sales + 4200 Returns + 4210 Discounts.
 *
 * ⚠ Brand is the ORDER's (Joy, 11 Oct: each order carries its brand), not the
 * product's: the older `sales_by_brand` report groups by the product's brand
 * and the two answer different questions. A sale with no order is channel
 * Counter and brand "No brand".
 *
 * ⚠ Delivery charges billed are shown beside the revenue, never inside it:
 * they are 4300 Delivery Charges Income, their own line on the P&L.
 */
class SalesByChannelReport implements ChartsOnScreen, ReportDefinition
{
    use FiltersByOrderBrand;

    private const GROUPS = [
        'all' => 'Brand, channel and customer type',
        'brand' => 'Brand only',
        'channel' => 'Channel only',
        'customer_type' => 'Customer type only',
    ];

    public function key(): string
    {
        return 'sales_by_channel';
    }

    public function name(): string
    {
        return 'Sales by brand, channel and customer type';
    }

    public function description(): string
    {
        return 'Invoiced revenue excluding VAT, net of discounts and returns, by the order\'s brand, the channel it came through and the customer type. Ties to Sales, Returns and Discounts in the ledger; delivery charges shown apart.';
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
        $channels = [];

        foreach (SalesFacts::CHANNELS as $value => $label) {
            $channels[] = ['value' => $value, 'label' => $label];
        }

        $groups = [];

        foreach (self::GROUPS as $value => $label) {
            $groups[] = ['value' => $value, 'label' => $label];
        }

        return [
            ReportParameter::dateRange('invoiced', 'Invoiced between', required: false),
            $this->brandParameter(),
            ReportParameter::select('channel', 'Channel', $channels),
            ReportParameter::select('group_by', 'Show', $groups, default: 'all'),
        ];
    }

    public function columns(?ReportParameters $parameters = null): array
    {
        return [
            ReportColumn::text('brand', 'Brand', 18),
            ReportColumn::text('channel', 'Channel', 18),
            ReportColumn::text('customer_type', 'Customer type', 16),
            ReportColumn::integer('invoices', 'Invoices'),
            ReportColumn::decimal('quantity', 'Units (net of returns)'),
            ReportColumn::money('gross', 'Goods sold, ex-VAT'),
            ReportColumn::money('discount', 'Discounts'),
            ReportColumn::money('returns', 'Returns'),
            ReportColumn::money('net_revenue', 'Net revenue'),
            ReportColumn::money('delivery_charges', 'Delivery charges billed (not in revenue)', 18),
        ];
    }

    public function totalColumns(?ReportParameters $parameters = null): array
    {
        return ['quantity', 'gross', 'discount', 'returns', 'net_revenue', 'delivery_charges'];
    }

    public function rows(ReportParameters $parameters): LazyCollection
    {
        return LazyCollection::make(function () use ($parameters) {
            $facts = $this->facts($parameters);
            $group = $this->groupBy($parameters);
            $names = $this->brandNames();
            $groups = [];

            $bucket = function (array $fact) use (&$groups, $group, $names): string {
                $brand = in_array($group, ['all', 'brand'], true) ? self::brandLabel($fact['brand_id'], $names) : 'All brands';
                $channel = in_array($group, ['all', 'channel'], true) ? SalesFacts::channelLabel($fact['channel']) : 'All channels';
                $type = in_array($group, ['all', 'customer_type'], true) ? self::customerTypeLabel($fact['customer_type']) : 'All customers';
                $key = $brand."\0".$channel."\0".$type;

                $groups[$key] ??= [
                    'brand' => $brand, 'channel' => $channel, 'customer_type' => $type,
                    'invoices' => [], 'quantity' => 0.0, 'gross' => 0.0, 'discount' => 0.0,
                    'returns' => 0.0, 'net_revenue' => 0.0, 'delivery_charges' => 0.0,
                ];

                return $key;
            };

            foreach ($facts->invoiceLines()->concat($facts->returnLines()) as $fact) {
                $key = $bucket($fact);
                $groups[$key]['quantity'] += $fact['quantity'];
                $groups[$key]['gross'] += $fact['gross'];
                $groups[$key]['discount'] += $fact['discount'];
                $groups[$key]['returns'] += $fact['returned'];
                $groups[$key]['net_revenue'] += $fact['net'];

                if ($fact['invoice_id'] !== null) {
                    $groups[$key]['invoices'][$fact['invoice_id']] = true;
                }
            }

            foreach ($facts->deliveryCharges() as $charge) {
                $key = $bucket($charge + ['invoice_id' => null]);
                $groups[$key]['delivery_charges'] += $charge['amount'];
            }

            uasort($groups, fn (array $a, array $b) => $b['net_revenue'] <=> $a['net_revenue']);

            foreach ($groups as $row) {
                yield [
                    'brand' => $row['brand'],
                    'channel' => $row['channel'],
                    'customer_type' => $row['customer_type'],
                    'invoices' => count($row['invoices']),
                    'quantity' => round($row['quantity'], 4),
                    'gross' => round($row['gross'], 2),
                    'discount' => round($row['discount'], 2),
                    'returns' => round($row['returns'], 2),
                    'net_revenue' => round($row['net_revenue'], 2),
                    'delivery_charges' => round($row['delivery_charges'], 2),
                ];
            }
        });
    }

    /**
     * An upper bound, never a query: the rows are groups, at most one per
     * brand, channel and customer type. It only picks download or queue.
     */
    public function estimatedRows(ReportParameters $parameters): int
    {
        return (count(OrderBrand::names($this->businessId())) + 1) * count(SalesFacts::CHANNELS) * count(CustomerType::cases());
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
            note: 'Basis: invoiced. Goods sold is the invoice lines excluding VAT (what Product Sales 4110, under Sales Revenue 4100, was credited); '
                .'discounts are each line\'s share of the invoice discount excluding VAT (4210); returns are dated by the return, '
                .'at the invoiced price excluding VAT, less the discount given back (4200). Net revenue ties to 4110 + 4200 + 4210 for the period. '
                .'Not included: VAT, invoice totals, goods sent but not yet invoiced, and money received in advance. '
                .'Delivery charges billed are Delivery Charges Income (4300), shown apart and not in net revenue. '
                .'Brand and channel are the order\'s; a sale with no order behind it is Counter with no brand.',
        );
    }

    public function chart(ReportParameters $parameters): ?array
    {
        $label = match ($this->groupBy($parameters)) {
            'channel' => 'channel',
            'customer_type' => 'customer_type',
            default => 'brand',
        };

        return [
            'type' => 'bar',
            'title' => 'Net revenue',
            'label' => $label,
            'series' => [['key' => 'net_revenue', 'label' => 'Net revenue']],
            'limit' => 12,
        ];
    }

    private function facts(ReportParameters $parameters): SalesFacts
    {
        $channel = $parameters->string('channel');

        return new SalesFacts(
            businessId: $this->businessId(),
            from: $parameters->from('invoiced'),
            to: $parameters->to('invoiced'),
            brand: $this->brandFilter($parameters),
            channel: $channel !== null && array_key_exists($channel, SalesFacts::CHANNELS) ? $channel : null,
        );
    }

    private function groupBy(ReportParameters $parameters): string
    {
        $group = $parameters->string('group_by') ?? 'all';

        return array_key_exists($group, self::GROUPS) ? $group : 'all';
    }

    private static function customerTypeLabel(string $type): string
    {
        return CustomerType::tryFrom($type)?->label() ?? ($type === '' ? 'Unknown' : ucfirst($type));
    }
}
