<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\InventoryValuation;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\ReportDates;
use App\Modules\Accounting\Support\ValuationRow;
use App\Modules\Inventory\Support\ProductFamilyOverview;
use App\Support\Traceability\BatchTrace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the stock was worth on a stated day.
 *
 * ⚠ AS AT a date, never FOR a period - the same rule as the ageing. "What is it
 * worth" is a question about one moment.
 */
class InventoryValuationController extends Controller
{
    public function index(Request $request): Response
    {
        $asAt = $request->filled('as_at')
            ? ReportDates::parse($request, 'as_at')->startOfDay()
            : Carbon::today();

        $valuation = InventoryValuation::asAt($asAt);
        // ⭐ Initial plan B22 (24 Oct 2026; R10): an optional "By family"
        // summary beside By brand and By warehouse. Off by default.
        $byFamily = $request->string('group')->toString() === 'family';

        return Inertia::render('accounting/inventory-valuation/index', [
            'as_at' => $valuation->asAt->toDateString(),
            'group' => $byFamily ? 'family' : '',
            'by_family' => $byFamily ? $this->byFamily($valuation->rows) : [],
            'rows' => $valuation->rows->map(fn (ValuationRow $row) => [
                'product_sku' => $row->productSku,
                'product_name' => $row->productName,
                'brand' => $row->brand,
                'warehouse' => $row->warehouseName,
                'batch_number' => $row->batchNumber,
                'expiry_date' => $row->expiryDate?->toDateString(),
                'quantity' => $row->quantity,
                // ⚠ Null, not "0.00", when there is nothing to divide by. A unit
                // cost of zero on an empty layer reads as a known cost of nothing.
                'unit_cost' => $row->unitCost(),
                // ⚠ Every figure formatted explicitly. `19200.00000000` has
                // reached a real user on this project twice.
                'value' => $row->value->format(),
                'anomaly' => $row->anomaly(),
            ]),
            'by_brand' => $valuation->byBrand()->map(fn (array $entry) => [
                'brand' => $entry['brand'],
                'quantity' => $entry['quantity'],
                'value' => $entry['value']->format(),
            ]),
            'by_warehouse' => $valuation->byWarehouse()->map(fn (array $entry) => [
                'warehouse' => $entry['warehouse'],
                'quantity' => $entry['quantity'],
                'value' => $entry['value']->format(),
            ]),
            'total' => $valuation->total()->format(),
            // ⚠⚠ C-IVL-4. The tie-out is on the screen, every time. A tie-out
            // that lives only in a nightly command is not performed.
            'control' => [
                'account' => $valuation->controlAccountLabel,
                'balance' => $valuation->controlBalance?->format(),
                'has_account' => $valuation->hasControlAccount(),
                'ties_out' => $valuation->tiesOut(),
                'difference' => $valuation->difference()->absolute()->format(),
            ],
            'anomaly_count' => $valuation->anomalies()->count(),
        ]);
    }

    /**
     * ⭐ B22: stock value per family (its sizes added up; quantity told size by
     * size) and per single product, largest value first.
     *
     * @param  Collection<int, ValuationRow>  $rows
     * @return list<array{name: string, quantity: string, quantity_text: ?string, value: string}>
     */
    private function byFamily(Collection $rows): array
    {
        $families = ProductFamilyOverview::membership($rows->map(fn (ValuationRow $row) => $row->productId));
        $out = [];

        foreach ($rows as $row) {
            $family = $families[$row->productId] ?? null;
            $key = $family === null ? "p{$row->productId}" : "f{$family['id']}";

            $out[$key] ??= ['name' => $family['name'] ?? $row->productName, 'is_family' => $family !== null, 'quantity' => 0.0, 'sizes' => [], 'value' => Money::zero()];
            $out[$key]['quantity'] += (float) $row->quantity;
            $out[$key]['value'] = $out[$key]['value']->plus($row->value);

            if ($family !== null) {
                $size = (string) ($family['value'] ?? $row->productName);
                $out[$key]['sizes'][$size] = ($out[$key]['sizes'][$size] ?? 0.0) + (float) $row->quantity;
            }
        }

        return collect($out)
            ->sortByDesc(fn (array $entry) => $entry['value']->minor)
            ->map(fn (array $entry) => [
                'name' => $entry['name'],
                'quantity' => BatchTrace::qty($entry['quantity']),
                'quantity_text' => $entry['is_family'] ? ProductFamilyOverview::sizesText($entry['sizes']) : null,
                'value' => $entry['value']->format(),
            ])
            ->values()
            ->all();
    }
}
