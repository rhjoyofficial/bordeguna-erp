<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Accounting\Support\Money;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductFamily;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;
use App\Support\Traceability\BatchTrace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ⭐ Initial plan B22 (Joy, 24 Oct 2026; R10): what a family's sizes hold and
 * sell for, read for the family page, the product list's family row and the
 * price screen. Reads only.
 *
 * ⚠⚠ ON HAND IS NEVER ONE NUMBER ACROSS UNITS. Ten 1 kg packs and two 25 kg
 * bostas are not twelve of anything, so the total is said per stock unit
 * ("10 pcs, 2 bag") and only the VALUE is added up, in BDT.
 */
class ProductFamilyOverview
{
    /**
     * Stock on hand and its value per product, from the held (Active) batches,
     * the product list's own rule. Value per batch through Money, never float.
     *
     * @param  iterable<int>  $productIds
     * @return array<int, array{quantity: float, value: Money}>
     */
    public static function stock(iterable $productIds): array
    {
        $ids = collect($productIds)->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $out = [];

        StockBatch::query()
            ->whereIn('product_id', $ids)
            ->where('status', StockBatchStatus::Active)
            ->where('current_quantity', '>', 0)
            ->get(['product_id', 'current_quantity', 'current_value'])
            ->each(function (StockBatch $batch) use (&$out) {
                $id = (int) $batch->product_id;
                $out[$id] ??= ['quantity' => 0.0, 'value' => Money::zero()];
                $out[$id]['quantity'] += (float) $batch->current_quantity;
                $out[$id]['value'] = $out[$id]['value']->plus($batch->value());
            });

        return $out;
    }

    /**
     * The family row's totals over EVERY size (switched off ones included),
     * keyed by family id: sizes, on hand per unit, value.
     *
     * @param  iterable<int>  $familyIds
     * @return array<int, array{sizes: int, on_hand: string, value: string}>
     */
    public static function totals(iterable $familyIds): array
    {
        $ids = collect($familyIds)->filter()->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $sizes = Product::query()->whereIn('product_family_id', $ids)
            ->with('unitOfMeasure:id,abbreviation')
            ->get(['id', 'product_family_id', 'unit_of_measure_id', 'type']);
        $stock = self::stock($sizes->pluck('id'));

        return $sizes->groupBy('product_family_id')
            ->map(fn (Collection $members) => [
                'sizes' => $members->count(),
                'on_hand' => self::perUnit($members, $stock),
                'value' => Money::sum($members->map(fn (Product $p) => $stock[$p->id]['value'] ?? Money::zero()))->format(),
            ])
            ->all();
    }

    /** The price groups a size is priced in (not Owner, which is at cost). */
    public static function priceGroups(): Collection
    {
        return PriceGroup::query()->active()->where('price_basis', '<>', PriceGroup::BASIS_COST)
            ->orderBy('id')->get(['id', 'code', 'name']);
    }

    /**
     * Today's price of each size in each price group: the group's general
     * rule (no customer) for the smallest quantity, in force today. Null when
     * the group has no price for that size (RETAIL then falls back to MRP).
     *
     * @param  iterable<int>  $productIds
     * @return array<int, array<int, string|null>> {product_id: {price_group_id: "1,250.00"}}
     */
    public static function groupPrices(iterable $productIds): array
    {
        $out = [];

        ProductPrice::query()
            ->whereIn('product_id', collect($productIds)->all())
            ->whereNull('customer_id')
            ->inForceOn(Carbon::today())
            ->orderBy('min_quantity')
            ->orderByDesc('effective_from')
            ->get(['product_id', 'price_group_id', 'unit_price'])
            ->each(function (ProductPrice $price) use (&$out) {
                $out[(int) $price->product_id][(int) $price->price_group_id] ??= $price->unit_price === null
                    ? null
                    : Money::ofTaka((string) $price->unit_price)->format();
            });

        return $out;
    }

    /**
     * Every size of one family, smallest first by the order they were added,
     * with its stock, value, MRP and today's group prices.
     *
     * @return list<array<string, mixed>>
     */
    public static function sizes(ProductFamily $family): array
    {
        $sizes = $family->sizes()->with('unitOfMeasure:id,abbreviation')->orderBy('id')->get();
        $stock = self::stock($sizes->pluck('id'));
        $prices = self::groupPrices($sizes->pluck('id'));

        return $sizes->map(fn (Product $size) => [
            'id' => $size->id,
            'name' => $size->name,
            'sku' => $size->sku,
            'value' => $size->family_attribute_value,
            'label' => ProductFamily::sizeLabel($family->name, $size->family_attribute_value),
            'unit' => $size->unitOfMeasure?->abbreviation,
            'is_active' => (bool) $size->is_active,
            'stocked' => $size->type?->isStocked() ?? true,
            'on_hand' => BatchTrace::qty($stock[$size->id]['quantity'] ?? 0.0),
            'stock_value' => ($stock[$size->id]['value'] ?? Money::zero())->format(),
            'mrp' => $size->mrp === null ? null : Money::ofTaka((string) $size->mrp)->format(),
            'prices' => (object) ($prices[$size->id] ?? []),
        ])->values()->all();
    }

    /**
     * ⭐ Reports grouped by family (B22): which of these products are sizes,
     * of which family, and which size. A single product is not in the map.
     *
     * @param  iterable<int>  $productIds
     * @return array<int, array{id: int, name: string, sku: string, value: ?string}>
     */
    public static function membership(iterable $productIds): array
    {
        $ids = collect($productIds)->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return Product::query()
            ->whereIn('products.id', $ids)
            ->whereNotNull('products.product_family_id')
            ->join('product_families', 'product_families.id', '=', 'products.product_family_id')
            ->get(['products.id', 'products.family_attribute_value', 'product_families.id as family_id', 'product_families.name as family_name', 'product_families.sku as family_sku'])
            ->mapWithKeys(fn ($row) => [(int) $row->id => [
                'id' => (int) $row->family_id,
                'name' => (string) $row->family_name,
                'sku' => (string) $row->family_sku,
                'value' => $row->family_attribute_value,
            ]])
            ->all();
    }

    /**
     * "1 kg: 10, 25 kg: 2": a family's quantity told size by size, never
     * added across sizes (ten packs and two bostas are not twelve of anything).
     *
     * @param  array<string, float>  $bySize
     */
    public static function sizesText(array $bySize): string
    {
        return collect($bySize)->map(fn (float $qty, string $size) => "{$size}: ".BatchTrace::qty($qty))->implode(', ');
    }

    /**
     * "10 pcs, 2 bag": on hand added up within each stock unit only.
     *
     * @param  array<int, array{quantity: float, value: Money}>  $stock
     */
    private static function perUnit(Collection $members, array $stock): string
    {
        $byUnit = [];

        foreach ($members as $member) {
            if (! ($member->type?->isStocked() ?? true)) {
                continue;
            }

            $unit = (string) ($member->unitOfMeasure?->abbreviation ?? '');
            $byUnit[$unit] = ($byUnit[$unit] ?? 0.0) + ($stock[$member->id]['quantity'] ?? 0.0);
        }

        if ($byUnit === []) {
            return '-';
        }

        return collect($byUnit)->map(fn (float $qty, string $unit) => trim(BatchTrace::qty($qty).' '.$unit))->implode(', ');
    }
}
