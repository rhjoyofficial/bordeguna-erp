<?php

namespace App\Http\Controllers\Api\Hq;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\Money;
use App\Modules\Commerce\Models\Store;
use App\Modules\Sales\Actions\CancelHqSalesOrder;
use App\Modules\Sales\Actions\ConfirmHqSalesOrderDraft;
use App\Modules\Sales\Exceptions\SalesOrderDraftRefused;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderHqCancellation;
use App\Modules\Sales\Models\SalesOrderHqConfirmation;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Modules\Sales\Support\HqOrderPricing;
use App\Modules\Sales\Support\HqPlacedOrders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;

/**
 * HQ's order routes after the draft (HQ docs/88 A point 3, docs/79 F): a quote, an order made from an
 * approved draft, the read-back, and HQ's cancellation.
 *
 * ⛔ EACH BEHIND ITS OWN ABILITY, and the two writes behind abilities no token holds unless issued by
 * name with `hq:api:token --write`: `sales.orders.record` (a draft becomes an order, which then WAITS in
 * the ERP's approval inbox) and `sales.orders.cancel` (only an order HQ placed, only while nothing has
 * been delivered). The quote and the read-back are reads (`sales.view`), and the read-back reaches only
 * orders HQ placed. `sales.orders.approve` is never issuable to HQ: approving is a person's act here.
 *
 * Every body is an explicit field list stamped `hq-sales-orders.v1`, and says what happened and what did
 * not.
 */
final class HqSalesOrderController extends Controller
{
    public const CONTRACT = 'hq-sales-orders.v1';

    public const RECORD = 'sales.orders.record';

    public const CANCEL = 'sales.orders.cancel';

    public const READ = 'sales.view';

    private const AMOUNT = 'regex:/^\d{1,13}(\.\d{1,2})?$/';

    private const KEY = 'regex:/^[A-Za-z0-9._:-]+$/';

