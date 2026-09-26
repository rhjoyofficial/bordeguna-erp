<?php

namespace App\Modules\Sales\Support;

use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderHqConfirmation;
use Illuminate\Database\Eloquent\Builder;

/**
 * The orders HQ placed, and only those (HQ docs/88 A point 3).
 *
 * ⛔ HQ reads back, and may ask to cancel, ONLY an order that came from one of its own drafts: the
 * order has a `sales_order_hq_confirmations` row. An order a colleague typed in, or a customer placed on
 * a shop, is not reachable through HQ's order routes at all, whatever its reference: reading it answers
 * 404, exactly as if it did not exist. That keeps the new write (cancel) and its read-back to the orders
 * HQ is answerable for.
 */
final class HqPlacedOrders
{
    /** By the reference a customer quotes (`shop_reference`) or the ERP's `order_number`. */
    public static function find(int $businessId, string $reference): ?SalesOrder
    {
        return self::query($businessId)
            ->where(fn (Builder $q) => $q->where('shop_reference', $reference)->orWhere('order_number', $reference))
            ->first();
    }

    /** By the key HQ sent when it asked for the order (the reconcile path). */
    public static function byKey(int $businessId, string $key): ?SalesOrder
    {
        $confirmation = SalesOrderHqConfirmation::query()->where('business_id', $businessId)->where('idempotency_key', $key)->first();

        return $confirmation === null ? null : self::query($businessId)->whereKey($confirmation->sales_order_id)->first();
    }

    /** @return Builder<SalesOrder> */
    private static function query(int $businessId): Builder
    {
        return SalesOrder::query()
            ->where('business_id', $businessId)
            ->whereIn('id', SalesOrderHqConfirmation::query()->where('business_id', $businessId)->select('sales_order_id'))
            ->with(['lines.product', 'address', 'approvalRequest', 'store', 'customer']);
    }
}
