<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Models\StockReservationOverride;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\AvailableToSell;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Support\OrderReservations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ⭐ RESERVED STOCK (Joy, 24 Oct 2026, Q4 = A, R81, B17): per product, which
 * documents hold how much and since when; the backorders (confirmed orders
 * holding less than they want); and Release by hand for a Manager or the
 * Owner, with a reason.
 *
 * ⚠ Release by hand ends ONE order's hold on ONE product. The order stays
 * confirmed and may still be delivered; its hold is taken again only by an
 * edit or a reversed challan. A staff purchase or an owner withdrawal is not
 * released here: cancelling that request is what frees it (their price was
 * frozen on the batch held).
 */
class ReservedStockController extends Controller
{
    public function index(Request $request): Response
    {
        $orderType = (new SalesOrder)->getMorphClass();

        $rows = StockReservation::query()->live()
            ->with(['product:id,name,sku', 'warehouse:id,name', 'createdBy:id,name'])
            ->orderBy('created_at')
            ->get();

        $orders = SalesOrder::query()
            ->whereIn('id', $rows->where('reference_type', $orderType)->pluck('reference_id')->unique())
            ->with('customer:id,name')
            ->get(['id', 'order_number', 'customer_id'])
            ->keyBy('id');

        $grid = AvailableToSell::grid($rows->pluck('product_id')->filter()->unique()->values()->all());

        $products = $rows->groupBy(fn (StockReservation $row) => $row->product_id.'-'.$row->warehouse_id)
            ->map(function ($group) use ($orders, $orderType, $grid) {
                /** @var StockReservation $first */
                $first = $group->first();
                $cell = $grid[(int) $first->warehouse_id][(int) $first->product_id] ?? ['on_hand' => '0', 'reserved' => '0', 'available' => '0'];

                // Batch slices of one staff purchase are one hold to a person.
                $holds = $group->groupBy(fn (StockReservation $row) => $row->reference_type.'-'.$row->reference_id)
                    ->map(function ($held) use ($orders, $orderType) {
                        /** @var StockReservation $one */
                        $one = $held->first();
                        $order = $one->reference_type === $orderType ? $orders->get($one->reference_id) : null;

                        return [
                            'id' => $one->id,
                            'kind' => $order !== null ? 'order' : $one->reason,
                            'document' => $order?->order_number ?? self::label($one->reason).' #'.$one->reference_id,
                            'url' => $order !== null ? '/sales/orders/'.$order->id : null,
                            'customer' => $order?->customer?->name,
                            'quantity' => AvailableToSell::unscale($held->sum(fn ($r) => AvailableToSell::scale((string) $r->quantity))),
                            'since' => $held->min('created_at')?->toDateString(),
                            'by' => $one->createdBy?->name,
                            'releasable' => $order !== null,
                        ];
                    })->values();

                return [
                    'key' => $first->product_id.'-'.$first->warehouse_id,
                    'product' => $first->product?->name,
                    'sku' => $first->product?->sku,
                    'product_id' => $first->product_id,
                    'warehouse' => $first->warehouse?->name,
                    'on_hand' => $cell['on_hand'],
                    'reserved' => $cell['reserved'],
                    'available' => $cell['available'],
                    'holds' => $holds,
                ];
            })
            ->sortBy('product')
            ->values();

        return Inertia::render('sales/reserved-stock/index', [
            'products' => $products,
            'backorders' => $this->backorders(),
            'can_release' => $request->user()->can(OrderReservations::PERMISSION),
        ]);
    }