    /** POST sales-orders/quote: what the ERP would charge, now. Writes nothing. */
    public function quote(Request $request, int $business, HqOrderPricing $pricing): JsonResponse
    {
        $this->requireAbility($request, self::READ);

        $unknown = array_values(array_diff(array_keys($request->all()), ['store_key', 'destination', 'lines']));
        if ($unknown !== []) {
            return $this->error('unknown_field', 'A quote carries no '.implode(', ', $unknown).'.', 422,
                collect($unknown)->mapWithKeys(fn ($k) => [$k => ['Not taken on a quote.']])->all());
        }

        $data = $request->validate([
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
            'lines.*' => ['required', 'array:sku,quantity'],
            'lines.*.sku' => ['required', 'string', 'max:64'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        try {
            $priced = $pricing->price($business, (string) $data['store_key'], (array) $data['destination'], array_values((array) $data['lines']), Carbon::now());
        } catch (SalesOrderDraftRefused $e) {
            return $this->error($e->errorCode, 'The ERP would not take this order as it stands.', $e->status, $e->errors);
        }

        return $this->ok($business, [
            'store' => ['key' => $priced->store->key],
            'lines' => array_map(fn (array $l) => [
                'sku' => $l['sku'],
                'product_id' => $l['product_id'],
                'quantity' => $l['quantity'],
                'unit_price' => self::decimal($l['unit_price'], 4),
                'line_total' => self::decimal($l['line_total'], 2),
                'price_note' => $l['price_note'],
                'sellable' => true,
                'made_to_order' => (bool) $l['made_to_order'],
            ], $priced->lines),
            'payment_methods' => ['cod'],
            'goods_total' => $priced->goods->toTaka(),
            'delivery_charge' => $priced->delivery->toTaka(),
            'order_discount' => '0.00',
            'agreed_total' => $priced->agreed()->toTaka(),
            'delivery_promise' => $this->promise($priced->store),
            'quoted_at' => $priced->pricedAt->toIso8601String(),
            'effects' => ['written' => false],
        ]);
    }

    /** POST sales-orders: an approved HQ draft becomes an order waiting in the ERP's approval inbox. */
    public function store(Request $request, int $business, ConfirmHqSalesOrderDraft $action): JsonResponse
    {
        $this->requireAbility($request, self::RECORD);

        $unknown = array_values(array_diff(array_keys($request->all()), ['idempotency_key', 'draft_key', 'proposed', 'hq_reference']));
        if ($unknown !== []) {
            return $this->error('unknown_field', 'An order from a draft carries no '.implode(', ', $unknown).'.', 422,
                collect($unknown)->mapWithKeys(fn ($k) => [$k => ['Not taken on an order from a draft.']])->all());
        }

        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:16', 'max:128', self::KEY],
            'draft_key' => ['required', 'string', 'min:16', 'max:128', self::KEY],
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

        if (($mismatch = $this->headerMismatch($request, $data['idempotency_key'])) !== null) {
            return $mismatch;
        }

        try {
            [$order, $replayed] = $action->handle($business, $data, $request->user(), $request->user()->currentAccessToken()?->id, Context::get('correlation_id'));
        } catch (SalesOrderDraftRefused $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->status, $e->errors);
        }

        return $this->ok($business, $this->body($order, $replayed), $replayed ? 200 : 201);
    }

    /** GET sales-orders/{reference}: an order HQ placed, by the customer's reference or the order number. */
    public function show(Request $request, int $business, string $reference): JsonResponse
    {
        $this->requireAbility($request, self::READ);

        $order = HqPlacedOrders::find($business, $reference);

        return $order === null
            ? $this->error('not_found', 'No order HQ placed with that reference in this business.', 404)
            : $this->ok($business, $this->body($order, null));
    }

    /** GET sales-orders/by-key/{key}: the order HQ's key made, for reconciling a request with no answer. */
    public function byKey(Request $request, int $business, string $key): JsonResponse
    {
        $this->requireAbility($request, self::READ);

        $order = HqPlacedOrders::byKey($business, $key);

        return $order === null
            ? $this->error('not_found', 'No order was made with that key in this business.', 404)
            : $this->ok($business, $this->body($order, null));
    }

    /** POST sales-orders/{reference}/cancel: HQ cancels an order it placed, while nothing has left. */
    public function cancel(Request $request, int $business, string $reference, CancelHqSalesOrder $action): JsonResponse
    {
        $this->requireAbility($request, self::CANCEL);

        $unknown = array_values(array_diff(array_keys($request->all()), ['idempotency_key', 'reason', 'hq_reference']));
        if ($unknown !== []) {
            return $this->error('unknown_field', 'A cancellation carries no '.implode(', ', $unknown).'.', 422,
                collect($unknown)->mapWithKeys(fn ($k) => [$k => ['Not taken on a cancellation.']])->all());
        }

        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:16', 'max:128', self::KEY],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'hq_reference' => ['nullable', 'array:proposal_fingerprint,approval_uuid,tool_call_id,conversation_uuid'],
            'hq_reference.proposal_fingerprint' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'hq_reference.approval_uuid' => ['nullable', 'string', 'max:64'],
            'hq_reference.tool_call_id' => ['nullable', 'integer', 'min:1'],
            'hq_reference.conversation_uuid' => ['nullable', 'string', 'max:64'],
        ]);

        if (($mismatch = $this->headerMismatch($request, $data['idempotency_key'])) !== null) {
            return $mismatch;
        }

        try {
            [$order, $replayed] = $action->handle($business, $reference, $data, $request->user(), $request->user()->currentAccessToken()?->id, Context::get('correlation_id'));
        } catch (SalesOrderDraftRefused $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->status, $e->errors);
        }

