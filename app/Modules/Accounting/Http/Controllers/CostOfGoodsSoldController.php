<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\CostOfGoodsSold;
use App\Modules\Accounting\Support\CostOfGoodsSoldRow;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\ReportDates;
use App\Modules\Inventory\Support\ProductFamilyOverview;
use App\Support\Traceability\BatchTrace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the goods sold in a period cost, and what they earned.
 *
 * ⚠ FOR A PERIOD, never as at a date - the mirror of the ageing rule. A cost of
 * goods sold "as at" one day is a category error.
 */
class CostOfGoodsSoldController extends Controller
{
    public function index(Request $request): Response
    {
        [$from, $to] = $this->period($request);

        $cogs = CostOfGoodsSold::forPeriod($from, $to);
        // ⭐ Initial plan B22 (24 Oct 2026; R10): an optional toggle that shows
        // a family's sizes as one row. Off by default: the report as it was.
        $byFamily = $request->string('group')->toString() === 'family';

        return Inertia::render('accounting/cost-of-goods-sold/index', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'group' => $byFamily ? 'family' : '',
            'rows' => $byFamily ? $this->byFamily($cogs->rows) : $cogs->rows->map(fn (CostOfGoodsSoldRow $row) => [
                'product_sku' => $row->productSku,
                'product_name' => $row->productName,
                'brand' => $row->brand,
                'quantity' => $row->quantity,
                'cost' => $row->cost->format(),
                'revenue' => $row->revenue->format(),
                'gross_profit' => $row->grossProfit()->format(),
                // ⚠ Null, not "0.0", when nothing was sold. Until prices are
                // loaded that is every product, and a displayed zero margin
                // would become the normal state people learn to ignore.
                'margin_percent' => $row->marginPercent(),
            ]),
            'by_brand' => $cogs->byBrand()->map(fn (array $entry) => [
                'brand' => $entry['brand'],
                'cost' => $entry['cost']->format(),
                'revenue' => $entry['revenue']->format(),
                'gross_profit' => $entry['gross_profit']->format(),
            ]),
            'totals' => [
                'cost' => $cogs->totalCost()->format(),
                'revenue' => $cogs->totalRevenue()->format(),
                'gross_profit' => $cogs->grossProfit()->format(),
            ],
            'control' => [
                'account' => $cogs->controlAccountLabel,
                'balance' => $cogs->controlBalance?->format(),
                'has_account' => $cogs->hasControlAccount(),
                'ties_out' => $cogs->tiesOut(),
                'difference' => $cogs->difference()->absolute()->format(),
            ],
        ]);
    }

    /**
     * ⭐ B22: the rows with each family's sizes added up into one row (cost,
     * revenue, profit and margin from the sums; quantity told size by size,
     * never added across sizes). Single products stay as they are.
     *
     * @param  Collection<int, CostOfGoodsSoldRow>  $rows
     * @return list<array<string, mixed>>
     */
    private function byFamily(Collection $rows): array
    {
        $families = ProductFamilyOverview::membership($rows->map(fn (CostOfGoodsSoldRow $row) => $row->productId));
        $out = [];

        foreach ($rows as $row) {
            $family = $families[$row->productId] ?? null;
            $key = $family === null ? "p{$row->productId}" : "f{$family['id']}";

            $out[$key] ??= [
                'family_id' => $family['id'] ?? null,
                'product_sku' => $family['sku'] ?? $row->productSku,
                'product_name' => $family['name'] ?? $row->productName,
                'brand' => $row->brand,
                'quantity' => '0',
                'sizes' => [],
                'cost' => Money::zero(),
                'revenue' => Money::zero(),
            ];

            $out[$key]['cost'] = $out[$key]['cost']->plus($row->cost);
            $out[$key]['revenue'] = $out[$key]['revenue']->plus($row->revenue);
            $out[$key]['quantity'] = BatchTrace::qty((float) $out[$key]['quantity'] + (float) $row->quantity);
            $out[$key]['brand'] = $out[$key]['brand'] === $row->brand ? $row->brand : null;

            if ($family !== null) {
                $size = (string) ($family['value'] ?? $row->productName);
                $out[$key]['sizes'][$size] = ($out[$key]['sizes'][$size] ?? 0.0) + (float) $row->quantity;
            }
        }

        return collect($out)
            ->map(function (array $entry) {
                $profit = Money::ofMinor($entry['revenue']->minor - $entry['cost']->minor);

                return [
                    'family_id' => $entry['family_id'],
                    'product_sku' => $entry['product_sku'],
                    'product_name' => $entry['product_name'],
                    'brand' => $entry['brand'],
                    'quantity' => $entry['quantity'],
                    'quantity_text' => $entry['family_id'] === null ? null : ProductFamilyOverview::sizesText($entry['sizes']),
                    'cost' => $entry['cost']->format(),
                    'revenue' => $entry['revenue']->format(),
                    'gross_profit' => $profit->format(),
                    'margin_percent' => $entry['revenue']->minor === 0 ? null : number_format($profit->minor / $entry['revenue']->minor * 100, 1, '.', ''),
                ];
            })
            ->sortBy(fn (array $row) => mb_strtolower($row['product_name']))
            ->values()
            ->all();
    }

    /**
     * ⚠ Defaults to the CURRENT MONTH, not "everything". A report that opens on
     * all of history is one nobody can read and one that says nothing about how
     * the business is doing now.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(Request $request): array
    {
        $from = $request->filled('from')
            ? ReportDates::parse($request, 'from')->startOfDay()
            : Carbon::today()->startOfMonth();

        $to = $request->filled('to')
            ? ReportDates::parse($request, 'to')->startOfDay()
            : Carbon::today();

        // A reversed range would silently return nothing, which reads as "no
        // sales" rather than "you typed the dates the wrong way round".
        return $to->lt($from) ? [$to, $from] : [$from, $to];
    }
}
