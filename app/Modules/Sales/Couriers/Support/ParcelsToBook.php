<?php

namespace App\Modules\Sales\Couriers\Support;

use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Couriers\Models\CourierAccount;
use App\Modules\Sales\Couriers\Models\CourierBookingRequest;
use App\Modules\Sales\Couriers\Models\CourierBulkRunItem;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use Illuminate\Support\Collection;

/**
 * Which approved orders are waiting for a courier, and what one click would do
 * for each (bulk booking, Joy 11 Oct 2026).
 *
 * ⭐ Two shapes qualify, and nothing else:
 *   - an approved order with nothing delivered yet: a challan is made for
 *     everything ordered, then booked;
 *   - an order whose ONE challan carries only this order and no courier has
 *     it yet: that challan is booked as it is.
 * A part-delivered order, a challan with several orders, or a challan already
 * billed is left to the order screen, where a person decides.
 */
final class ParcelsToBook
{
    public const MAKE_CHALLAN = 'challan';

    public const BOOK_CHALLAN = 'book';

    /**
     * What one click would do for this order, or why it cannot.
     *
     * @return array{kind: string|null, delivery: Delivery|null, reason: string|null}
     */
    public static function plan(SalesOrder $order): array
    {
        $order->loadMissing('lines.deliveryLines', 'address');

        if ($order->approvalRequest?->status !== ApprovalStatus::Approved) {
            return self::no('The order is not approved.');
        }

        if ($order->address === null) {
            return self::no('The order has no delivery address.');
        }

        if (in_array($order->status, [SalesOrderStatus::Cancelled, SalesOrderStatus::Closed], true)) {
            return self::no('The order is '.$order->status->value.'.');
        }

        $deliveryIds = $order->lines->flatMap(fn (SalesOrderLine $line) => $line->deliveryLines->pluck('delivery_id'))->unique()->values();

        if ($deliveryIds->isEmpty()) {
            return $order->status === SalesOrderStatus::Open
                ? ['kind' => self::MAKE_CHALLAN, 'delivery' => null, 'reason' => null]
                : self::no('Nothing is left to deliver on this order.');
        }

        if ($deliveryIds->count() > 1 || $order->status !== SalesOrderStatus::Delivered) {
            return self::no('The order was delivered in parts. Book each challan from the order.');
        }

        $delivery = Delivery::query()->with('lines.orderLine')->find($deliveryIds->first());

        if ($delivery === null) {
            return self::no('The challan cannot be found.');
        }

        if ($delivery->lines->contains(fn ($line) => (int) ($line->orderLine?->sales_order_id ?? 0) !== (int) $order->id)) {
            return self::no("Challan {$delivery->delivery_number} also carries other goods. Book it from the order.");
        }

        if (Consignment::query()->where('delivery_id', $delivery->id)->exists()) {
            return self::no("Challan {$delivery->delivery_number} is already with a courier.");
        }

        if ($delivery->lines->contains(fn ($line) => (float) $line->quantity_invoiced > 0 || (float) $line->quantity_returned > 0)) {
            return self::no("Challan {$delivery->delivery_number} has already been billed or returned.");
        }

        return ['kind' => self::BOOK_CHALLAN, 'delivery' => $delivery, 'reason' => null];
    }

    /**
     * Approved orders waiting for a courier, newest first.
     *
     * @return Collection<int, SalesOrder>
     */
    public static function orders(int $limit = 300): Collection
    {
        return SalesOrder::query()
            ->with(['customer:id,name', 'address', 'lines.product:id,name,sku,weight_grams', 'lines.deliveryLines'])
            ->whereIn('status', [SalesOrderStatus::Open->value, SalesOrderStatus::Delivered->value])
            ->whereHas('approvalRequest', fn ($q) => $q->where('status', ApprovalStatus::Approved->value))
            ->whereHas('address')
            // Not handed to a courier yet: no line of it sits on a consigned challan.
            ->whereDoesntHave('lines.deliveryLines', fn ($q) => $q->whereIn('delivery_id', Consignment::query()->select('delivery_id')))
            ->latest('id')
            ->limit($limit)
            ->get()
            ->filter(fn (SalesOrder $order) => self::plan($order)['kind'] !== null)
            ->values();
    }

