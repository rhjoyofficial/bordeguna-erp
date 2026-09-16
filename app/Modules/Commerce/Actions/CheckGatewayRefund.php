<?php

namespace App\Modules\Commerce\Actions;

use App\Modules\Commerce\Enums\GatewayRefundStatus;
use App\Modules\Commerce\Models\GatewayRefund;
use App\Modules\Commerce\Support\SslCommerzClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Move one gateway refund forward by asking SSLCommerz (slice P4). Called right
 * after a refund is requested, by "Check now", and every 30 minutes.
 *
 * - requested:  ask SSLCommerz to start it, under its own `refund_trans_id`
 *               (the documented id that tells one refund of a payment from
 *               another, which is what makes asking again safe)
 * - processing: ask where it stands; `refunded` confirms it, `cancelled` ends it
 * - confirmed:  book it
 *
 * ⚠ An answer that cannot be read changes nothing but the note beside it. Only
 * an answer SSLCommerz actually gave moves a refund, and only forwards.
 *
 * ⛔ Nothing SSLCommerz sends is logged whole, and the store password never is.
 */
final class CheckGatewayRefund
{
    public function __construct(private readonly BookGatewayRefund $book) {}

    public function handle(GatewayRefund $refund): GatewayRefund
    {
        /** @var GatewayRefund $refund */
        $refund = GatewayRefund::query()->withoutGlobalScopes()->with('payment.store.paymentGateway')->findOrFail($refund->id);

        return match ($refund->status) {
            GatewayRefundStatus::Requested => $this->start($refund),
            GatewayRefundStatus::Processing => $this->follow($refund),
            GatewayRefundStatus::Confirmed => $this->book->handle($refund),
            default => $refund,
        };
    }

    private function start(GatewayRefund $refund): GatewayRefund
    {
        $client = $this->client($refund);

        if ($client === null) {
            return $refund;
        }

        $answer = $client->initiateRefund($refund, $refund->payment);
        $refund->last_checked_at = Carbon::now();

        if ($answer === null) {
            return $this->note($refund, 'SSLCommerz did not answer. It is asked again at the next check.');
        }

        $connect = strtoupper((string) ($answer['APIConnect'] ?? ''));
        $reason = $this->reason($answer);

        // ⚠ INVALID_REQUEST, FAILED or INACTIVE: SSLCommerz did not take the
        // request at all, so nothing is leaving and the money is released.
        if ($connect !== 'DONE') {
            return $this->end($refund, GatewayRefundStatus::Failed, $connect, 'SSLCommerz refused the request ('.($connect ?: 'no answer code').')'.($reason ? ": {$reason}" : '.'));
        }

        if (! $this->isAboutThisPayment($answer, $refund)) {
            return $this->note($refund, 'SSLCommerz answered about a different transaction. Nothing was changed; it is asked again at the next check.');
        }

        $status = strtolower((string) ($answer['status'] ?? ''));
        $reference = trim((string) ($answer['refund_ref_id'] ?? ''));

        if ($status === 'failed') {
            return $this->end($refund, GatewayRefundStatus::Failed, $status, 'SSLCommerz refused the refund'.($reason ? ": {$reason}" : '.'));
        }

        if (! in_array($status, ['success', 'processing'], true)) {
            return $this->note($refund, 'SSLCommerz gave an answer that could not be read ('.($status ?: 'no status').'). It is asked again at the next check.');
        }

        if ($reference === '') {
            return $this->note($refund, 'SSLCommerz accepted the refund but gave no refund reference. It is asked again at the next check.');
        }

        // ⚠⚠ `processing` means "already asked". If SSLCommerz names a refund
        // this ERP already holds under ANOTHER request, this request did not
        // start anything: it is ended, and its money released, rather than
        // tracking one refund twice.
        $holder = GatewayRefund::query()->withoutGlobalScopes()->where('refund_ref_id', $reference)->whereKeyNot($refund->id)->first();

        if ($holder !== null) {
            return $this->end($refund, GatewayRefundStatus::Failed, $status, "SSLCommerz says this payment's refund {$holder->refund_trans_id} is already in progress. Ask again once it is finished.");
        }

        $refund->forceFill([
            'status' => GatewayRefundStatus::Processing,
            'refund_ref_id' => mb_substr($reference, 0, 50),
            'gateway_status' => $status,
            'initiated_at' => $refund->initiated_at ?? Carbon::now(),
            'problem' => null,
        ])->save();

        // SSLCommerz may already have finished it.
        return $this->follow($refund->fresh('payment.store.paymentGateway'));
    }

