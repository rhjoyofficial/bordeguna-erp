<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductUnitConversion;
use App\Modules\Inventory\Models\UnitOfMeasure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Buying in one unit and stocking in another, as a screen.
 *
 * ⚠⚠ WHY THIS EXISTS. `ProductUnitConversion`, `ResolveReceiptConversion` and
 * the batch-level factor freeze have been built, tested and deployed since
 * 9 August, and `ProductUnitConversion::record()` had no caller anywhere outside
 * its own tests. `products.purchase_unit_id` is deliberately not fillable, so
 * that method is the ONLY way in - and nothing led to it. The fourth engine on
 * this project to be finished and unreachable.
 *
 * The consequence was concrete: mustard oil is bought by the kilogram and sold
 * by the litre, and nobody could tell the system so. Every goods receipt for it
 * would have thrown, correctly and permanently (C-UOM-9).
 *
 * ⚠ Both directions are typed by hand and the model checks they multiply to 1.
 * The screen deliberately does NOT compute the second from the first: an
 * inverted factor is 21% wrong for ever with every ledger still balancing, and
 * two numbers that must agree is the only cheap way to catch it. C-UOM-3.
 */
class UnitConversionController extends Controller
{
    public function index(): Response
    {
        $conversions = ProductUnitConversion::with(['product', 'fromUnit', 'toUnit'])
            ->orderBy('product_id')
            ->orderByDesc('effective_from')
            ->get()
            ->map(fn (ProductUnitConversion $c) => [
                'id' => $c->id,
                'sku' => $c->product->sku,
                'product' => $c->product->name,
                'from_unit' => $c->fromUnit->abbreviation,
                'to_unit' => $c->toUnit->abbreviation,
                'stock_units_per_purchase_unit' => $c->stock_units_per_purchase_unit,
                'effective_from' => $c->effective_from->toDateString(),
                'source' => $c->source,
                'note' => $c->note,
            ]);

        return Inertia::render('inventory/unit-conversions/index', [
            'conversions' => $conversions,
            // Only stocked products can be bought at all. A service has no unit
            // to convert into.
            'products' => Product::where('is_active', true)
                ->whereIn('type', ['raw_material', 'finished_good'])
                // purchaseUnit too: it was loaded once per product (bug hunt 16 Oct 2026).
                ->with(['unitOfMeasure', 'purchaseUnit'])
                ->orderBy('sku')
                ->get()
                ->map(fn (Product $p) => [
                    'id' => $p->id,
                    'sku' => $p->sku,
                    'name' => $p->name,
                    'stock_unit' => $p->unitOfMeasure?->abbreviation,
                    'stock_unit_id' => $p->unit_of_measure_id,
                    // ⚠ Once set, the purchase unit cannot be changed: existing
                    // batches froze a factor describing the old one. The screen
                    // shows it so nobody tries.
                    'purchase_unit' => $p->purchaseUnit?->abbreviation,
                ]),
            'units' => UnitOfMeasure::orderBy('name')->get(['id', 'name', 'abbreviation']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'product_id' => ['required', Rule::exists('products', 'id')->where('business_id', $businessId)],
            'from_unit_id' => ['required', Rule::exists('units_of_measure', 'id')->where('business_id', $businessId)],
            // ⚠⚠ Both directions, both required, both typed. This is the whole
            // guard: the model refuses a pair that does not multiply to 1.
            'stock_units_per_purchase_unit' => ['required', 'numeric', 'gt:0'],
            'purchase_units_per_stock_unit' => ['required', 'numeric', 'gt:0'],
            'effective_from' => ['required', 'date'],
            // Where the number came from. A factor with no source is a factor
            // nobody can defend when a margin looks wrong a year from now.
            'source' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'stock_units_per_purchase_unit' => 'stock units per purchase unit',
            'purchase_units_per_stock_unit' => 'purchase units per stock unit',
        ]);

        $product = Product::findOrFail($data['product_id']);
        $fromUnit = UnitOfMeasure::findOrFail($data['from_unit_id']);
        $toUnit = UnitOfMeasure::findOrFail($product->unit_of_measure_id);

        try {
            ProductUnitConversion::record(
                businessId: $businessId,
                product: $product,
                fromUnit: $fromUnit,
                toUnit: $toUnit,
                stockUnitsPerPurchaseUnit: (string) $data['stock_units_per_purchase_unit'],
                purchaseUnitsPerStockUnit: (string) $data['purchase_units_per_stock_unit'],
                effectiveFrom: $data['effective_from'],
                source: $data['source'],
                note: $data['note'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            // ⚠ The model's refusals are written for a person to read - "one of
            // them is the wrong way round" - so they are surfaced verbatim
            // rather than replaced with a generic message.
            throw ValidationException::withMessages([
                'stock_units_per_purchase_unit' => $e->getMessage(),
            ]);
        }

        return back()->with('success', 'Conversion recorded for '.$product->sku.'.');
    }
}
