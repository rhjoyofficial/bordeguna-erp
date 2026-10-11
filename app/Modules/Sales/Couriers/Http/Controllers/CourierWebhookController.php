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
 * - CarryBee: X-CarryBee-Webhook-Signature carries our webhook secret (plain
 *   or HMAC). Every answer carries X-CB-Webhook-Integration-Header = the value
 *   CarryBee shows on its Webhook Integration page.
 * - Pathao: X-PATHAO-Signature must equal our webhook secret. Every answer
 *   carries X-Pathao-Merchant-Webhook-Integration-Secret.
 *
 * ⚠⚠ THE PAIRING GUARD (11 Oct 2026, three "Validation error"s in one day).
 * The value echoed back is shown only on the courier's own webhook page, so it
 * lives on the account (Settings > Couriers, "Courier's reply value"); the
 * header comes from the provider definition. Every pairing attempt is recorded
 * on the account (ok, missing_echo or bad_signature, never a header value), and
 * a failed one becomes an item in Needs attention that says what to do.
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
        $payload = $request->json()->all();
        $event = is_string($payload['event'] ?? null) ? $payload['event'] : '';
        $handshake = $event === '' || in_array(Str::lower($event), ['webhook.integration', 'webhook_integration'], true);

        // ⚠⚠ CarryBee, as its checker really behaves (logged 11 Oct 2026, agent
        // CB-Webhook-agent/0.8): every call carries X-CarryBee-Webhook-Signature
        // (our webhook secret, plain or as an HMAC of the body), and the answer
        // must be 202 carrying X-CB-Webhook-Integration-Header = the value
        // CarryBee shows on its Webhook Integration page. The old documented
        // shape (secret in X-CB-Webhook-Integration-Header) is still accepted.
        if ($account->provider->code === 'carrybee') {
            $form = $this->carryBeeSignature($request, $secret);
            $authentic = $form !== null;
        } else {
            $form = 'plain';
            $authentic = $header !== null && is_string($given) && $secret !== null && hash_equals($secret, $given);
        }

        if (! $authentic) {
            Log::notice('Courier webhook refused: secret missing or wrong', ['courier_account_id' => $account->id]);
            $this->logShape($request, $account, 'signature missing or wrong');

            if ($handshake) {
                $account->recordHandshake(false, CourierAccount::PAIRING_BAD_SIGNATURE);
                Log::warning('Courier webhook pairing refused: the secret in the courier form does not match the ERP', [
                    'courier_account_id' => $account->id,
                    'provider' => $account->provider->code,
                ]);
            }

            return response('', 401);
        }

        // ⚠⚠ The value the courier wants back (from the courier's own webhook
        // page, kept in Settings > Couriers). One source of truth: the provider
        // definition names the header, the account holds the value.
        $echo = $account->integrationEchoHeaders();

        if ($handshake) {
            if ($account->needsIntegrationEcho()) {
                // ⚠⚠ Still 202 without the header: the courier then says
                // "Validation error", and Needs attention tells the owner why.
                $account->recordHandshake(false, CourierAccount::PAIRING_MISSING_ECHO);
                Log::warning('Courier webhook pairing: the courier reply value is missing', [
                    'courier_account_id' => $account->id,
                    'provider' => $account->provider->code,
                    'header' => $definition->integrationEchoHeader(),
                    'where' => $definition->integrationEchoPage(),
                ]);
            } else {
                $account->recordHandshake(true);
                Log::info('Courier webhook paired', ['courier_account_id' => $account->id, 'signature' => $form]);
            }

            return response('', 202, $echo);
        }

        $account->recordWebhookEvent();

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
     * What a refused courier request LOOKED like: header names, content type,
     * body keys and length. Never header values or body values, so no secret or
     * customer detail reaches the log.
     */
    private function logShape(Request $request, CourierAccount $account, string $why): void
    {
        $body = $request->json()->all();

        Log::warning('Courier webhook shape', [
            'courier_account_id' => $account->id,
            'why' => $why,
            'headers' => array_keys($request->headers->all()),
            'content_type' => $request->header('Content-Type'),
            'body_keys' => array_keys(is_array($body) ? $body : []),
            'form_keys' => array_keys($request->request->all()),
            'body_length' => strlen((string) $request->getContent()),
        ]);
    }

    /**
     * Which form of our secret the CarryBee call carries, or null if none does:
     * the plain secret, or an HMAC-SHA256 of the raw body (hex or base64, with
     * or without a "sha256=" prefix), in X-CarryBee-Webhook-Signature; or the
     * plain secret in the documented X-CB-Webhook-Integration-Header.
     */
    private function carryBeeSignature(Request $request, ?string $secret): ?string
    {
        if ($secret === null || $secret === '') {
            return null;
        }

        $signature = (string) $request->header('X-CarryBee-Webhook-Signature', '');
        $signature = trim(Str::startsWith(Str::lower($signature), 'sha256=') ? substr($signature, 7) : $signature);
        $body = (string) $request->getContent();

        $candidates = [
            'plain' => $secret,
            'hmac-sha256-hex' => hash_hmac('sha256', $body, $secret),
            'hmac-sha256-base64' => base64_encode(hash_hmac('sha256', $body, $secret, true)),
        ];

        if ($signature !== '') {
            foreach ($candidates as $form => $expected) {
                if (hash_equals($expected, $signature) || hash_equals(Str::lower($expected), Str::lower($signature))) {
                    return $form;
                }
            }
        }

        $legacy = (string) $request->header('X-CB-Webhook-Integration-Header', '');

        return $legacy !== '' && hash_equals($secret, $legacy) ? 'legacy-header' : null;
    }
}
