<?php

namespace App\Modules\Commerce\Console\Commands;

use App\Modules\Commerce\Actions\BookGatewayPayment;
use App\Modules\Commerce\Actions\PlaceWebsiteOrder;
use App\Modules\Commerce\Enums\GatewayPaymentStatus;
use App\Modules\Commerce\Models\GatewayPayment;
use App\Modules\Commerce\Support\PlacedOrderPresenter;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Sales\Actions\CancelSalesOrder;
use App\Modules\Sales\Enums\SalesChannel;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The two things online payments need done without anybody pressing anything
 * (SSLCommerz slice P2c).
 *
 * 1. BOOK what the gateway proved and could not be booked at the time - a
 *    mapping missing for an hour, a database blip. The proof was kept for exactly
 *    this; the money must reach the books without a person noticing first.
 * 2. CANCEL an online order still unpaid 24 hours after it was placed (Joy, 13
 *    September), and mark its open attempts expired. Nobody was ever asked to
 *    confirm it, so nobody is told.
 *
 * ⚠ An order the gateway has proven a payment for is never cancelled here, even
 * if booking it is still failing: that customer has paid.
 *
 * ⚠ A payment that arrives after its order was cancelled is still booked - the
 * money is already taken - and the order then reads refund owed.
 */
class SettleOnlinePayments extends Command
{
    protected $signature = 'commerce:online-payments
        {--business= : Limit to one business id}
        {--dry-run : Say what would be booked and cancelled, and change nothing}';

    protected $description = 'Book proven online payments not yet booked, and cancel online orders left unpaid for 24 hours';

    public function handle(BookGatewayPayment $book, CancelSalesOrder $cancel): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = Carbon::now()->subHours(PlacedOrderPresenter::PAYMENT_WINDOW_HOURS);

        $businesses = Business::query()
            ->when($this->option('business'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('id')->get();

        foreach ($businesses as $business) {
            TenantScope::enter($business->id);

            $proven = GatewayPayment::query()->where('status', GatewayPaymentStatus::Proven->value)->get();
            $booked = 0;

            foreach ($proven as $payment) {
                if ($dryRun) {
                    $this->line("  would book {$payment->tran_id}");

                    continue;
                }

                if ($book->handle($payment)->status === GatewayPaymentStatus::Paid) {
                    $booked++;
                } else {
                    $this->warn("  {$payment->tran_id} still not booked: {$payment->fresh()->problem}");
                }
            }

            TenantScope::enter($business->id);

            $unpaid = SalesOrder::query()
                ->where('channel', SalesChannel::Website->value)
                ->where('payment_method', PlaceWebsiteOrder::ONLINE)
                ->where('status', SalesOrderStatus::Open->value)
                ->whereDoesntHave('approvalRequest')
                ->where('created_at', '<=', $cutoff)
                ->whereNotIn('id', GatewayPayment::query()
                    ->whereIn('status', [GatewayPaymentStatus::Proven->value, GatewayPaymentStatus::Paid->value])
                    ->select('sales_order_id'))
                ->get();

            $cancelled = 0;

            foreach ($unpaid as $order) {
                if ($dryRun) {
                    $this->line("  would cancel {$order->shop_reference}");

                    continue;
                }

                try {
                    $cancel->handle($order);
                } catch (Throwable $e) {
                    $this->warn("  {$order->shop_reference} not cancelled: {$e->getMessage()}");

                    continue;
                }

                GatewayPayment::query()->where('sales_order_id', $order->id)
                    ->where('status', GatewayPaymentStatus::Initiated->value)
                    ->update(['status' => GatewayPaymentStatus::Expired->value, 'updated_at' => now()]);

                $cancelled++;
            }

            $this->line(sprintf('%s: %d booked, %d unpaid order(s) cancelled', $business->name, $booked, $cancelled));
        }

        TenantScope::leave();

        return self::SUCCESS;
    }
}
