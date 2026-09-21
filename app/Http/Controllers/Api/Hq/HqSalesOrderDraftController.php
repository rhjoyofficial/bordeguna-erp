<?php

namespace App\Http\Controllers\Api\Hq;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Actions\CreateSalesOrderDraft;
use App\Modules\Sales\Exceptions\SalesOrderDraftRefused;
use App\Modules\Sales\Models\SalesOrderDraft;
use App\Modules\Sales\Models\SalesOrderDraftLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\Rule;

/**
 * HQ's draft sales order (HQ docs/83 C.3, docs/79): the narrowest write HQ has on the ERP.
 *
 * ⛔ A DRAFT AND NOTHING AFTER IT. `POST sales-order-drafts` writes a `SalesOrderDraft`: no sales
 * order, no order number, no approval request, no customer, no stock, no journal, no notification.
 * `GET sales-order-drafts/{key}` reads one back by the caller's key, which is how HQ checks that what
 * it proposed is what the ERP holds. There is no route that confirms, changes or cancels a draft.
 *
 * ⛔ A NEW ABILITY, `sales.orders.draft`, that no token holds until one is issued for it by name with
 * `hq:api:token --write`. Every body is an explicit field list, and states its own effects.
 */
final class HqSalesOrderDraftController extends Controller
{
    public const ABILITY = 'sales.orders.draft';

    /** The only top-level keys a draft request may carry. Anything else is refused, not ignored. */
    public const REQUEST_KEYS = ['idempotency_key', 'store_key', 'destination', 'lines', 'payment_method', 'proposed', 'hq_reference'];

    private const AMOUNT = 'regex:/^\d{1,13}(\.\d{1,2})?$/';

    private const PRICE = 'regex:/^\d{1,11}(\.\d{1,4})?$/';

    public function store(Request $request, int $business, CreateSalesOrderDraft $action): JsonResponse
    {
        $this->requireAbility($request);

        $unknown = array_values(array_diff(array_keys($request->all()), self::REQUEST_KEYS));
        if ($unknown !== []) {
            return $this->error('unknown_field', 'A draft carries no '.implode(', ', $unknown).' in this version.', 422,
                collect($unknown)->mapWithKeys(fn ($k) => [$k => ['Not taken on a draft.']])->all());
        }

        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'store_key' => ['required', 'string', 'max:64'],
            'destination' => ['required', 'array:name,phone,district,thana,area,address_line,landmark'],
            'destination.name' => ['required', 'string', 'max:120'],
            'destination.phone' => ['required', 'string', 'max:32'],
            'destination.district' => ['required', 'string', 'max:64'],
            'destination.thana' => ['required', 'string', 'max:64'],
            'destination.area' => ['nullable', 'string', 'max:120'],
            'destination.address_line' => ['required', 'string', 'max:255'],
            'destination.landmark' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1', 'max:20'],
            'lines.*' => ['required', 'array:sku,quantity,unit_price'],
            'lines.*.sku' => ['required', 'string', 'max:64'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'lines.*.unit_price' => ['required', 'string', self::PRICE],
            // ⚠ Cash on delivery only. An online payment is a link the customer follows; that is a
            // later decision (HQ docs/79 H.1), not something a draft may choose.
            'payment_method' => ['required', Rule::in(['cod'])],
            'proposed' => ['required', 'array:goods_total,delivery_charge,agreed_total'],
            'proposed.goods_total' => ['required', 'string', self::AMOUNT],
            'proposed.delivery_charge' => ['required', 'string', self::AMOUNT],
            'proposed.agreed_total' => ['required', 'string', self::AMOUNT],
            'hq_reference' => ['required', 'array:proposal_fingerprint,approval_uuid,tool_call_id,conversation_uuid'],
            'hq_reference.proposal_fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'hq_reference.approval_uuid' => ['nullable', 'string', 'max:64'],
            'hq_reference.tool_call_id' => ['nullable', 'integer', 'min:1'],
            'hq_reference.conversation_uuid' => ['nullable', 'string', 'max:64'],
        ]);

        $header = $request->header('Idempotency-Key');
        if ($header !== null && $header !== $data['idempotency_key']) {
            return $this->error('idempotency_key_mismatch', 'The Idempotency-Key header and the body name different keys.', 422);
        }

