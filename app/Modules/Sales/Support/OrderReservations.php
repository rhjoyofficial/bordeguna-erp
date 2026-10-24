<?php

namespace App\Modules\Sales\Support;

use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Models\StockReservationOverride;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\AvailableToSell;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Exceptions\StockShortfall;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ A CONFIRMED ORDER HOLDS ITS STOCK (Joy, 24 Oct 2026, Q4 = A, R81, B17).
 *
 *   confirmed / approved   holds what is still to deliver, per product, in the
 *                          order's warehouse (product + warehouse rows in
 *                          stock_reservations, reason `sales_order`, no batch:
 *                          the challan still picks batches FEFO)
 *   challan                the hold drops by what was delivered
 *   cancel, close short    the hold ends
 *   edit                   the hold is taken again for the new quantities
 *   challan reversed       the hold is taken again for what is owed once more
 *   by hand                Owner or Manager, with a reason (Reserved stock list)
 *
 * ⚠ Confirming an order that wants more than is available is REFUSED with a
 * sentence (available against wanted). A holder of `sales.stock.override`
 * (Owner, Manager) may confirm it with a reason: what is there is held, the
 * shortfall is a backorder, unreserved, logged in stock_reservation_overrides
 * and listed on the Reserved stock page.
 *
 * ⚠ Rows are never deleted: a changed hold releases the old rows
 * (`released_at`) and writes new ones, so "what did SO-12 hold on the 20th"
 * stays answerable. Pending, rejected and website orders waiting for payment
 * hold nothing (Q2 = B: an order reserves only when somebody confirms it).
 */
final class OrderReservations
{
    public const PERMISSION = 'sales.stock.override';

    /** Where an order's stock is held: its own warehouse, else the default, else the first in use. */
    public static function warehouseIdOf(SalesOrder $order): ?int
    {
        if ($order->warehouse_id !== null) {
            return (int) $order->warehouse_id;
        }

        $skip = AvailableToSell::notForSaleWarehouseIds();
        $query = fn () => Warehouse::query()->where('is_active', true)->when($skip !== [], fn ($q) => $q->whereNotIn('id', $skip));

        $id = $query()->where('is_default', true)->value('id') ?? $query()->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * What is still to deliver, per stocked product (scaled x 10000).
     *
     * @return array<int, int>
     */
    public static function wanted(SalesOrder $order): array
    {
        $wanted = [];

        foreach (SalesOrderLine::query()->where('sales_order_id', $order->id)->with('product:id,type')->get() as $line) {
            if ($line->product === null || ! $line->product->type->isStocked()) {
                continue;
            }

            $left = AvailableToSell::scale((string) $line->quantity_ordered) - AvailableToSell::scale((string) $line->quantity_delivered);

            if ($left > 0) {
                $wanted[(int) $line->product_id] = ($wanted[(int) $line->product_id] ?? 0) + $left;
            }
        }

        return $wanted;
    }

    /**
     * What the order holds now, per product (scaled).
     *
     * @return array<int, int>
     */
    public static function held(SalesOrder $order): array
    {
        return StockReservation::query()->live()
            ->for($order->getMorphClass(), (int) $order->id)
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity) as quantity')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->product_id => AvailableToSell::scale((string) $row->quantity)])
            ->all();
    }

    /**
     * Products the order wants more of than is available to it (everything
     * on the shelf less what OTHER documents hold).
     *
     * @return array<int, array{product_id: int, product: string, warehouse: string, wanted: string, available: string, short: string}>
     */
    public static function shortfalls(SalesOrder $order): array
    {
        $warehouseId = self::warehouseIdOf($order);
        $wanted = self::wanted($order);

        if ($wanted === []) {
            return [];
        }

        $warehouseName = $warehouseId === null ? '' : (string) Warehouse::query()->whereKey($warehouseId)->value('name');
        $names = Product::query()->whereIn('id', array_keys($wanted))->pluck('name', 'id');
        $short = [];

        foreach ($wanted as $productId => $want) {
            $free = $warehouseId === null ? 0 : AvailableToSell::freeScaled($productId, $warehouseId, $order);

            if ($want > $free) {
                $short[] = [
                    'product_id' => $productId,
                    'product' => (string) ($names[$productId] ?? '#'.$productId),
                    'warehouse' => $warehouseName,
                    'wanted' => AvailableToSell::unscale($want),
                    'available' => AvailableToSell::unscale($free),
                    'short' => AvailableToSell::unscale($want - $free),
                ];
            }
        }

        return $short;
    }

