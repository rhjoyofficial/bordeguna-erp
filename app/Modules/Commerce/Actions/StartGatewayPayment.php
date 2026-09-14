<?php

namespace App\Modules\Commerce\Actions;

use App\Modules\Commerce\Enums\GatewayPaymentStatus;
use App\Modules\Commerce\Exceptions\GatewayUnavailable;
use App\Modules\Commerce\Models\GatewayPayment;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StorePaymentGateway;
use App\Modules\Commerce\Support\SslCommerzClient;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Facades\DB;

/**
 * Open a payment page for an order (SSLCommerz slice P2b).
 *
 * ⭐ Every attempt is its own row and its own transaction id,
 * `{shop reference}-{attempt}`. A retry never reuses an id the gateway has
 * already seen, and an earlier page a customer still has open can still be paid:
 * whichever attempt the gateway proves is the one booked.
 *
 * ⚠ Refused for an order the shop no longer takes payment for (cancelled, or
 * already paid), and when the shop's gateway would not be able to record the
 * payment. Better to say so before the customer's money moves than after.
 */
final class StartGatewayPayment
{
    /**
     * SSLCommerz refuses a payment below this.
     */
    public const MINIMUM_TAKA = 10;

    /**
     * @param  array{success: string, fail: string, cancel: string, ipn: string}  $urls
     * @return array{payment: GatewayPayment, url: string}
     *
     * @throws GatewayUnavailable
     */
    public function handle(SalesOrder $order, Store $store, array $urls): array
    {
        /** @var StorePaymentGateway|null $gateway */
        $gateway = $store->paymentGateway;

        if ($gateway === null || ! $gateway->isUsable()) {
            throw GatewayUnavailable::because('This shop is not taking payment online right now.');
        }

        if ($order->store_id !== $store->id) {
            throw GatewayUnavailable::because('That order belongs to another shop.');
        }

        $payment = DB::transaction(function () use ($order, $store, $gateway) {
            /** @var SalesOrder $order */
            $order = SalesOrder::query()->whereKey($order->id)->lockForUpdate()->with('lines.deliveryLines')->firstOrFail();

            if ($order->status === SalesOrderStatus::Cancelled) {
                throw GatewayUnavailable::because("Order {$order->shop_reference} was cancelled, so it cannot be paid.");
            }

            $tookMoney = GatewayPayment::query()->where('sales_order_id', $order->id)
                ->whereIn('status', [GatewayPaymentStatus::Proven->value, GatewayPaymentStatus::Paid->value])
                ->exists();

            $amount = $order->stillToPay();

            if ($tookMoney || $amount->isZero()) {
                throw GatewayUnavailable::because("Order {$order->shop_reference} is already paid.");
            }

            if ($amount->minor < self::MINIMUM_TAKA * 100) {
                throw GatewayUnavailable::because('An online payment must be at least '.self::MINIMUM_TAKA.' taka.');
            }

            $attempt = (int) GatewayPayment::query()->where('sales_order_id', $order->id)->max('attempt') + 1;
            $tranId = ($order->shop_reference ?? $order->order_number).'-'.$attempt;

            // ⚠ SSLCommerz allows 30 characters. A shop reference is 21 with a
            // three-letter prefix; only a very long prefix and many retries reach it.
            if (strlen($tranId) > 30) {
                throw GatewayUnavailable::because('This order has been tried too many times. Place it again.');
            }

            return GatewayPayment::create([
                'business_id' => $order->business_id,
                'store_id' => $store->id,
                'sales_order_id' => $order->id,
                'provider' => StorePaymentGateway::SSLCOMMERZ,
                'tran_id' => $tranId,
                'attempt' => $attempt,
                'amount' => $amount->toTaka(),
                'status' => GatewayPaymentStatus::Initiated,
                'is_live' => (bool) $gateway->is_live,
            ]);
        });

        try {
            $url = (new SslCommerzClient($gateway))->openSession($payment, $order, $urls);
        } catch (GatewayUnavailable $e) {
            // ⚠ A page that never opened can never be paid: this attempt is over.
            $payment->forceFill(['status' => GatewayPaymentStatus::Failed, 'problem' => mb_substr($e->getMessage(), 0, 255)])->save();

            throw $e;
        }

        return ['payment' => $payment, 'url' => $url];
    }
}
