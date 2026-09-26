<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Sales\Exceptions\SalesOrderDraftRefused;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesOrderDraft;
use App\Modules\Sales\Support\HqOrderPricing;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writes a draft sales order for HQ, and nothing else (HQ docs/83 C.3, docs/79).
 *
 * ⚠⚠ NOT `CreateSalesOrder`, ON PURPOSE. That action takes the next order number, opens an approval
 * request, notifies the approvers, and (through the website) may create a customer. A draft does none
 * of those: it is its own immutable record (`SalesOrderDraft`). Turning it into an order is
 * `ConfirmHqSalesOrderDraft` (HQ docs/88 A point 3), a separate request behind its own ability, whose
 * order waits in the ERP's approval inbox. Nothing here reaches stock, a journal, a coupon, a customer
 * row, a notification or a queue.
 *
 * ⚠⚠ THE ERP ASKS ITS OWN QUESTIONS AND NEVER TRUSTS THE CALLER'S ANSWERS (`HqOrderPricing`: the store
 * live, the website's questions per SKU, the one engine per price, `DeliveryQuote`,
 * `SalesOrderDestination`); the customer is the one the website's `identify` would pick. Then every
 * figure HQ proposed must equal the ERP's to the paisa, or the request is REFUSED with each field
 * named. Nothing is ever rewritten to make it match: a proposal a person approved at one price is not
 * quietly written at another.
 *
 * ⭐ IDEMPOTENT ON THE CALLER'S KEY. The same key with the same request answers the stored draft
 * (without pricing it again: a draft records what was agreed then); the same key with a different
 * request is refused, and no second draft is ever written.
 */
final class CreateSalesOrderDraft
{
    public function __construct(private readonly HqOrderPricing $pricing) {}

    /**
     * @param  array<string, mixed>  $input  the validated request
     * @return array{0: SalesOrderDraft, 1: bool} the draft and whether it was replayed
     *
     * @throws SalesOrderDraftRefused
     */
    public function handle(int $businessId, array $input, User $by, ?int $tokenId = null, ?string $correlationId = null): array
    {
        $key = (string) $input['idempotency_key'];
        $digest = self::digest($input);

        if (($replayed = $this->replay($businessId, $key, $digest)) !== null) {
            return [$replayed, true];
        }

        $now = Carbon::now();
        $asked = array_values((array) $input['lines']);
        $priced = $this->pricing->price($businessId, (string) $input['store_key'], (array) $input['destination'], $asked, $now);

        $mismatches = [];
        foreach ($priced->lines as $line) {
            $proposed = (string) $asked[$line['index']]['unit_price'];
            if (! HqOrderPricing::sameDecimal($proposed, $line['unit_price'])) {
                $mismatches["lines.{$line['index']}.unit_price"][] = "Proposed {$proposed}, the ERP's price is ".HqOrderPricing::plain($line['unit_price']).'.';
            }
        }

        $goods = $priced->goods;
        $delivery = $priced->delivery;
        $agreed = $priced->agreed();
        foreach (['goods_total' => $goods, 'delivery_charge' => $delivery, 'agreed_total' => $agreed] as $field => $erp) {
            $proposed = (string) $input['proposed'][$field];
            if (Money::ofTaka($proposed)->minor !== $erp->minor) {
                $mismatches["proposed.{$field}"][] = "Proposed {$proposed}, the ERP's figure is {$erp->toTaka()}.";
            }
        }
        if ($mismatches !== []) {
            throw SalesOrderDraftRefused::quoteMismatch($mismatches);
        }

        $store = $priced->store;
        $destination = $priced->destination;
        $phone = $priced->phone;

        // The customer the website would pick for this phone: active, not the seeded walk-in row, the
        // first by id. ⛔ Nobody is created: a new buyer stays "new" until an order exists.
        $customer = Customer::query()
            ->where('business_id', $businessId)
            ->where('phone_normalized', $phone)
            ->where('is_active', true)
            ->where('is_system', false)
            ->orderBy('id')
            ->first();

        $lines = array_map(fn (array $line) => [
            'product_id' => $line['product_id'],
            'sku' => $line['sku'],
            'quantity' => $line['quantity'],
            'unit_price' => $line['unit_price'],
            'line_total' => $line['line_total'],
            'price_note' => $line['price_note'],
            'made_to_order' => $line['made_to_order'],
        ], $priced->lines);

        try {
            $draft = DB::transaction(function () use ($businessId, $store, $key, $digest, $input, $customer, $destination, $phone, $goods, $delivery, $agreed, $now, $correlationId, $tokenId, $by, $lines) {
                $draft = SalesOrderDraft::create([
                    'business_id' => $businessId,
                    'store_id' => $store->id,
                    'idempotency_key' => $key,
                    'payload_digest' => $digest,
                    'hq_proposal_fingerprint' => (string) $input['hq_reference']['proposal_fingerprint'],
                    'hq_reference' => $input['hq_reference'],
                    'customer_id' => $customer?->id,
                    'customer_resolution' => $customer === null ? 'new' : 'existing',
                    'recipient_name' => $destination->recipientName,
                    'recipient_phone' => $destination->recipientPhone,
                    'recipient_phone_normalized' => $phone,
                    'district' => $destination->district,
                    'thana' => $destination->thana,
                    'area' => $destination->area,
                    'postcode' => $destination->postcode,
                    'address_line' => $destination->addressLine,
                    'landmark' => $destination->landmark,
                    'payment_method' => (string) $input['payment_method'],
                    'goods_total' => $goods->toTaka(),
                    'delivery_charge' => $delivery->toTaka(),
                    'order_discount' => '0.00',
                    'agreed_total' => $agreed->toTaka(),
                    'priced_at' => $now,
                    'correlation_id' => $correlationId,
                    'hq_token_id' => $tokenId,
                    'created_by' => $by->id,
                ]);
                foreach ($lines as $line) {
                    $draft->lines()->create(['business_id' => $businessId] + $line);
                }

                return $draft;
            });
        } catch (UniqueConstraintViolationException) {
            // Two requests with one key in the same moment: the other one won. Answer as a replay.
            if (($replayed = $this->replay($businessId, $key, $digest)) !== null) {
                return [$replayed, true];
            }
            throw SalesOrderDraftRefused::keyReused();
        }

        return [$draft->fresh(['lines', 'store']), false];
    }

    /**
     * The stored draft for this key, or null when there is none; refused when the key was used for a
     * different request.
     *
     * @throws SalesOrderDraftRefused
     */
    private function replay(int $businessId, string $key, string $digest): ?SalesOrderDraft
    {
        $existing = SalesOrderDraft::query()->where('business_id', $businessId)->where('idempotency_key', $key)->with(['lines', 'store'])->first();
        if ($existing === null) {
            return null;
        }
        if (! hash_equals($existing->payload_digest, $digest)) {
            throw SalesOrderDraftRefused::keyReused();
        }

        return $existing;
    }

    /**
     * What exactly was asked, as one digest: every key sorted at every depth, so the same request in
     * another key order is the same request.
     *
     * @param  array<string, mixed>  $input
     */
    public static function digest(array $input): string
    {
        $sort = function (array $data) use (&$sort): array {
            foreach ($data as $k => $v) {
                if (is_array($v)) {
                    $data[$k] = $sort($v);
                }
            }
            if (! array_is_list($data)) {
                ksort($data);
            }

            return $data;
        };

        return hash('sha256', (string) json_encode($sort($input), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }
}