    /**
     * Release one order's hold on one product by hand.
     *
     * ⚠ Under a lock and re-read: a second click finds nothing live and says so.
     */
    public function release(Request $request, StockReservation $reservation): RedirectResponse
    {
        abort_unless($request->user()->can(OrderReservations::PERMISSION), 403);

        $data = $request->validate(
            ['reason' => ['required', 'string', 'min:5', 'max:1000']],
            ['reason.required' => 'Say why the hold is released.', 'reason.min' => 'Say why in at least 5 characters.'],
        );

        $orderType = (new SalesOrder)->getMorphClass();

        if ($reservation->reference_type !== $orderType) {
            throw ValidationException::withMessages(['reason' => 'Only an order\'s hold is released here. Cancel the request to free its stock.']);
        }

        DB::transaction(function () use ($reservation, $data, $request, $orderType) {
            $live = StockReservation::query()->live()
                ->for($orderType, (int) $reservation->reference_id)
                ->where('product_id', $reservation->product_id)
                ->lockForUpdate()
                ->get();

            if ($live->isEmpty()) {
                throw ValidationException::withMessages(['reason' => 'That hold was already released.']);
            }

            $quantity = $live->sum(fn ($r) => AvailableToSell::scale((string) $r->quantity));
            $reason = trim($data['reason']);

            StockReservation::query()->whereIn('id', $live->pluck('id'))->update([
                'released_at' => now(),
                'released_by' => $request->user()->id,
                'release_note' => mb_substr('Released by hand: '.$reason, 0, 500),
                'updated_at' => now(),
            ]);

            StockReservationOverride::create([
                'business_id' => $reservation->business_id,
                'kind' => StockReservationOverride::RELEASE,
                'product_id' => $reservation->product_id,
                'warehouse_id' => $reservation->warehouse_id,
                'quantity' => AvailableToSell::unscale($quantity),
                'reference_type' => $orderType,
                'reference_id' => $reservation->reference_id,
                'reason' => mb_substr($reason, 0, 1000),
                'authorised_by' => $request->user()->id,
            ]);

            $order = SalesOrder::query()->find($reservation->reference_id);

            if ($order !== null) {
                activity()->performedOn($order)->causedBy($request->user())
                    ->withProperties(['product_id' => $reservation->product_id, 'quantity' => AvailableToSell::unscale($quantity), 'reason' => $reason])
                    ->log('stock hold released by hand');
            }
        });

        return back()->with('success', 'Hold released.');
    }

    /**
     * Confirmed orders that hold less than they still want, per product.
     *
     * @return array<int, array<string, mixed>>
     */
    private function backorders(): array
    {
        $orders = SalesOrder::query()
            ->whereIn('status', [SalesOrderStatus::Open, SalesOrderStatus::PartiallyDelivered])
            ->whereHas('approvalRequest', fn ($q) => $q->where('status', ApprovalStatus::Approved))
            ->with('customer:id,name')
            ->orderBy('id')
            ->get(['id', 'order_number', 'customer_id', 'warehouse_id', 'status']);

        $rows = [];
        $names = [];
        $warehouses = Warehouse::query()->pluck('name', 'id');

        foreach ($orders as $order) {
            $held = OrderReservations::held($order);

            foreach (OrderReservations::wanted($order) as $productId => $want) {
                $have = $held[$productId] ?? 0;

                if ($want <= $have) {
                    continue;
                }

                $names[$productId] ??= Product::query()->whereKey($productId)->value('name');
                $since = StockReservationOverride::query()
                    ->where('kind', StockReservationOverride::BACKORDER)
                    ->where('reference_type', $order->getMorphClass())->where('reference_id', $order->id)
                    ->where('product_id', $productId)
                    ->latest('id')->first(['reason', 'created_at']);

                $rows[] = [
                    'key' => $order->id.'-'.$productId,
                    'order' => $order->order_number,
                    'url' => '/sales/orders/'.$order->id,
                    'customer' => $order->customer?->name,
                    'product' => $names[$productId],
                    'warehouse' => $warehouses[OrderReservations::warehouseIdOf($order)] ?? null,
                    'wanted' => AvailableToSell::unscale($want),
                    'held' => AvailableToSell::unscale($have),
                    'short' => AvailableToSell::unscale($want - $have),
                    'reason' => $since?->reason,
                    'since' => $since?->created_at?->toDateString(),
                ];
            }
        }

        return $rows;
    }

    private static function label(string $reason): string
    {
        return match ($reason) {
            'staff_purchase' => 'Staff purchase',
            'owner_withdrawal' => 'Owner withdrawal',
            default => ucfirst(str_replace('_', ' ', $reason)),
        };
    }
}
