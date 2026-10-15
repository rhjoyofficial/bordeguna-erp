<?php

namespace App\Modules\Sales\Support;

use App\Modules\Sales\Reporting\SalesFacts;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * What was invoiced in a period, by channel and by brand: the ONE figure the
 * Sales overview (15 Oct 2026) adds beside the dashboard widgets. It is the
 * Sales by channel report's own arithmetic (SalesFacts: ex-VAT, after
 * discounts, net of returns dated in the period, opening invoices excluded),
 * summed once per channel and once per brand, so the overview's "invoiced
 * today" is the number that report shows for today.
 *
 * ⚠ Not a second idea of revenue: every line comes from SalesFacts, which
 * ties to 4101 + 4200 + 4210 for the period. Nothing here reads a document
 * total (that carries VAT and the delivery charge).
 */
final class InvoicedByChannel
{
    public function __construct(private readonly int $businessId) {}

    /**
     * @param  int|string|null  $brand  an OrderBrand filter: null (all), OrderBrand::NONE or a brand id
     * @return array{
     *     net: float, gross: float, returned: float, invoices: int,
     *     channels: list<array{key: string, label: string, net: float, invoices: int}>,
     *     brands: list<array{id: int|null, label: string, net: float, invoices: int}>
     * }
     */
    public function between(CarbonInterface $from, CarbonInterface $to, int|string|null $brand = null): array
    {
        $facts = new SalesFacts($this->businessId, Carbon::instance($from), Carbon::instance($to), $brand);
        $names = OrderBrand::names($this->businessId);

        $net = 0.0;
        $gross = 0.0;
        $returned = 0.0;
        $invoices = [];
        $channels = [];
        $brands = [];

        foreach ($facts->invoiceLines()->concat($facts->returnLines()) as $fact) {
            $net += $fact['net'];
            $gross += $fact['gross'];
            $returned += $fact['returned'];

            $channel = (string) $fact['channel'];
            $channels[$channel] ??= ['key' => $channel, 'label' => SalesFacts::channelLabel($channel), 'net' => 0.0, 'invoices' => []];
            $channels[$channel]['net'] += $fact['net'];

            $brandId = $fact['brand_id'];
            $brandKey = $brandId === null ? 'none' : (string) $brandId;
            $brands[$brandKey] ??= ['id' => $brandId, 'label' => $brandId === null ? 'No brand' : (string) ($names[$brandId] ?? 'Brand '.$brandId), 'net' => 0.0, 'invoices' => []];
            $brands[$brandKey]['net'] += $fact['net'];

            if ($fact['invoice_id'] !== null) {
                $invoices[$fact['invoice_id']] = true;
                $channels[$channel]['invoices'][$fact['invoice_id']] = true;
                $brands[$brandKey]['invoices'][$fact['invoice_id']] = true;
            }
        }

        $shape = fn (array $row) => array_replace($row, ['net' => round($row['net'], 2), 'invoices' => count($row['invoices'])]);

        $channels = array_values(array_map($shape, $channels));
        $brands = array_values(array_map($shape, $brands));
        usort($channels, fn (array $a, array $b) => $b['net'] <=> $a['net']);
        usort($brands, fn (array $a, array $b) => $b['net'] <=> $a['net']);

        return [
            'net' => round($net, 2),
            'gross' => round($gross, 2),
            'returned' => round($returned, 2),
            'invoices' => count($invoices),
            'channels' => $channels,
            'brands' => $brands,
        ];
    }

    /** Today's invoicing, Dhaka's today. */
    public function today(int|string|null $brand = null): array
    {
        $today = Carbon::today();

        return $this->between($today, $today, $brand);
    }
}