    /** "Only 100 of Honey 500g is available to sell in Savar; this order wants 400." */
    public static function sentence(SalesOrder $order, array $shortfalls): string
    {
        $parts = array_map(
            fn (array $row) => "only {$row['available']} of {$row['product']} is available to sell".($row['warehouse'] !== '' ? " in {$row['warehouse']}" : '')."; this order wants {$row['wanted']}",
            $shortfalls,
        );

        return "Order {$order->order_number} cannot be confirmed: ".implode('. And ', $parts).'. A Manager or the Owner can confirm it with a reason (the rest waits as a backorder).';
    }

    /**
     * Hold what the order still wants. Called when its approval is complete.
     *
     * ⚠ Inside the caller's transaction, under a lock on the batches of every
     * product, so two people confirming orders for the last 100 cannot both
     * get them. Short and no permitted reason: StockShortfall, and the
     * caller's transaction (the approval) rolls back with it.
     *
     * @param  bool  $allowShort  true = take what there is, no question (an
     *                            edit of an order that was already confirmed,
     *                            a reversed challan)
     *
     * @throws StockShortfall
     */
    public function reserve(SalesOrder $order, ?User $by, ?string $backorderReason = null, bool $allowShort = false): void
    {
        DB::transaction(function () use ($order, $by, $backorderReason, $allowShort) {
            $warehouseId = self::warehouseIdOf($order);
            $wanted = self::wanted($order);

            if ($warehouseId !== null && $wanted !== []) {
                StockBatch::query()->whereIn('product_id', array_keys($wanted))->where('warehouse_id', $warehouseId)->lockForUpdate()->get(['id']);
            }

            $shortfalls = self::shortfalls($order);
            $reason = trim((string) $backorderReason);

            if ($shortfalls !== [] && ! $allowShort) {
                if (mb_strlen($reason) < 5 || $by === null || ! $by->can(self::PERMISSION)) {
                    throw new StockShortfall($shortfalls, self::sentence($order, $shortfalls));
                }

                foreach ($shortfalls as $row) {
                    StockReservationOverride::create([
                        'business_id' => $order->business_id,
                        'kind' => StockReservationOverride::BACKORDER,
                        'product_id' => $row['product_id'],
                        'warehouse_id' => $warehouseId,
                        'quantity' => $row['short'],
                        'available' => $row['available'],
                        'reference_type' => $order->getMorphClass(),
                        'reference_id' => $order->id,
                        'reason' => mb_substr($reason, 0, 1000),
                        'authorised_by' => $by->id,
                    ]);
                }

                activity()->performedOn($order)->causedBy($by)
                    ->withProperties(['shortfalls' => $shortfalls, 'reason' => $reason])
                    ->log('confirmed with a stock shortfall (backorder)');
            }

            $this->write($order, $warehouseId, $wanted, $by, 'Confirmed');
        });
    }

    /**
     * Take the hold again for what the order now wants (an edit, a reversed
     * challan): never asks, holds what there is.
     */
    public function resync(SalesOrder $order, ?User $by, string $why = 'Edited'): void
    {
        $order->refresh();

        if (! $order->isDeliverable()) {
            $this->release($order, $why);

            return;
        }

        DB::transaction(fn () => $this->write($order, self::warehouseIdOf($order), self::wanted($order), $by, $why));
    }

    /** End every hold the order has. */
    public function release(SalesOrder $order, string $note, ?User $by = null): int
    {
        return StockReservation::query()->live()
            ->for($order->getMorphClass(), (int) $order->id)
            ->update(['released_at' => now(), 'released_by' => $by?->id, 'release_note' => mb_substr($note, 0, 500), 'updated_at' => now()]);
    }

    /**
     * A challan took $quantity of a product for this order: the hold drops by
     * that much (never below zero), and never stays above what is still owed.
     */
    public function delivered(SalesOrder $order, int $productId, string $quantity, string $challan): void
    {
        $rows = StockReservation::query()->live()
            ->for($order->getMorphClass(), (int) $order->id)
            ->where('product_id', $productId)
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $held = $rows->sum(fn (StockReservation $row) => AvailableToSell::scale((string) $row->quantity));
        $owed = self::wanted($order)[$productId] ?? 0;
        $left = min(max(0, $held - AvailableToSell::scale($quantity)), $owed);

        StockReservation::query()->whereIn('id', $rows->pluck('id'))
            ->update(['released_at' => now(), 'release_note' => "Delivered on {$challan}", 'updated_at' => now()]);

        if ($left > 0) {
            $first = $rows->first();
            StockReservation::create([
                'business_id' => $order->business_id,
                'stock_batch_id' => null,
                'product_id' => $productId,
                'warehouse_id' => $first->warehouse_id,
                'quantity' => number_format($left / 10000, 4, '.', ''),
                'reason' => StockReservation::SALES_ORDER,
                'reference_type' => $order->getMorphClass(),
                'reference_id' => $order->id,
                'created_by' => $first->created_by,
            ]);
        }
    }

