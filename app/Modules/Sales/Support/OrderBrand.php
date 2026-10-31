<?php

namespace App\Modules\Sales\Support;

use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Product;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The brand an order is sold under, and everything that hangs off an order
 * filtered by it (Joy, 11 Oct 2026: "each order carries its brand so sales,
 * campaigns, parcels and profit can be filtered per brand").
 *
 * ⭐ ONE PLACE FOR THE PATH. A challan, a parcel and an invoice have no brand
 * of their own: they reach it through their lines, back to the order lines and
 * the order. A copied column would be one more thing to keep in step, and the
 * first one somebody forgot would be believed. Every list and widget asks here.
 *
 * ⚠ A filter value is null (all brands), NONE ("No brand": orders that have
 * none) or a brand id of the CURRENT business. Anything else from a browser
 * reads as all brands: a brand id of another business must never narrow,
 * widen or reveal anything (business isolation).
 *
 * ⚠ Integer bindings only, compared to an integer column (MySQL strict mode: an
 * int-against-text comparison broke a live deploy once).
 */
final class OrderBrand
{
    public const NONE = 'none';

    /**
     * A request value as a filter: null, NONE or a brand id of this business.
     */
    public static function filter(mixed $value, int $businessId): int|string|null
    {
        if ($value === self::NONE) {
            return self::NONE;
        }

        if (! is_scalar($value) || ! ctype_digit((string) $value)) {
            return null;
        }

        $id = (int) $value;

        return Brand::query()->withoutGlobalScopes()->where('business_id', $businessId)->whereKey($id)->exists() ? $id : null;
    }

    /** The filter as the page should keep it in its form: '', 'none' or the id. */
    public static function filterValue(int|string|null $filter): string
    {
        return $filter === null ? '' : (string) $filter;
    }

    /**
     * Brands of this business for a filter or a picker, active first.
     *
     * @return Collection<int, array{id: int, code: string, name: string, is_active: bool}>
     */
    public static function options(int $businessId, bool $activeOnly = false): Collection
    {
        return Brand::query()->withoutGlobalScopes()
            ->where('business_id', $businessId)
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderByDesc('is_active')->orderBy('name')
            ->get(['id', 'code', 'name', 'is_active'])
            ->map(fn (Brand $b) => ['id' => (int) $b->id, 'code' => (string) $b->code, 'name' => (string) $b->name, 'is_active' => (bool) $b->is_active])
            ->values();
    }

    /** @return array<int, string> id => name, for labelling rows */
    public static function names(int $businessId): array
    {
        return Brand::query()->withoutGlobalScopes()->where('business_id', $businessId)->pluck('name', 'id')
            ->mapWithKeys(fn ($name, $id) => [(int) $id => (string) $name])->all();
    }

    /**
     * ⭐ Order desk (Joy, 31 Oct 2026): the lead brand of a multi-brand order,
     * the brand with the biggest share of the goods (quantity x list price,
     * else quantity), ties to the lower id. Null when no line has a brand.
     *
     * @param  list<array{product_id: int|string, quantity: int|float|string}>  $lines
     */
    public static function leadOf(array $lines): ?int
    {
        $products = Product::query()->whereIn('id', collect($lines)->pluck('product_id')->map(fn ($id) => (int) $id)->all())
            ->get(['id', 'brand_id', 'mrp'])->keyBy('id');
        $weight = [];

        foreach ($lines as $line) {
            $product = $products[(int) $line['product_id']] ?? null;

            if ($product?->brand_id === null) {
                continue;
            }

            $price = (float) ($product->mrp ?? 0) > 0 ? (float) $product->mrp : 1.0;
            $weight[(int) $product->brand_id] = ($weight[(int) $product->brand_id] ?? 0) + (float) $line['quantity'] * $price;
        }

        if ($weight === []) {
            return null;
        }

        ksort($weight);
        arsort($weight);

        return (int) array_key_first($weight);
    }

    /**
     * Orders of the chosen brand.
     *
     * @template T of BuilderContract
     *
     * @param  T  $query
     * @return T
     */
    public static function onOrders(BuilderContract $query, int|string|null $filter, string $column = 'sales_orders.brand_id'): BuilderContract
    {
        return match (true) {
            $filter === null => $query,
            $filter === self::NONE => $query->whereNull($column),
            default => $query->where($column, '=', (int) $filter),
        };
    }

    /**
     * Rows that point at a challan (by $deliveryIdColumn) whose lines came from
     * an order of the chosen brand. "No brand" = a line from an order with none.
     *
     * @template T of BuilderContract
     *
     * @param  T  $query
     * @return T
     */
    public static function onDeliveries(BuilderContract $query, int|string|null $filter, string $deliveryIdColumn = 'deliveries.id'): BuilderContract
    {
        if ($filter === null) {
            return $query;
        }

        return $query->whereExists(function (QueryBuilder $sub) use ($filter, $deliveryIdColumn) {
            $sub->selectRaw('1')
                ->from('delivery_lines as brand_dl')
                ->join('sales_order_lines as brand_sol', 'brand_sol.id', '=', 'brand_dl.sales_order_line_id')
                ->join('sales_orders as brand_so', 'brand_so.id', '=', 'brand_sol.sales_order_id')
                ->whereColumn('brand_dl.delivery_id', $deliveryIdColumn);

            self::onOrders($sub, $filter, 'brand_so.brand_id');
        });
    }

    /** Parcels, through their challan. */
    public static function onConsignments(BuilderContract $query, int|string|null $filter, string $deliveryIdColumn = 'consignments.delivery_id'): BuilderContract
    {
        return self::onDeliveries($query, $filter, $deliveryIdColumn);
    }

    /**
     * Invoices with a line billed from a challan line of an order of the chosen
     * brand. A direct invoice (no challan) has no order and so no brand.
     */
    public static function onInvoices(BuilderContract $query, int|string|null $filter, string $invoiceIdColumn = 'sales_invoices.id'): BuilderContract
    {
        if ($filter === null) {
            return $query;
        }

        return $query->whereExists(function (QueryBuilder $sub) use ($filter, $invoiceIdColumn) {
            $sub->selectRaw('1')
                ->from('sales_invoice_lines as brand_sil')
                ->join('delivery_lines as brand_dl', 'brand_dl.id', '=', 'brand_sil.delivery_line_id')
                ->join('sales_order_lines as brand_sol', 'brand_sol.id', '=', 'brand_dl.sales_order_line_id')
                ->join('sales_orders as brand_so', 'brand_so.id', '=', 'brand_sol.sales_order_id')
                ->whereColumn('brand_sil.sales_invoice_id', $invoiceIdColumn);

            self::onOrders($sub, $filter, 'brand_so.brand_id');
        });
    }

    /**
     * Invoice lines (in a query over sales_invoice_lines) billed from an order
     * of the chosen brand.
     */
    public static function onInvoiceLines(BuilderContract $query, int|string|null $filter, string $deliveryLineIdColumn = 'sales_invoice_lines.delivery_line_id'): BuilderContract
    {
        if ($filter === null) {
            return $query;
        }

        return $query->whereExists(function (QueryBuilder $sub) use ($filter, $deliveryLineIdColumn) {
            $sub->selectRaw('1')
                ->from('delivery_lines as brand_dl')
                ->join('sales_order_lines as brand_sol', 'brand_sol.id', '=', 'brand_dl.sales_order_line_id')
                ->join('sales_orders as brand_so', 'brand_so.id', '=', 'brand_sol.sales_order_id')
                ->whereColumn('brand_dl.id', $deliveryLineIdColumn);

            self::onOrders($sub, $filter, 'brand_so.brand_id');
        });
    }

    /**
     * The brand of each challan, by id: the brand of its order(s). A challan
     * carrying orders of two brands reads as the first; no brand reads as null.
     *
     * @param  list<int>|Collection<int, int>  $deliveryIds
     * @return array<int, int> delivery id => brand id
     */
    public static function forDeliveries(iterable $deliveryIds): array
    {
        $ids = collect($deliveryIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return DB::table('delivery_lines')
            ->join('sales_order_lines', 'sales_order_lines.id', '=', 'delivery_lines.sales_order_line_id')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_lines.sales_order_id')
            ->whereIn('delivery_lines.delivery_id', $ids->all())
            ->whereNotNull('sales_orders.brand_id')
            ->groupBy('delivery_lines.delivery_id')
            ->selectRaw('delivery_lines.delivery_id as delivery_id, MIN(sales_orders.brand_id) as brand_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->delivery_id => (int) $row->brand_id])
            ->all();
    }

    /**
     * The brand of each invoice, by id, through its challan lines.
     *
     * @param  list<int>|Collection<int, int>  $invoiceIds
     * @return array<int, int> invoice id => brand id
     */
    public static function forInvoices(iterable $invoiceIds): array
    {
        $ids = collect($invoiceIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return DB::table('sales_invoice_lines')
            ->join('delivery_lines', 'delivery_lines.id', '=', 'sales_invoice_lines.delivery_line_id')
            ->join('sales_order_lines', 'sales_order_lines.id', '=', 'delivery_lines.sales_order_line_id')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_lines.sales_order_id')
            ->whereIn('sales_invoice_lines.sales_invoice_id', $ids->all())
            ->whereNotNull('sales_orders.brand_id')
            ->groupBy('sales_invoice_lines.sales_invoice_id')
            ->selectRaw('sales_invoice_lines.sales_invoice_id as invoice_id, MIN(sales_orders.brand_id) as brand_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->invoice_id => (int) $row->brand_id])
            ->all();
    }
}