    private function follow(GatewayRefund $refund): GatewayRefund
    {
        $client = $this->client($refund);

        if ($client === null) {
            return $refund;
        }

        $answer = $client->refundStatus((string) $refund->refund_ref_id);
        $refund->last_checked_at = Carbon::now();

        if ($answer === null) {
            return $this->note($refund, 'SSLCommerz could not be asked where the refund stands. It is asked again at the next check.');
        }

        $connect = strtoupper((string) ($answer['APIConnect'] ?? ''));

        // ⚠ Unlike a refused START, a refused QUESTION says nothing about the
        // refund SSLCommerz already accepted. It stays where it was.
        if ($connect !== 'DONE') {
            return $this->note($refund, 'SSLCommerz did not answer the status question ('.($connect ?: 'no answer code').'). It is asked again at the next check.');
        }

        $status = strtolower((string) ($answer['status'] ?? ''));

        if ($status === 'processing') {
            $refund->forceFill(['gateway_status' => $status, 'problem' => null])->save();

            return $refund;
        }

        if ($status === 'cancelled') {
            $reason = $this->reason($answer);

            return $this->end($refund, GatewayRefundStatus::Cancelled, $status, 'SSLCommerz cancelled the refund'.($reason ? ": {$reason}" : '.'));
        }

        if ($status !== 'refunded') {
            return $this->note($refund, 'SSLCommerz gave a refund status that could not be read ('.($status ?: 'no status').'). It is asked again at the next check.');
        }

        $refund->forceFill([
            'status' => GatewayRefundStatus::Confirmed,
            'gateway_status' => $status,
            'refunded_at' => $this->when($answer['refunded_on'] ?? null),
            'problem' => null,
        ])->save();

        return $this->book->handle($refund);
    }

    /**
     * The client for the store the payment was taken on, or null (with the
     * reason noted) when it cannot be asked.
     */
    private function client(GatewayRefund $refund): ?SslCommerzClient
    {
        $gateway = $refund->payment?->store?->paymentGateway;

        if ($gateway === null || trim((string) $gateway->merchant_id) === '' || ! $gateway->hasSecret()) {
            $this->note($refund, "The shop's SSLCommerz store id and password are not saved, so SSLCommerz cannot be asked.");

            return null;
        }

        if ($gateway->is_live !== $refund->payment->is_live) {
            $this->note($refund, 'The shop has been switched between the SSLCommerz sandbox and live stores since this payment, so SSLCommerz cannot be asked about it.');

            return null;
        }

        return new SslCommerzClient($gateway);
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    private function isAboutThisPayment(array $answer, GatewayRefund $refund): bool
    {
        $bankTranId = $answer['bank_tran_id'] ?? null;

        return ! is_string($bankTranId) || $bankTranId === '' || $bankTranId === $refund->payment->bank_tran_id;
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    private function reason(array $answer): ?string
    {
        $reason = $answer['errorReason'] ?? null;

        return is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
    }

    private function when(mixed $value): Carbon
    {
        // ⚠ SSLCommerz reports Dhaka time.
        try {
            $when = is_string($value) && trim($value) !== ''
                ? Carbon::parse($value, config('app.timezone'))
                : Carbon::now();
        } catch (\Throwable) {
            return Carbon::now();
        }

        // ⚠ Never ahead of this server's clock: the ledger refuses a journal
        // dated in the future, and a refund booked days late because two clocks
        // disagree would be a refund that looks unbooked for no reason.
        // ⚠ Nor SSLCommerz's empty date: the real sandbox answers
        // "0000-00-00 00:00:00" (seen 16 September), which parses to year -1.
        return $when->isFuture() || $when->year < 2000 ? Carbon::now() : $when;
    }

    private function note(GatewayRefund $refund, string $problem): GatewayRefund
    {
        Log::warning('An SSLCommerz refund could not move forward', [
            'refund_trans_id' => $refund->refund_trans_id,
            'reason' => $problem,
        ]);

        $refund->forceFill(['problem' => mb_substr($problem, 0, 255)])->save();

        return $refund;
    }

    private function end(GatewayRefund $refund, GatewayRefundStatus $status, string $gatewayStatus, string $problem): GatewayRefund
    {
        $refund->forceFill([
            'status' => $status,
            'gateway_status' => mb_substr($gatewayStatus, 0, 30) ?: null,
            'problem' => mb_substr($problem, 0, 255),
        ])->save();

        return $refund;
    }
}