        try {
            [$draft, $replayed] = $action->handle(
                businessId: $business,
                input: $data,
                by: $request->user(),
                tokenId: $request->user()->currentAccessToken()?->id,
                correlationId: Context::get('correlation_id'),
            );
        } catch (SalesOrderDraftRefused $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->status, $e->errors);
        }

        return $this->ok($business, $this->body($draft, $replayed), $replayed ? 200 : 201);
    }

    public function show(Request $request, int $business, string $key): JsonResponse
    {
        $this->requireAbility($request);

        $draft = SalesOrderDraft::query()->where('business_id', $business)->where('idempotency_key', $key)->with(['lines', 'store'])->first();
        if ($draft === null) {
            return $this->error('not_found', 'No draft with that key in this business.', 404);
        }

        return $this->ok($business, $this->body($draft, null));
    }

    /** @return array<string, mixed> */
    private function body(SalesOrderDraft $draft, ?bool $replayed): array
    {
        return array_filter([
            'id' => $draft->id,
            'status' => $draft->status,
            'idempotency_key' => $draft->idempotency_key,
            'hq_proposal_fingerprint' => $draft->hq_proposal_fingerprint,
            // ⚠ Rebuilt in a fixed order, never echoed from the column: MySQL stores a JSON object
            // with its keys re-sorted, so the stored value comes back in a different order on
            // production than it went in (found by the scratch-MySQL rehearsal, HQ docs/83 C.3.3).
            'hq_reference' => [
                'proposal_fingerprint' => $draft->hq_reference['proposal_fingerprint'] ?? null,
                'approval_uuid' => $draft->hq_reference['approval_uuid'] ?? null,
                'tool_call_id' => $draft->hq_reference['tool_call_id'] ?? null,
                'conversation_uuid' => $draft->hq_reference['conversation_uuid'] ?? null,
            ],
            'store' => ['key' => $draft->store?->key],
            'customer' => ['resolution' => $draft->customer_resolution, 'id' => $draft->customer_id],
            'recipient' => [
                'name' => $draft->recipient_name,
                'phone' => $draft->recipient_phone,
                'phone_normalized' => $draft->recipient_phone_normalized,
            ],
            'destination' => [
                'district' => $draft->district,
                'thana' => $draft->thana,
                'area' => $draft->area,
                'postcode' => $draft->postcode,
                'address_line' => $draft->address_line,
                'landmark' => $draft->landmark,
            ],
            'lines' => $draft->lines->map(fn (SalesOrderDraftLine $l) => [
                'sku' => $l->sku,
                'product_id' => $l->product_id,
                'quantity' => $l->quantity,
                'unit_price' => self::decimal((string) $l->unit_price, 4),
                'line_total' => self::decimal((string) $l->line_total, 2),
                'price_note' => $l->price_note,
                'made_to_order' => (bool) $l->made_to_order,
            ])->values()->all(),
            'payment_method' => $draft->payment_method,
            'goods_total' => self::decimal((string) $draft->goods_total, 2),
            'delivery_charge' => self::decimal((string) $draft->delivery_charge, 2),
            'order_discount' => self::decimal((string) $draft->order_discount, 2),
            'agreed_total' => self::decimal((string) $draft->agreed_total, 2),
            'priced_at' => $draft->priced_at?->toIso8601String(),
            'created_at' => $draft->created_at?->toIso8601String(),
            // ⭐ Stated, not implied: what did NOT happen because of this draft.
            'effects' => [
                'sales_order_created' => false,
                'order_number_taken' => false,
                'approval_requested' => false,
                'customer_created' => false,
                'stock_reserved' => false,
                'journal_posted' => false,
                'notification_sent' => false,
            ],
            'next_step' => 'None. A draft becomes an order only through a later capability that does not exist yet.',
            'replayed' => $replayed,
        ], fn ($value) => $value !== null);
    }

    /** A decimal column as a string with exactly `$places` places, the same on MySQL and SQLite. */
    private static function decimal(string $value, int $places): string
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return ($whole === '' ? '0' : $whole).'.'.str_pad(substr($fraction, 0, $places), $places, '0');
    }

    private function requireAbility(Request $request): void
    {
        if (! $request->user()->tokenCan(self::ABILITY)) {
            abort(response()->json(['error' => ['code' => 'forbidden', 'message' => 'Token lacks ability '.self::ABILITY.'.']], 403));
        }
    }

    /** @param array<string, mixed> $data */
    private function ok(int $business, array $data, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => ['business_id' => $business, 'contract' => SalesOrderDraft::CONTRACT, 'as_of' => now()->toIso8601String()],
        ], $status);
    }

    /** @param array<string, list<string>> $errors */
    private function error(string $code, string $message, int $status, array $errors = []): JsonResponse
    {
        $body = ['error' => ['code' => $code, 'message' => $message]];
        if ($errors !== []) {
            $body['errors'] = $errors;
        }

        return response()->json($body, $status);
    }
}
