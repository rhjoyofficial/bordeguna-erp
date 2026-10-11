<?php

namespace App\Modules\Sales\Couriers\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Scopes\BusinessScope;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Sales\Couriers\Models\CourierAccount;
use App\Modules\Sales\Couriers\Models\CourierShipmentEvent;
use App\Modules\Sales\Couriers\Tracking\RecordShipmentEvent;
use App\Modules\Sales\Models\Consignment;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * POST /webhooks/couriers/{account uuid}: a courier telling us a parcel moved.
 *
 * ⚠⚠ A NOTIFICATION, NEVER ACCOUNTING EVIDENCE (ruling 1). This records the
 * word on the parcel's timeline and moves its COURIER status. It never raises
 * an invoice, a receipt or a journal: "delivered" makes the order screen ask a
 * person to confirm through the existing outcome.
 *
 * ⚠⚠ THE BUSINESS COMES FROM OUR RECORD, NEVER FROM THE PAYLOAD (ruling 12).
 * The parcel is found by THIS account's own consignment number or our merchant
 * reference; whatever store or business the body names is not believed.
 *
 * ⚠ Authenticity is a shared header secret only (neither courier documents an
 * HMAC), compared in constant time. No session, no CSRF, throttled.
 *
 * - CarryBee: X-CB-Webhook-Integration-Header must equal our webhook secret.
 *   The `webhook.integration` handshake is answered 202 with the header echoed.
 * - Pathao: X-PATHAO-Signature must equal our webhook secret. Every answer
 *   carries X-Pathao-Merchant-Webhook-Integration-Secret (the account-level
 *   value saved in Settings > Couriers).
 *
 * An unknown parcel is logged (ids only) and answered 202, so the courier does
 * not retry forever a parcel that belongs to a shop still booking by itself.
 */
class CourierWebhookController extends Controller
{
    public function __invoke(Request $request, string $uuid, RecordShipmentEvent $events): Response
    {
        $account = CourierAccount::query()->with('provider')->where('uuid', $uuid)->first();

        if ($account === null) {
            return response('', 404);
        }

        $definition = $account->definition();
        $header = $definition->webhookSecretHeader();
        $secret = $account->webhookSecretValue();
        $given = $header === null ? null : $request->header($header);

        if ($header === null || $secret === null || ! is_string($given) || ! hash_equals($secret, $given)) {
            Log::notice('Courier webhook refused: secret missing or wrong', ['courier_account_id' => $account->id]);

            return response('', 401);
        }

        $echo = $this->echoHeaders($account, $header, $secret);
        $payload = $request->json()->all();
        $event = is_string($payload['event'] ?? null) ? $payload['event'] : '';

        if ($event === '' || in_array(Str::lower($event), ['webhook.integration', 'webhook_integration'], true)) {
            return response('', 202, $echo);
        }

        $consignment = $this->findParcel($account, $payload);

        if ($consignment === null) {
            Log::info('Courier webhook for a parcel the ERP does not know', [
                'courier_account_id' => $account->id,
                'event' => Str::limit($event, 80, ''),
            ]);

            return response('', 202, $echo);
        }

        $before = app(BusinessContext::class)->id();

        try {
            TenantScope::enter((int) $consignment->business_id);

            $events->handle(
                $consignment,
                CourierShipmentEvent::WEBHOOK,
                $event,
                $event,
                $payload['timestamptz'] ?? $payload['updated_at'] ?? $payload['timestamp'] ?? null,
                [
                    'collected_amount' => $payload['collected_amount'] ?? null,
                    'cod_fee' => $payload['cod_fee'] ?? null,
                    'delivery_fee' => $payload['delivery_fee'] ?? null,
                    'attempt' => $payload['attempt'] ?? null,
                    'reason' => $payload['reason'] ?? null,
                    'invoice_id' => $payload['invoice_id'] ?? null,
                ],
                hash('sha256', $request->getContent()),
            );
        } finally {
            $before === null ? TenantScope::leave() : TenantScope::enter((int) $before);
        }

        return response('', 202, $echo);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function findParcel(CourierAccount $account, array $payload): ?Consignment
    {
        $number = $payload['consignment_id'] ?? null;
        $reference = $payload['merchant_order_id'] ?? null;
        $query = fn () => Consignment::query()->withoutGlobalScope(BusinessScope::class)->where('courier_account_id', $account->id);

        if (is_scalar($number) && (string) $number !== '') {
            $found = $query()->where(fn ($q) => $q->where('consignment_number', (string) $number)->orWhere('provider_consignment_id', (string) $number))->first();

            if ($found !== null) {
                return $found;
            }
        }

        if (is_scalar($reference) && (string) $reference !== '') {
            return $query()->where('merchant_order_ref', (string) $reference)->first();
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function echoHeaders(CourierAccount $account, string $header, string $secret): array
    {
        return match ($account->provider->code) {
            'carrybee' => [$header => $secret],
            'pathao' => ($echo = $account->integrationSecret()) === null ? [] : ['X-Pathao-Merchant-Webhook-Integration-Secret' => $echo],
            default => [],
        };
    }
}