    /**
     * ⚠ The guard every challan and counter bill passes, per line, before the
     * stock leaves: a document may take what is on the shelf less what is held
     * for OTHER documents. Stock held for another order goes only with a
     * holder of `sales.stock.override` and a reason; when the shelf itself is
     * short the stock-out's own sentence is the right one, so this stays out
     * of the way.
     *
     * @param  Model|null  $for  the order (or approved request) the goods are for
     *
     * @throws ValidationException
     */
    public static function guardTake(Product $product, Warehouse $warehouse, string $quantity, ?Model $for, ?ReservedStockPass $pass, Model $document): void
    {
        if (! $product->type->isStocked()) {
            return;
        }

        $want = AvailableToSell::scale($quantity);
        $free = AvailableToSell::freeScaled((int) $product->id, (int) $warehouse->id, $for);

        if ($want <= $free) {
            return;
        }

        $orderHolds = StockReservation::query()->live()
            ->whereNull('stock_batch_id')
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->when($for !== null, fn ($q) => $q->where(fn ($w) => $w
                ->where('reference_type', '<>', $for->getMorphClass())
                ->orWhere('reference_id', '<>', (int) $for->getKey())))
            ->get(['reference_type', 'reference_id', 'quantity']);

        $heldForOrders = $orderHolds->sum(fn ($row) => AvailableToSell::scale((string) $row->quantity));

        // Short on the shelf itself, not because of an order: FIFO's sentence.
        if ($heldForOrders <= 0) {
            return;
        }

        $reason = trim((string) $pass?->reason);

        if ($pass !== null && mb_strlen($reason) >= 5 && $pass->by->can(self::PERMISSION)) {
            StockReservationOverride::create([
                'business_id' => $product->business_id,
                'kind' => StockReservationOverride::TAKE_RESERVED,
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => AvailableToSell::unscale(min($want - $free, $heldForOrders)),
                'available' => AvailableToSell::unscale($free),
                'reference_type' => $document->getMorphClass(),
                'reference_id' => $document->getKey(),
                'reason' => mb_substr($reason, 0, 1000),
                'authorised_by' => $pass->by->id,
            ]);

            return;
        }

        $orderType = (new SalesOrder)->getMorphClass();
        $numbers = SalesOrder::query()
            ->whereIn('id', $orderHolds->where('reference_type', $orderType)->pluck('reference_id')->unique())
            ->orderBy('id')->limit(5)->pluck('order_number')->all();
        $for = $numbers === [] ? '' : ' for '.implode(', ', $numbers);

        $sentence = 'Only '.AvailableToSell::unscale($free)." of {$product->name} is available to sell in {$warehouse->name}; ".AvailableToSell::unscale($heldForOrders)." is reserved{$for}. You asked for ".AvailableToSell::unscale($want).'. A Manager or the Owner can let this through with a reason.';

        // `reserved` tells the screen to offer the reason box.
        throw ValidationException::withMessages(['lines' => $sentence, 'reserved' => $sentence]);
    }

    /**
     * Release the order's live rows and hold min(wanted, available to it) per
     * product, one row each.
     *
     * @param  array<int, int>  $wanted
     */
    private function write(SalesOrder $order, ?int $warehouseId, array $wanted, ?User $by, string $why): void
    {
        $this->release($order, $why.': held again', $by);

        if ($warehouseId === null || ! in_array($order->status, [SalesOrderStatus::Open, SalesOrderStatus::PartiallyDelivered], true)) {
            return;
        }

        foreach ($wanted as $productId => $want) {
            $take = min($want, AvailableToSell::freeScaled($productId, $warehouseId, $order));

            if ($take <= 0) {
                continue;
            }

            StockReservation::create([
                'business_id' => $order->business_id,
                'stock_batch_id' => null,
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'quantity' => number_format($take / 10000, 4, '.', ''),
                'reason' => StockReservation::SALES_ORDER,
                'reference_type' => $order->getMorphClass(),
                'reference_id' => $order->id,
                'created_by' => $by?->id,
            ]);
        }
    }
}
