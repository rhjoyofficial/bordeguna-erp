<?php

namespace App\Modules\Sales\Couriers\Jobs;

use App\Models\User;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\Actions\ResolveSellingPrice;
use App\Modules\Sales\Couriers\Booking\BookParcelThroughCourier;
use App\Modules\Sales\Couriers\Booking\ParcelDraft;
use App\Modules\Sales\Couriers\Locations\CourierDestination;
use App\Modules\Sales\Couriers\Locations\Unresolved;
use App\Modules\Sales\Couriers\Models\CourierAccount;
use App\Modules\Sales\Couriers\Models\CourierBookingRequest;
use App\Modules\Sales\Couriers\Models\CourierBulkRun;
use App\Modules\Sales\Couriers\Models\CourierBulkRunItem;
use App\Modules\Sales\Couriers\Support\ParcelPresenter;
use App\Modules\Sales\Couriers\Support\ParcelsToBook;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * "Create challans and book" for the orders of one bulk run (Joy, 11 Oct 2026).
 *
 * ⚠⚠ NOTHING NEW ABOUT BOOKING HAPPENS HERE. The challan is RecordDelivery's
 * and the booking is BookParcelThroughCourier's, with its intent row before the
 * call, one booking in flight per challan, and its lock. This job only walks
 * the list, in order, slowly, and writes down each answer.
 *
 * ⚠ Per order, the order matters:
 *   1. the address and the courier's place for it are checked BEFORE any stock
 *      moves; a place the courier does not know yet is "fix address" and no
 *      challan is made;
 *   2. stock is checked per product and the short one is named;
 *   3. the challan is made (or the order's existing one is used);
 *   4. the parcel is booked: booked / refused / unknown.
 *
 * ⚠ No retries (tries = 1). An item already answered is never touched again,
 * so a job started twice for one run does nothing the second time; a parcel
 * whose answer was lost is left UNKNOWN for a person to settle, never re-sent.
 */
class BookParcelsInBulk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        public int $businessId,
        public int $runId,
        public int $userId,
    ) {}

    public function handle(RecordDelivery $deliver, BookParcelThroughCourier $book, ResolveSellingPrice $resolver): void
    {
        // ⚠ Bug hunt 16 Oct 2026: a queue worker is one long process. Without
        // this, the next job it ran inherited this business and this user. Put
        // back exactly what was there before (nothing, on a worker; the
        // request's own business and user, when the queue runs in-process).
        $previousBusiness = app(BusinessContext::class)->id();
        $previousUser = Auth::user();

        TenantScope::enter($this->businessId);
        // onceUsingId, not loginUsingId: no session is written, so nothing of
        // this user is left in the worker's session store either.
        Auth::onceUsingId($this->userId);

        try {
            $this->run($deliver, $book, $resolver);
        } finally {
            $previousBusiness === null ? TenantScope::leave() : TenantScope::enter($previousBusiness);
            $previousUser === null ? Auth::forgetUser() : Auth::setUser($previousUser);
        }
    }

    private function run(RecordDelivery $deliver, BookParcelThroughCourier $book, ResolveSellingPrice $resolver): void
    {

        /** @var User $user */
        $user = User::query()->findOrFail($this->userId);
        $run = CourierBulkRun::query()->findOrFail($this->runId);

        if ($run->isFinished()) {
            return;
        }

        $run->forceFill(['status' => CourierBulkRun::RUNNING, 'started_at' => $run->started_at ?? now()])->save();

        $accounts = ParcelPresenter::bookableAccounts($this->businessId);
        $pace = max(0, (int) config('couriers.bulk_pace_ms', 300));
        $first = true;

        $items = CourierBulkRunItem::query()->where('courier_bulk_run_id', $run->id)->where('status', CourierBulkRunItem::PENDING)->orderBy('id')->get();

        foreach ($items as $item) {
            $item->forceFill(['status' => CourierBulkRunItem::WORKING])->save();

            try {
                $calledCourier = $this->one($item, $run, $accounts, $user, $deliver, $book, $resolver, $pace, $first);
                $first = $first && ! $calledCourier;
            } catch (Throwable $e) {
                report($e);
                $this->answer($item, CourierBulkRunItem::REFUSED, 'Something went wrong: '.$e->getMessage());
            }

            $run->recount();
        }

        $run->forceFill(['status' => CourierBulkRun::FINISHED, 'finished_at' => now()])->save();
    }

    public function failed(Throwable $exception): void
    {
        $run = CourierBulkRun::query()->withoutGlobalScopes()->find($this->runId);

        if ($run === null) {
            return;
        }

        // ⚠⚠ Bug hunt 16 Oct 2026: the items were left pending or working, and
        // ParcelsToBook counts every open item as "being booked" whatever its
        // run's state, so a run killed mid-way (a deploy, a crash) blocked its
        // orders from bulk booking for ever. Each one is now closed with what
        // is known: never sent, or sent with no answer. The second is not
        // retried blindly: a booking whose answer was lost is settled through
        // its booking request (ruling 13), which still guards the challan.
        $items = CourierBulkRunItem::query()->withoutGlobalScopes()->where('courier_bulk_run_id', $run->id);

        (clone $items)->where('status', CourierBulkRunItem::PENDING)->update([
            'status' => CourierBulkRunItem::REFUSED,
            'message' => 'The run stopped before this order was sent. Nothing went to the courier; select it again.',
            'updated_at' => now(),
        ]);
        (clone $items)->where('status', CourierBulkRunItem::WORKING)->update([
            'status' => CourierBulkRunItem::UNKNOWN,
            'message' => 'The run stopped while this order was being booked. Check with the courier before booking it again.',
            'updated_at' => now(),
        ]);

        $run->forceFill(['status' => CourierBulkRun::FAILED, 'error' => Str::limit($exception->getMessage(), 480, ''), 'finished_at' => now()])->save();
        $run->recount();
    }

    /**
     * @param  Collection<int, CourierAccount>  $accounts
     * @return bool whether the courier was called
     */
    private function one(
        CourierBulkRunItem $item,
        CourierBulkRun $run,
        $accounts,
        User $user,
        RecordDelivery $deliver,
        BookParcelThroughCourier $book,
        ResolveSellingPrice $resolver,
        int $pace,
        bool $first,
    ): bool {
        $order = SalesOrder::query()->with(['customer', 'address', 'lines.product', 'lines.deliveryLines'])->find($item->sales_order_id);

        if ($order === null) {
            $this->answer($item, CourierBulkRunItem::REFUSED, 'The order cannot be found.');

            return false;
        }

        $open = ParcelsToBook::openBooking($order);

        if ($open !== null) {
            $this->answer($item, CourierBulkRunItem::UNKNOWN, 'An earlier booking of this order has no clear answer yet. Settle it on the order first.', ['courier_booking_request_id' => $open->id, 'delivery_id' => $open->delivery_id]);

            return false;
        }

        $plan = ParcelsToBook::plan($order);

        if ($plan['kind'] === null) {
            $this->answer($item, CourierBulkRunItem::REFUSED, (string) $plan['reason']);

            return false;
        }

        $account = $run->courier_account_id !== null
            ? $accounts->firstWhere('id', $run->courier_account_id)
            : ParcelsToBook::defaultAccount($order, $accounts, $this->businessId);

        if ($account === null) {
            $this->answer($item, CourierBulkRunItem::REFUSED, 'No courier account serves this order (or its brand) here. An Owner links one in Settings > Couriers.');

            return false;
        }

        $item->forceFill(['courier_account_id' => $account->id])->save();

        // 1. The courier's place for the address, before anything moves.
        $address = $order->address;
        $destination = CourierDestination::resolve($account->definition()->code(), $address?->district, $address?->thana, $address?->area);

        if ($destination instanceof Unresolved) {
            $this->answer($item, CourierBulkRunItem::FIX_ADDRESS, $destination->message);

            return false;
        }

        $delivery = $plan['delivery'];

        if ($delivery === null) {
            $warehouse = $this->warehouseFor($order, $run);

            if ($warehouse === null) {
                $this->answer($item, CourierBulkRunItem::REFUSED, 'Choose the warehouse the parcels leave from.');

                return false;
            }

            // 2. Stock, named per product.
            $short = ParcelsToBook::stockShortfall($order, $warehouse);

            if ($short !== null) {
                $this->answer($item, CourierBulkRunItem::REFUSED, $short);

                return false;
            }

            // 3. The challan, for everything still ordered.
            try {
                $delivery = $deliver->handle(
                    customer: $order->customer,
                    warehouse: $warehouse,
                    lines: $order->lines
                        ->filter(fn (SalesOrderLine $line) => (float) $line->quantityRemaining() > 0)
                        ->map(fn (SalesOrderLine $line) => ['product' => $line->product, 'quantity' => $line->quantityRemaining(), 'orderLine' => $line])
                        ->values()->all(),
                    createdBy: $user,
                    note: "Bulk booking #{$run->id}",
                );
            } catch (InsufficientStockException $e) {
                $this->answer($item, CourierBulkRunItem::REFUSED, 'Not enough stock: '.$e->getMessage());

                return false;
            } catch (ValidationException $e) {
                $this->answer($item, CourierBulkRunItem::REFUSED, (string) collect($e->errors())->flatten()->first());

                return false;
            } catch (PeriodClosedException|MappingNotFound $e) {
                $this->answer($item, CourierBulkRunItem::REFUSED, $e->getMessage());

                return false;
            }
        }

        $item->forceFill(['delivery_id' => $delivery->id])->save();

        // 4. The booking. COD = what the challan would bill, rounded up.
        $draft = ParcelDraft::for($delivery->fresh(), $resolver);
        $cod = $draft->codWholeTaka() ?? 0;

        if (! $first && $pace > 0) {
            usleep($pace * 1000);
        }

        try {
            $intent = $book->handle($delivery->fresh(), $account, ['cod_amount' => (string) $cod], $user);
        } catch (ValidationException $e) {
            $this->answer($item, CourierBulkRunItem::REFUSED, 'Challan '.$delivery->delivery_number.' made, not booked: '.collect($e->errors())->flatten()->first(), ['cod_amount' => $cod]);

            return false;
        }

        $extra = ['courier_booking_request_id' => $intent->id, 'cod_amount' => $cod];

        if ($intent->status === CourierBookingRequest::SUCCEEDED) {
            $consignment = Consignment::query()->find($intent->consignment_id);
            $this->answer($item, CourierBulkRunItem::BOOKED, null, $extra + [
                'consignment_id' => $intent->consignment_id,
                'consignment_number' => $consignment?->consignment_number ?? $intent->provider_consignment_id,
                'fee_quoted' => $consignment?->provider_fee_quoted,
            ]);
        } elseif ($intent->status === CourierBookingRequest::FAILED) {
            $this->answer($item, CourierBulkRunItem::REFUSED, "{$account->label} refused: {$intent->error}", $extra);
        } else {
            $this->answer($item, CourierBulkRunItem::UNKNOWN, "{$account->label} gave no clear answer ({$intent->error}). Settle it on the order; it will not be sent again by itself.", $extra);
        }

        return true;
    }

    private function warehouseFor(SalesOrder $order, CourierBulkRun $run): ?Warehouse
    {
        $id = $order->warehouse_id ?? $run->warehouse_id;

        return $id === null ? null : Warehouse::query()->where('is_active', true)->find($id);
    }

    /** @param array<string, mixed> $extra */
    private function answer(CourierBulkRunItem $item, string $status, ?string $message, array $extra = []): void
    {
        $item->forceFill($extra + ['status' => $status, 'message' => $message === null ? null : Str::limit($message, 480, '')])->save();
    }
}
