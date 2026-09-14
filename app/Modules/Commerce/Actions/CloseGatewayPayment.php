<?php

namespace App\Modules\Commerce\Actions;

use App\Modules\Commerce\Enums\GatewayPaymentStatus;
use App\Modules\Commerce\Models\GatewayPayment;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Support\SslCommerzClient;

/**
 * SSLCommerz says an attempt failed or the customer cancelled it (slice P2b).
 *
 * ⛔ Only a SIGNED callback closes an attempt, and only one still waiting. An
 * unsigned "failed" is exactly what somebody would send to make a paid order look
 * unpaid, and a proven or paid attempt is never moved backwards by anything.
 *
 * ⚠ The order itself is untouched: the customer can try again until it expires.
 */
final class CloseGatewayPayment
{
    /**
     * @param  array<string, mixed>  $callback
     */
    public function handle(Store $store, array $callback, GatewayPaymentStatus $outcome): ?GatewayPayment
    {
        if (! in_array($outcome, [GatewayPaymentStatus::Failed, GatewayPaymentStatus::Cancelled], true)) {
            return null;
        }

        $tranId = $callback['tran_id'] ?? null;

        if (! is_string($tranId) || $tranId === '') {
            return null;
        }

        /** @var GatewayPayment|null $payment */
        $payment = GatewayPayment::query()->where('store_id', $store->id)->where('tran_id', $tranId)->first();

        if ($payment === null || $payment->status !== GatewayPaymentStatus::Initiated) {
            return $payment;
        }

        $gateway = $store->paymentGateway;

        if ($gateway === null || ! (new SslCommerzClient($gateway))->hasValidSignature($callback)) {
            $payment->forceFill(['problem' => 'A payment failure without a valid SSLCommerz signature was ignored.'])->save();

            return $payment;
        }

        $reason = is_scalar($callback['error'] ?? null) && (string) $callback['error'] !== '' ? (string) $callback['error'] : null;

        $payment->forceFill([
            'status' => $outcome,
            'problem' => $reason === null ? null : mb_substr($reason, 0, 255),
        ])->save();

        return $payment;
    }
}
