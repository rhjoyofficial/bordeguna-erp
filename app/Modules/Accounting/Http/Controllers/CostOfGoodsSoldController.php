<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\CostOfGoodsSold;
use App\Modules\Accounting\Support\CostOfGoodsSoldRow;
use App\Modules\Accounting\Support\ReportDates;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

        return Inertia::render('accounting/cost-of-goods-sold/index', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'rows' => $cogs->rows->map(fn (CostOfGoodsSoldRow $row) => [
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
