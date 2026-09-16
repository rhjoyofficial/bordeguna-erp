<?php

namespace App\Modules\Commerce\Actions;

use App\Modules\Commerce\Enums\GatewayRefundStatus;
use App\Modules\Commerce\Models\GatewayPayment;
use App\Modules\Commerce\Models\GatewayRefund;
use App\Modules\Foundation\Support\SystemActor;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Sales\Actions\RecordCustomerRefund;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Book a refund SSLCommerz has confirmed (slice P4): a customer refund on the
 * order, out of the gateway account the payment was booked into.
 *
 *   Dr 1140 Accounts Receivable (customer)   Cr 1146 the gateway account
 *
 * ⚠ The fee. SSLCommerz usually keeps its charge on a refunded payment, and then
 * nothing more is posted: 1146 for this payment ends at minus the fee, which is
 * what SSLCommerz nets out of a later deposit. Whether it returns the fee is for
 * Joy to find out (16 September); if it does, a full refund adds
 * `Dr 1146 / Cr 6475` here.
 *
 * ⭐ As with a payment, a confirmed refund that cannot be booked (a period
 * closed, a mapping missing) stays `confirmed`, says why, and is retried; the
 * confirmation is never rolled back with the accounting.
 */
final class BookGatewayRefund
{
    public function __construct(private readonly RecordCustomerRefund $refunds) {}

    public function handle(GatewayRefund $refund): GatewayRefund
    {
        if ($refund->status !== GatewayRefundStatus::Confirmed) {
            return $refund;
        }

        TenantScope::enter($refund->business_id);

        try {
            DB::transaction(function () use ($refund) {
                /** @var GatewayRefund $locked */
                $locked = GatewayRefund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();

                if ($locked->status !== GatewayRefundStatus::Confirmed) {
                    return;
                }

                /** @var GatewayPayment $payment */
                $payment = GatewayPayment::query()->with('receipt.moneyAccount')->whereKey($locked->gateway_payment_id)->firstOrFail();

                // ⚠ Out of the account the payment went INTO, not whatever the
                // shop is set to today.
                $account = $payment->receipt?->moneyAccount;

                if ($account === null || ! $account->isGateway()) {
                    throw new RuntimeException('The payment was not booked into a payment gateway account, so there is nothing to refund it out of.');
                }

                /** @var SalesOrder $order */
                $order = SalesOrder::query()->whereKey($locked->sales_order_id)->firstOrFail();

                $on = Carbon::parse($locked->refunded_at ?? Carbon::now())->timezone(config('app.timezone'))->startOfDay();

                $customerRefund = $this->refunds->forOrder(
                    order: $order,
                    moneyAccount: $account,
                    amount: $locked->amountMoney()->toTaka(),
                    createdBy: SystemActor::user(),
                    refundedOn: $on,
                    reference: $locked->refund_ref_id ?: $locked->refund_trans_id,
                    note: "Refunded through SSLCommerz, {$locked->refund_trans_id} for transaction {$payment->tran_id}",
                    throughGateway: $locked,
                );

                $locked->forceFill([
                    'status' => GatewayRefundStatus::Refunded,
                    'customer_refund_id' => $customerRefund->id,
                    'problem' => null,
                ])->save();
            });
        } catch (Throwable $e) {
            Log::warning('An SSLCommerz refund was confirmed but could not be booked', [
                'refund_trans_id' => $refund->refund_trans_id,
                'reason' => $e->getMessage(),
            ]);

            $refund->forceFill(['problem' => mb_substr('Refunded but not yet booked: '.$e->getMessage(), 0, 255)])->save();

            return $refund->fresh();
        }

        return $refund->fresh();
    }
}