    /** Ids of orders in a run that has not answered for them yet. */
    public static function inFlightOrderIds(): Collection
    {
        return CourierBulkRunItem::query()->whereIn('status', CourierBulkRunItem::OPEN)->pluck('sales_order_id')->map(fn ($id) => (int) $id);
    }

    /** The open booking (requested or unknown) on one of the order's challans, if any. */
    public static function openBooking(SalesOrder $order): ?CourierBookingRequest
    {
        $deliveryIds = $order->lines->flatMap(fn (SalesOrderLine $line) => $line->deliveryLines->pluck('delivery_id'))->unique();

        return $deliveryIds->isEmpty() ? null : CourierBookingRequest::query()->whereIn('delivery_id', $deliveryIds->all())
            ->whereIn('status', [CourierBookingRequest::REQUESTED, CourierBookingRequest::UNKNOWN])->latest('id')->first();
    }

    /**
     * The courier account an order goes with when none is chosen: the one the
     * order screen would preselect, by the official brand routing
     * (ParcelPresenter::accountOptions over BookParcelThroughCourier::linkFor).
     * Null when no account serves the order's brand here.
     *
     * @param  Collection<int, CourierAccount>  $accounts  bookable accounts of the business
     */
    public static function defaultAccount(SalesOrder $order, Collection $accounts, int $businessId): ?CourierAccount
    {
        $best = collect(self::accountChoices($order, $accounts, $businessId))->firstWhere('preselected', true);

        return $best === null ? null : $accounts->firstWhere('id', $best['id']);
    }

    /**
     * The order screen's account options for this order's brand.
     *
     * @param  Collection<int, CourierAccount>  $accounts
     * @return list<array<string, mixed>>
     */
    public static function accountChoices(SalesOrder $order, Collection $accounts, int $businessId): array
    {
        return $accounts->isEmpty() ? [] : ParcelPresenter::accountOptions($accounts, $businessId, self::orderBrandId($order));
    }

    /** The order's brand (null = no brand). */
    public static function orderBrandId(SalesOrder $order): ?int
    {
        $brand = $order->getAttribute('brand_id');

        return $brand === null ? null : (int) $brand;
    }

    /**
     * The first product the warehouse cannot cover for what is still ordered,
     * as a sentence; null when everything is there.
     */
    public static function stockShortfall(SalesOrder $order, Warehouse $warehouse): ?string
    {
        $order->loadMissing('lines.product');
        $needed = [];

        foreach ($order->lines as $line) {
            $needed[$line->product_id] = ($needed[$line->product_id] ?? 0) + (int) round((float) $line->quantityRemaining() * 10000);
        }

        if ($needed === []) {
            return null;
        }

        $have = StockBatch::query()->where('warehouse_id', $warehouse->id)->whereIn('product_id', array_keys($needed))
            ->consumable()->toBase()->selectRaw('product_id, sum(current_quantity) as qty')->groupBy('product_id')->pluck('qty', 'product_id');

        foreach ($needed as $productId => $scaled) {
            $available = (int) round((float) ($have[$productId] ?? 0) * 10000);

            if ($scaled > $available) {
                $product = $order->lines->firstWhere('product_id', $productId)?->product;

                return 'Not enough '.($product?->name ?? 'stock')." in {$warehouse->name}: needs ".self::qty($scaled).', has '.self::qty($available).'.';
            }
        }

        return null;
    }

    private static function qty(int $scaled): string
    {
        return rtrim(rtrim(number_format($scaled / 10000, 4, '.', ''), '0'), '.');
    }

    /** @return array{kind: null, delivery: null, reason: string} */
    private static function no(string $reason): array
    {
        return ['kind' => null, 'delivery' => null, 'reason' => $reason];
    }
}
