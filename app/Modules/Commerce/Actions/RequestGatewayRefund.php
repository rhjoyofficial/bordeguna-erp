<?php

namespace App\Modules\Commerce\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Commerce\Enums\GatewayPaymentStatus;
use App\Modules\Commerce\Models\GatewayPayment;
use App\Modules\Commerce\Models\GatewayRefund;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Send a customer's money back through SSLCommerz (slice P4; Joy, 16 September:
 * the Owner only, partial refunds allowed).
 *
 * ⭐⭐ The request is WRITTEN AND COMMITTED FIRST, reserving the money on its
 * order, and only then is SSLCommerz asked. A timeout leaves a row the next check
 * resolves under the same `refund_trans_id`; nothing a second click does can
 * refund twice.
 *
 * ⚠⚠ Capped twice, under the payment's and the order's locks: by what this
 * payment has not yet given back, and by what the order still holds (which
 * already subtracts every refund in flight and every refund made by hand).
 *
 * ⚠ The books do not move here. They move when SSLCommerz says the money is
 * back with the customer (BookGatewayRefund).
 */
final class RequestGatewayRefund
{
    public function __construct(private readonly CheckGatewayRefund $check) {}

    /**
     * @throws ValidationException
     */
    public function handle(GatewayPayment $payment, string $amount, string $remarks, User $requestedBy): GatewayRefund
    {
        $remarks = trim($remarks);

        if ($remarks === '') {
            throw ValidationException::withMessages(['remarks' => 'Say why the money is going back. SSLCommerz requires a reason.']);
        }

        $asking = Money::ofTaka($amount);

        if ($asking->minor <= 0) {
            throw ValidationException::withMessages(['amount' => 'A refund must be greater than zero.']);
        }

        $gateway = $payment->store?->paymentGateway;

        if ($gateway === null || trim((string) $gateway->merchant_id) === '' || ! $gateway->hasSecret()) {
            throw ValidationException::withMessages([
                'gateway_payment_id' => "This shop's SSLCommerz store id and password are not saved, so nothing can be refunded through SSLCommerz. Pay the customer back by bank or bKash.",
            ]);
        }

        // ⚠ A payment taken on the sandbox exists only there, and a live one only
        // on live. A shop switched since would send the refund to the wrong place.
        if ($gateway->is_live !== $payment->is_live) {
            throw ValidationException::withMessages([
                'gateway_payment_id' => sprintf(
                    'Transaction %s was paid on the SSLCommerz %s store, and the shop is now set to %s. It can only be refunded where it was paid.',
                    $payment->tran_id,
                    $payment->is_live ? 'live' : 'sandbox',
                    $gateway->is_live ? 'live' : 'sandbox',
                ),
            ]);
        }

        $refund = DB::transaction(function () use ($payment, $asking, $remarks, $requestedBy) {
            // ⚠ Payment first, then order: the order BookGatewayPayment locks in.
            /** @var GatewayPayment $locked */
            $locked = GatewayPayment::query()->withoutGlobalScopes()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            /** @var SalesOrder $order */
            $order = SalesOrder::query()->withoutGlobalScopes()->whereKey($locked->sales_order_id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== GatewayPaymentStatus::Paid) {
                throw ValidationException::withMessages([
                    'gateway_payment_id' => "Transaction {$locked->tran_id} is not a booked payment ({$locked->status->label()}), so there is nothing to refund through SSLCommerz.",
                ]);
            }

            if (blank($locked->bank_tran_id)) {
                throw ValidationException::withMessages([
                    'gateway_payment_id' => "SSLCommerz gave no bank transaction id for {$locked->tran_id}, and its refund API needs one. Pay the customer back by bank or bKash.",
                ]);
            }

            $left = Money::ofMinor(($locked->amountPaidMoney()?->minor ?? 0) - GatewayRefund::takenFrom($locked)->minor);

            if ($asking->minor > $left->minor) {
                throw ValidationException::withMessages([
                    'amount' => $left->minor <= 0
                        ? "Transaction {$locked->tran_id} has nothing left to refund through SSLCommerz."
                        : "Transaction {$locked->tran_id} has only {$left->format()} left to refund through SSLCommerz.",
                ]);
            }

            $held = $order->advanceHeld();

            if ($asking->minor > $held->minor) {
                throw ValidationException::withMessages([
                    'amount' => $held->minor <= 0
                        ? "Order {$order->order_number} holds none of the customer's money."
                        : "Order {$order->order_number} holds only {$held->format()} of the customer's money.",
                ]);
            }

            $number = GatewayRefund::query()->withoutGlobalScopes()->where('gateway_payment_id', $locked->id)->count() + 1;
            $refundTransId = "{$locked->tran_id}-R{$number}";

            // ⚠ SSLCommerz allows 30 characters. Refused rather than cut: a cut id
            // could name another refund.
            if (strlen($refundTransId) > 30) {
                throw ValidationException::withMessages([
                    'amount' => "Transaction {$locked->tran_id} has been refunded too many times to name another refund. Pay the rest back by bank or bKash.",
                ]);
            }

            return GatewayRefund::create([
                'business_id' => $locked->business_id,
                'gateway_payment_id' => $locked->id,
                'sales_order_id' => $order->id,
                'refund_trans_id' => $refundTransId,
                'amount' => $asking->toTaka(),
                'remarks' => mb_substr($remarks, 0, 255),
                'requested_by' => $requestedBy->id,
            ]);
        });

        return $this->check->handle($refund);
    }
}
