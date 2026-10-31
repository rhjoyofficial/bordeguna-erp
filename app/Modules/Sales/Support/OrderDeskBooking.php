<?php

namespace App\Modules\Sales\Support;

use App\Models\User;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Couriers\Jobs\BookParcelsInBulk;
use App\Modules\Sales\Couriers\Models\CourierAccount;
use App\Modules\Sales\Couriers\Models\CourierBulkRun;
use App\Modules\Sales\Couriers\Models\CourierBulkRunItem;
use App\Modules\Sales\Couriers\Support\ParcelPresenter;
use App\Modules\Sales\Couriers\Support\ParcelsToBook;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Order desk (Joy, 31 Oct 2026): "Save and book" in one press.
 *
 * The order goes to the courier through the SAME run the Parcels to book
 * screen starts (BookParcelsInBulk with one item), so every check of it
 * holds: approved, address mapped to a courier zone, stock in the order's
 * warehouse, no booking already in flight, COD = what is still due.
 * Whatever it refuses is said on the order page, and the order stays in
 * Parcels to book.
 *
 * Joy's decision 1: one account is preselected, the person may choose any
 * bookable account, and that account takes the whole order.
 */
final class OrderDeskBooking
{
    /**
     * The account asked for, checked before the order is written. Null id =
     * the order's own default account, worked out when the run starts.
     *
     * @return array{account_id: ?int}
     */
    public static function account(int|string|null $id, User $user): array
    {
        // The Parcels to book screen's own gate.
        if (! $user->can('sales.sell') || ! $user->can('couriers.parcels.book')) {
            throw ValidationException::withMessages(['then' => 'Booking a courier needs the parcel booking permission. Save the order; the office books it.']);
        }

        if ($id === null || $id === '') {
            return ['account_id' => null];
        }

        $businessId = (int) app(BusinessContext::class)->id();

        if (ParcelPresenter::bookableAccounts($businessId)->firstWhere('id', (int) $id) === null) {
            throw ValidationException::withMessages(['courier_account_id' => 'That courier account cannot book for this business. Choose another.']);
        }

        return ['account_id' => (int) $id];
    }

    /**
     * After the order's transaction: start the booking, or say why not.
     *
     * @param  array{account_id: ?int}  $choice
     */
    public static function start(SalesOrder $order, array $choice, User $user): string
    {
        $order = $order->fresh(['approvalRequest', 'customer', 'address', 'lines.product', 'lines.deliveryLines']);
        $recorded = "Sales order {$order->order_number} recorded";

        if ($order->approvalRequest?->isPending()) {
            return "{$recorded}. It waits for approval; then it goes to the courier.";
        }

        $plan = ParcelsToBook::plan($order);

        if ($plan['kind'] === null) {
            return "{$recorded}, not sent to the courier: {$plan['reason']}";
        }

        $businessId = (int) $order->business_id;
        $accounts = ParcelPresenter::bookableAccounts($businessId);
        $account = $choice['account_id'] !== null
            ? $accounts->firstWhere('id', $choice['account_id'])
            : ParcelsToBook::defaultAccount($order, $accounts, $businessId);

        if (! $account instanceof CourierAccount) {
            return "{$recorded}, not sent to the courier: no courier account serves it. An Owner links one in Settings > Couriers.";
        }

        $run = CourierBulkRun::create([
            'business_id' => $businessId,
            'courier_account_id' => $account->id,
            'warehouse_id' => $order->warehouse_id,
            'status' => CourierBulkRun::QUEUED,
            'total' => 1,
            'created_by' => $user->id,
        ]);

        CourierBulkRunItem::create([
            'business_id' => $businessId,
            'courier_bulk_run_id' => $run->id,
            'sales_order_id' => $order->id,
            'status' => CourierBulkRunItem::PENDING,
        ]);

        BookParcelsInBulk::dispatch($businessId, $run->id, (int) $user->id);

        return "{$recorded} and sent to {$account->label}.";
    }
}
