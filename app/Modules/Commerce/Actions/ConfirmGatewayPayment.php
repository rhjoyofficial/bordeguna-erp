<?php

namespace App\Modules\Commerce\Actions;

use App\Modules\Commerce\Enums\GatewayPaymentStatus;
use App\Modules\Commerce\Models\GatewayPayment;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Support\SslCommerzClient;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SSLCommerz says an attempt was paid - from the customer's browser coming back,
 * or from its own server (IPN). Check it, keep the proof, then book it.
 *
 * ⛔⛔ NOTHING IN THE CALLBACK IS BELIEVED. Its signature must verify with this
 * store's password, and then the Validation API - called by the ERP, not handed
 * over by the browser - must confirm the same transaction, in BDT, for at least
 * the amount asked. A callback that fails either changes nothing but a note.
 *
 * ⭐ The proof is committed BEFORE the money is booked, in its own transaction.
 * If booking then fails, the attempt stays `proven`: the customer's money is on
 * record and the booking can be retried, rather than both vanishing together.
 *
 * ⚠ Both callbacks usually arrive, in either order. The second finds the attempt
 * already proven and does nothing; `val_id` is unique, so even a race cannot
 * prove two attempts with one payment.
 */
final class ConfirmGatewayPayment
{
    public function __construct(private readonly BookGatewayPayment $book) {}

    /**
     * @param  array<string, mixed>  $callback
     */
    public function handle(Store $store, array $callback): ?GatewayPayment
    {
        $tranId = $callback['tran_id'] ?? null;

        if (! is_string($tranId) || $tranId === '') {
            return null;
        }

        /** @var GatewayPayment|null $payment */
        $payment = GatewayPayment::query()->where('store_id', $store->id)->where('tran_id', $tranId)->first();

        if ($payment === null) {
            return null;
        }

        if ($payment->status->tookMoney()) {
            return $this->book->handle($payment);
        }

        $gateway = $store->paymentGateway;

        if ($gateway === null || ! $gateway->hasSecret()) {
            return $this->note($payment, 'A payment callback arrived but the shop has no SSLCommerz password saved to check it with.');
        }

        $client = new SslCommerzClient($gateway);

        if (! $client->hasValidSignature($callback)) {
            return $this->note($payment, 'A payment callback without a valid SSLCommerz signature was ignored.');
        }

        $valId = $callback['val_id'] ?? null;

        if (! is_string($valId) || $valId === '') {
            return $this->note($payment, 'A signed payment callback carried no validation id.');
        }

        $answer = $client->validate($valId);

        if ($answer === null) {
            return $this->note($payment, 'SSLCommerz could not be reached to confirm a payment. Its server notification will try again.');
        }

        if (($why = SslCommerzClient::whyNotProven($answer, $payment)) !== null) {
            return $this->note($payment, $why);
        }

        try {
            DB::transaction(function () use ($payment, $answer, $valId) {
                /** @var GatewayPayment $locked */
                $locked = GatewayPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

                if ($locked->status->tookMoney()) {
                    return;
                }

                $locked->forceFill([
                    'status' => GatewayPaymentStatus::Proven,
                    'val_id' => $valId,
                    'bank_tran_id' => self::text($answer['bank_tran_id'] ?? null, 80),
                    'amount_paid' => (string) $answer['amount'],
                    'store_amount' => is_scalar($answer['store_amount'] ?? null) && preg_match('/^\d+(\.\d+)?$/', (string) $answer['store_amount'])
                        ? (string) $answer['store_amount']
                        : null,
                    'card_type' => self::text($answer['card_type'] ?? null, 60),
                    'risk_level' => is_numeric($answer['risk_level'] ?? null) ? (int) $answer['risk_level'] : null,
                    'risk_title' => self::text($answer['risk_title'] ?? null, 120),
                    'proven_at' => Carbon::now(),
                    'problem' => null,
                ])->save();
            });
        } catch (UniqueConstraintViolationException) {
            return $this->note($payment, 'This SSLCommerz validation id already proved another attempt.');
        }

        return $this->book->handle($payment->fresh());
    }

    private function note(GatewayPayment $payment, string $problem): GatewayPayment
    {
        $payment->forceFill(['problem' => mb_substr($problem, 0, 255)])->save();

        return $payment;
    }

    private static function text(mixed $value, int $length): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? mb_substr((string) $value, 0, $length) : null;
    }
}
