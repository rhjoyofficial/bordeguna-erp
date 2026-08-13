<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\InventoryValuation;
use App\Modules\Accounting\Support\ValuationRow;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
            ? Carbon::parse($request->string('as_at')->toString())->startOfDay()
            : Carbon::today();

        $valuation = InventoryValuation::asAt($asAt);

        return Inertia::render('accounting/inventory-valuation/index', [
            'as_at' => $valuation->asAt->toDateString(),
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
}