        return $this->ok($business, $this->body($order, $replayed));
    }

    /** @return array<string, mixed> */
    private function body(SalesOrder $order, ?bool $replayed): array
    {
        $order->loadMissing(['lines.product', 'address', 'approvalRequest', 'store', 'customer']);
        $confirmation = SalesOrderHqConfirmation::query()->where('sales_order_id', $order->id)->with('draft')->first();
        $cancellation = SalesOrderHqCancellation::query()->where('sales_order_id', $order->id)->first();
        $approval = $order->approvalRequest?->status?->value;
        $address = $order->address;

        return array_filter([
            'id' => $order->id,
            'order_number' => $order->order_number,
            'shop_reference' => $order->shop_reference,
            'status' => $order->status->value,
            'approval_status' => $approval,
            'deliverable' => $order->isDeliverable(),
            'channel' => $order->effectiveChannel()->value,
            'store' => ['key' => $order->store?->key],
            'customer' => ['id' => $order->customer_id],
            'recipient' => ['name' => $address?->recipient_name, 'phone' => $address?->recipient_phone],
            'destination' => [
                'district' => $address?->district,
                'thana' => $address?->thana,
                'area' => $address?->area,
                'postcode' => $address?->postcode,
                'address_line' => $address?->address_line,
                'landmark' => $address?->landmark,
            ],
            'lines' => $order->lines->map(fn (SalesOrderLine $l) => [
                'sku' => $l->product?->sku,
                'product_id' => $l->product_id,
                'quantity' => (int) round((float) $l->quantity_ordered),
                'quantity_delivered' => self::decimal((string) $l->quantity_delivered, 4),
                'unit_price' => self::decimal((string) $l->unit_price, 4),
                'line_discount' => self::decimal((string) $l->line_discount, 2),
                'price_note' => $l->price_note,
            ])->values()->all(),
            'payment_method' => $order->payment_method,
            'goods_total' => Money::ofTaka($order->goodsTotal())->toTaka(),
            'order_discount' => $order->orderDiscount()->toTaka(),
            'delivery_charge' => $order->deliveryCharge()->toTaka(),
            'agreed_total' => Money::ofTaka($order->agreedTotal())->toTaka(),
            'delivery_promise' => $order->deliveryPromise(),
            'expected_date' => $order->expected_date?->toDateString(),
            'ordered_at' => $order->ordered_at?->toDateString(),
            'hq' => [
                'draft_key' => $confirmation?->draft?->idempotency_key,
                'idempotency_key' => $confirmation?->idempotency_key,
                'proposal_fingerprint' => $confirmation?->draft?->hq_proposal_fingerprint,
                'approval_uuid' => $confirmation?->hq_reference['approval_uuid'] ?? null,
            ],
            'cancellation' => $cancellation === null ? ['requested' => false] : [
                'requested' => true,
                'idempotency_key' => $cancellation->idempotency_key,
                'reason' => $cancellation->reason,
            ],
            // ⭐ Stated, not implied: what the order did and did not do.
            'effects' => [
                'sales_order_created' => true,
                'approval_requested' => true,
                'approved_by_hq' => false,
                'stock_moved' => false,
                'journal_posted' => false,
                'payment_taken' => false,
                'courier_booked' => false,
            ],
            'next_step' => $order->status->value === 'cancelled'
                ? 'None. The order is cancelled.'
                : 'A person in the ERP approves or rejects it in the approval inbox; goods leave only against an approved order.',
            'replayed' => $replayed,
        ], fn ($value) => $value !== null);
    }

    private function promise(Store $store): ?string
    {
        return $store->delivery_promise_max_hours === null
            ? null
            : Store::promiseSentence((int) $store->delivery_promise_min_hours, (int) $store->delivery_promise_max_hours);
    }

    private function headerMismatch(Request $request, string $key): ?JsonResponse
    {
        $header = $request->header('Idempotency-Key');

        return $header !== null && $header !== $key
            ? $this->error('idempotency_key_mismatch', 'The Idempotency-Key header and the body name different keys.', 422)
            : null;
    }

    /** A decimal as a string with exactly `$places` places, the same on MySQL and SQLite. */
    private static function decimal(string $value, int $places): string
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return ($whole === '' ? '0' : $whole).'.'.str_pad(substr($fraction, 0, $places), $places, '0');
    }

    private function requireAbility(Request $request, string $ability): void
    {
        if (! $request->user()->tokenCan($ability)) {
            abort(response()->json(['error' => ['code' => 'forbidden', 'message' => "Token lacks ability {$ability}."]], 403));
        }
    }

    /** @param array<string, mixed> $data */
    private function ok(int $business, array $data, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => ['business_id' => $business, 'contract' => self::CONTRACT, 'as_of' => now()->toIso8601String()],
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
