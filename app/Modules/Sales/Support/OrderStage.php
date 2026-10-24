<?php

namespace App\Modules\Sales\Support;

use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Inventory\Models\StockReservationOverride;
use App\Modules\Sales\Enums\ConsignmentStatus;
use App\Modules\Sales\Enums\DeliveryMethod;
use App\Modules\Sales\Enums\OrderConfirmation;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * ⭐ B18 (Joy's initial plan, 25 Oct 2026): "each order have status". One word
 * for where an order stands, read from the documents (never stored):
 *
 *   waiting_confirmation  nobody has confirmed it with the customer yet
 *   confirmed             the customer said yes; not yet approved or holding stock
 *   reserved / backorder  approved: its stock is held (backorder: held short)
 *   packed                a challan went out, no courier yet
 *   with_courier          a parcel is booked with a courier
 *   delivered             the customer has it (courier delivered, or no courier)
 *   returned              goods came back (a parcel returned, or a sales return)
 *   cancelled / closed    finished by hand
 *
 * ⚠ The furthest stage wins: a parcel with the courier is "with courier" even
 * if nobody marked the call (the confirmation chip still says so beside it).
 *
 * ⚠ Read for a whole page at once (`forOrders`): a fixed number of queries
 * however many rows, the order book's rule (SalesOrdersQueueTest).
 */
final class OrderStage
{
    public const ALL = ['waiting_confirmation', 'confirmed', 'reserved', 'backorder', 'packed', 'with_courier', 'delivered', 'returned', 'cancelled', 'closed'];

    public static function for(SalesOrder $order): string
    {
        return self::forOrders(collect([$order]))[$order->id];
    }

    /**
     * @param  Collection<int, SalesOrder>  $orders
     * @return array<int, string> order id => stage
     */
    public static function forOrders(Collection $orders): array
    {
        if ($orders->isEmpty()) {
            return [];
        }

        $orders = new EloquentCollection($orders->all());
        $orders->loadMissing(['approvalRequest', 'address:id,sales_order_id', 'lines.deliveryLines:id,sales_order_line_id,delivery_id']);

        $deliveryIds = $orders->flatMap(fn (SalesOrder $o) => $o->lines->flatMap(fn ($l) => $l->deliveryLines->pluck('delivery_id')))->unique()->values();
        $deliveries = $deliveryIds->isEmpty() ? collect() : Delivery::query()->whereIn('id', $deliveryIds)
            ->whereNull('reversed_at')
            ->with('consignment:id,delivery_id,status')
            ->get(['id'])->keyBy('id');
        $returnedDeliveries = $deliveries->isEmpty() ? collect() : SalesReturn::query()
            ->whereIn('delivery_id', $deliveries->keys())
            ->whereNull('reverses_return_id')->whereDoesntHave('reversalReturn')
            ->distinct()->pluck('delivery_id')->flip();
        $backorders = StockReservationOverride::query()
            ->where('kind', StockReservationOverride::BACKORDER)
            ->where('reference_type', (new SalesOrder)->getMorphClass())
            ->whereIn('reference_id', $orders->pluck('id'))
            ->distinct()->pluck('reference_id')->flip();

        $out = [];

        foreach ($orders as $order) {
            $mine = $order->lines->flatMap(fn ($l) => $l->deliveryLines->pluck('delivery_id'))->unique()
                ->map(fn ($id) => $deliveries->get($id))->filter()->values();
            $out[$order->id] = self::stage($order, $mine, fn ($id) => $returnedDeliveries->has($id), $backorders->has($order->id));
        }

        return $out;
    }

    /**
     * @param  Collection<int, Delivery>  $deliveries  the order's live challans
     */
    private static function stage(SalesOrder $order, Collection $deliveries, Closure $returned, bool $backorder): string
    {
        if ($order->status === SalesOrderStatus::Cancelled || $order->approvalRequest?->status === ApprovalStatus::Rejected) {
            return 'cancelled';
        }

        $status = fn (Delivery $d) => $d->consignment?->status;

        if ($deliveries->contains(fn (Delivery $d) => in_array($status($d), [ConsignmentStatus::Returned, ConsignmentStatus::PaidReturn], true) || $returned($d->id))) {
            return 'returned';
        }

        if ($order->status === SalesOrderStatus::Closed) {
            return 'closed';
        }

        $courierDelivered = $deliveries->contains(fn (Delivery $d) => in_array($status($d), [ConsignmentStatus::Delivered, ConsignmentStatus::PartiallyDelivered], true));
        // Everything left with no courier: our own person, a rider or a
        // hand-over took it (an order of before B18: one with no address).
        $leftWithoutCourier = $order->status === SalesOrderStatus::Delivered
            && $deliveries->every(fn (Delivery $d) => $d->consignment === null)
            && ($order->delivery_method !== null ? $order->delivery_method !== DeliveryMethod::Courier : $order->address === null);

        if ($courierDelivered || $leftWithoutCourier) {
            return 'delivered';
        }

        if ($deliveries->contains(fn (Delivery $d) => $status($d) === ConsignmentStatus::Booked)) {
            return 'with_courier';
        }

        if ($deliveries->isNotEmpty()) {
            return 'packed';
        }

        if ($order->approvalRequest?->status === ApprovalStatus::Approved) {
            return $backorder ? 'backorder' : 'reserved';
        }

        return $order->confirmation_status === OrderConfirmation::Confirmed ? 'confirmed' : 'waiting_confirmation';
    }
}
