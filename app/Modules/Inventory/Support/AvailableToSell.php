<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ "Available to sell" (Joy, 24 Oct 2026, Q4 = A, R81, B17): what is on the
 * shelf and can leave (active, not on hold, not expired: the FEFO scope),
 * less everything somebody holds: a confirmed sales order (product +
 * warehouse rows), an approved staff purchase or owner withdrawal (batch
 * rows). "500 in the warehouse, an order for 400: the others see 100."
 *
 * ⚠ ONE place for the arithmetic, read by the stock page, the product page,
 * the order form, the counter, the quotation form, the low-stock signal and
 * the challan guard, so no two screens can disagree about the same shelf.
 *
 * ⚠ Integer-scaled (x 10000) inside: quantities are decimal(15,4), so four
 * places are the whole domain and every sum here is exact.
 */
final class AvailableToSell
{
    private static ?bool $hasFlag = null;

    /**
     * Warehouses whose stock is never offered for sale.
     *
     * ⚠ SEAM (B7, another branch): `warehouses.not_for_sale` (Damaged /
     * Quarantine). This branch does not have the column yet, so the list is
     * empty here and fills itself the day the column exists. Only the column
     * check is remembered; the list is read fresh (business-scoped).
     *
     * @return array<int, int>
     */
    public static function notForSaleWarehouseIds(): array
    {
        self::$hasFlag ??= Schema::hasColumn('warehouses', 'not_for_sale');

        if (! self::$hasFlag) {
            return [];
        }

        return Warehouse::query()->where('not_for_sale', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Sellable on hand per warehouse and product (scaled).
     *
     * @param  array<int, int>|null  $productIds  null = every product
     * @return array<int, array<int, int>> warehouse id => product id => scaled
     */
    public static function onHandScaled(?array $productIds, ?int $warehouseId = null): array
    {
        $map = [];

        StockBatch::query()->consumable()
            ->when($productIds !== null, fn (Builder $q) => $q->whereIn('product_id', $productIds))
            ->when($warehouseId !== null, fn (Builder $q) => $q->where('warehouse_id', $warehouseId))
            ->reorder()
            ->groupBy('warehouse_id', 'product_id')
            ->selectRaw('warehouse_id, product_id, SUM(current_quantity) as quantity')
            ->get()
            ->each(function ($row) use (&$map) {
                $map[(int) $row->warehouse_id][(int) $row->product_id] = self::scale((string) $row->quantity);
            });

        return $map;
    }

    /**
     * Live holds per warehouse and product (scaled): order rows (no batch) and
     * batch rows on batches that can still leave (a hold on an expired batch
     * holds nothing anybody could sell).
     *
     * @param  array<int, int>|null  $productIds
     * @param  Model|null  $except  leave out this document's own holds
     * @return array<int, array<int, int>>
     */
    public static function reservedScaled(?array $productIds, ?int $warehouseId = null, ?Model $except = null): array
    {
        $map = [];
        $exceptType = $except?->getMorphClass();
        $exceptId = $except === null ? null : (int) $except->getKey();

        $own = fn (Builder $q) => $q->where(fn (Builder $w) => $w
            ->where('stock_reservations.reference_type', '<>', $exceptType)
            ->orWhere('stock_reservations.reference_id', '<>', $exceptId));

        StockReservation::query()->live()
            ->whereNull('stock_batch_id')
            ->when($productIds !== null, fn (Builder $q) => $q->whereIn('product_id', $productIds))
            ->when($warehouseId !== null, fn (Builder $q) => $q->where('warehouse_id', $warehouseId))
            ->when($except !== null, $own)
            ->groupBy('warehouse_id', 'product_id')
            ->selectRaw('warehouse_id, product_id, SUM(quantity) as quantity')
            ->get()
            ->each(function ($row) use (&$map) {
                $map[(int) $row->warehouse_id][(int) $row->product_id] = ($map[(int) $row->warehouse_id][(int) $row->product_id] ?? 0) + self::scale((string) $row->quantity);
            });

        StockReservation::query()->live()
            ->whereIn('stock_batch_id', StockBatch::query()->consumable()->reorder()
                ->when($productIds !== null, fn (Builder $q) => $q->whereIn('product_id', $productIds))
                ->when($warehouseId !== null, fn (Builder $q) => $q->where('warehouse_id', $warehouseId))
                ->select('stock_batches.id'))
            ->when($except !== null, $own)
            ->join('stock_batches', 'stock_batches.id', '=', 'stock_reservations.stock_batch_id')
            ->groupBy('stock_batches.warehouse_id', 'stock_batches.product_id')
            ->selectRaw('stock_batches.warehouse_id as warehouse_id, stock_batches.product_id as product_id, SUM(stock_reservations.quantity) as quantity')
            ->get()
            ->each(function ($row) use (&$map) {
                $map[(int) $row->warehouse_id][(int) $row->product_id] = ($map[(int) $row->warehouse_id][(int) $row->product_id] ?? 0) + self::scale((string) $row->quantity);
            });

        return $map;
    }

    /**
     * On hand, reserved and available per warehouse and product.
     *
     * @param  array<int, int>|null  $productIds
     * @return array<int, array<int, array{on_hand: string, reserved: string, available: string}>>
     */
    public static function grid(?array $productIds, ?int $warehouseId = null): array
    {
        $onHand = self::onHandScaled($productIds, $warehouseId);
        $reserved = self::reservedScaled($productIds, $warehouseId);
        $grid = [];

        foreach ([$onHand, $reserved] as $source) {
            foreach ($source as $wh => $products) {
                foreach (array_keys($products) as $productId) {
                    $have = $onHand[$wh][$productId] ?? 0;
                    $held = $reserved[$wh][$productId] ?? 0;
                    $grid[$wh][$productId] = [
                        'on_hand' => self::unscale($have),
                        'reserved' => self::unscale($held),
                        'available' => self::unscale(max(0, $have - $held)),
                    ];
                }
            }
        }

        return $grid;
    }

    /**
     * Per product across every warehouse whose stock is for sale.
     *
     * ⚠ Available is worked out PER WAREHOUSE and then added: an order held
     * in Savar does not make Dhaka's shelf any smaller, and a warehouse held
     * beyond what it has adds nothing below zero.
     *
     * @param  array<int, int>|null  $productIds
     * @return array<int, array{on_hand: string, reserved: string, available: string}>
     */
    public static function totals(?array $productIds): array
    {
        $onHand = self::onHandScaled($productIds);
        $reserved = self::reservedScaled($productIds);
        $skip = array_flip(self::notForSaleWarehouseIds());
        $sums = [];

        foreach ([$onHand, $reserved] as $source) {
            foreach ($source as $wh => $products) {
                if (isset($skip[$wh])) {
                    continue;
                }

                foreach (array_keys($products) as $productId) {
                    $sums[$productId][$wh] = [$onHand[$wh][$productId] ?? 0, $reserved[$wh][$productId] ?? 0];
                }
            }
        }

        $totals = [];

        foreach ($sums as $productId => $warehouses) {
            $have = 0;
            $held = 0;
            $free = 0;

            foreach ($warehouses as [$h, $r]) {
                $have += $h;
                $held += $r;
                $free += max(0, $h - $r);
            }

            $totals[$productId] = ['on_hand' => self::unscale($have), 'reserved' => self::unscale($held), 'available' => self::unscale($free)];
        }

        return $totals;
    }

    /** One product's total, zeros when it has nothing. */
    public static function forProduct(int $productId): array
    {
        return self::totals([$productId])[$productId] ?? ['on_hand' => '0', 'reserved' => '0', 'available' => '0'];
    }

    /**
     * What a document may still take of one product in one warehouse: on
     * hand less every hold except its own (scaled, never below zero).
     */
    public static function freeScaled(int $productId, int $warehouseId, ?Model $for = null): int
    {
        $have = self::onHandScaled([$productId], $warehouseId)[$warehouseId][$productId] ?? 0;
        $held = self::reservedScaled([$productId], $warehouseId, $for)[$warehouseId][$productId] ?? 0;

        return max(0, $have - $held);
    }

    public static function scale(string $quantity): int
    {
        return (int) round(((float) $quantity) * 10000);
    }

    /** A scaled quantity as the screens show it: 10.5, never 10.5000. */
    public static function unscale(int $scaled): string
    {
        $value = number_format($scaled / 10000, 4, '.', '');
        $trimmed = rtrim(rtrim($value, '0'), '.');

        return ($trimmed === '' || $trimmed === '-') ? '0' : $trimmed;
    }
}
