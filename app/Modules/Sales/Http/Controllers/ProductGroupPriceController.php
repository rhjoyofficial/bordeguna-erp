<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Actions\SetProductGroupPrices;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The "Prices" half of the product dialog (21 Sep 2026): one price per
 * customer group, read and saved as JSON beside the product itself.
 *
 * ⚠ In Sales, not Inventory: Inventory references nothing in Sales, and the
 * product screen asks this endpoint rather than growing that dependency. A
 * user without sales.prices.manage gets a 403 here and the dialog simply shows
 * no prices section.
 */
class ProductGroupPriceController extends Controller
{
    /** The groups, and for a product that exists, its plain price in force for each. */
    public function show(Request $request): JsonResponse
    {
        $product = $request->filled('product_id') ? Product::query()->findOrFail($request->integer('product_id')) : null;
        $today = Carbon::today();

        $current = $product === null ? collect() : ProductPrice::query()
            ->where('product_id', $product->id)
            ->whereNull('customer_id')
            ->whereNotNull('price_group_id')
            ->where('min_quantity', 0)
            ->where('is_discount', false)
            ->inForceOn($today)
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get()
            ->keyBy('price_group_id');

        // Quantity breaks, customer prices and discounts are kept on Sales >
        // Selling prices; the dialog says they exist rather than hiding them.
        $otherRules = $product === null ? 0 : ProductPrice::query()
            ->where('product_id', $product->id)
            ->inForceOn($today)
            ->where(fn ($query) => $query->whereNotNull('customer_id')->orWhere('min_quantity', '>', 0)->orWhere('is_discount', true))
            ->count();

        return response()->json([
            'mrp' => $product?->mrp === null ? null : (string) $product->mrp,
            'other_rules' => $otherRules,
            'groups' => PriceGroup::query()
                ->where('is_active', true)
                ->orderByRaw('CASE WHEN code = ? THEN 0 ELSE 1 END', [PriceGroup::DEFAULT_CODE])
                ->orderBy('name')
                ->get()
                ->map(function (PriceGroup $group) use ($current) {
                    $row = $current->get($group->id);

                    return [
                        'id' => $group->id,
                        'code' => $group->code,
                        'name' => $group->name,
                        // RETAIL always falls back; see ResolveSellingPrice.
                        'falls_back_to_mrp' => $group->falls_back_to_mrp || $group->code === PriceGroup::DEFAULT_CODE,
                        'method' => $row?->price_method ?? 'none',
                        'percent_off' => $row?->percent_off === null ? null : rtrim(rtrim((string) $row->percent_off, '0'), '.'),
                        'unit_price' => $row === null ? null : (string) $row->unit_price,
                    ];
                })
                ->values(),
        ]);
    }

    public function update(Request $request, Product $product, SetProductGroupPrices $prices): JsonResponse
    {
        $data = $request->validate([
            'prices' => ['present', 'array'],
            'prices.*.price_group_id' => ['required', 'integer'],
            'prices.*.method' => ['required', 'string'],
            'prices.*.value' => ['nullable'],
        ]);

        $entries = collect($data['prices'])->keyBy('price_group_id')->map(fn (array $entry) => [
            'method' => $entry['method'],
            'value' => $entry['value'] ?? null,
        ])->all();

        $result = $prices->handle($product, $entries, $request->user());

        return response()->json($result);
    }
}
