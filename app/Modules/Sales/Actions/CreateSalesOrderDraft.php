<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Commerce\Enums\StoreStatus;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Support\Availability;
use App\Modules\Commerce\Support\CartRules;
use App\Modules\Commerce\Support\DeliveryQuote;
use App\Modules\Crm\Support\PhoneIdentity;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Exceptions\SalesOrderDraftRefused;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesOrderDraft;
use App\Modules\Sales\Support\SalesOrderDestination;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writes a draft sales order for HQ, and nothing else (HQ docs/83 C.3, docs/79).
 *
 * ⚠⚠ NOT `CreateSalesOrder`, ON PURPOSE. That action takes the next order number, opens an approval
 * request, notifies the approvers, and (through the website) may create a customer. A draft does none
 * of those: it is its own immutable record (`SalesOrderDraft`), and turning it into an order is a
 * later capability with its own gate. Nothing here reaches stock, a journal, a coupon, a customer
 * row, a notification or a queue.
 *
 * ⚠⚠ THE ERP ASKS ITS OWN QUESTIONS AND NEVER TRUSTS THE CALLER'S ANSWERS. The store must be live;
 * every product is re-read by SKU and asked the website's questions (`CartRules`: published, priced,
 * sellable); every price is the one engine at this store and this quantity; the delivery charge is
 * `DeliveryQuote`; the destination is `SalesOrderDestination`; the customer is the one the website's
 * `identify` would pick. Then every figure HQ proposed must equal the ERP's to the paisa, or the
 * request is REFUSED with each field named. Nothing is ever rewritten to make it match: a proposal a
 * person approved at one price is not quietly written at another.
 *
 * ⭐ IDEMPOTENT ON THE CALLER'S KEY. The same key with the same request answers the stored draft
 * (without pricing it again: a draft records what was agreed then); the same key with a different
 * request is refused, and no second draft is ever written.
 */
final class CreateSalesOrderDraft
{
    /** docs/79 C.7: a Bangladesh mobile number, as HQ's PhoneCanonical and the ERP's PhoneIdentity write it. */
    private const MOBILE = '/^01[3-9]\d{8}$/';

    public function __construct(private readonly ResolveSellingPrice $prices) {}

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
        $refusals = [];
        $mismatches = [];

        $store = Store::query()->where('business_id', $businessId)->where('key', (string) $input['store_key'])->first();
        if ($store === null) {
            throw SalesOrderDraftRefused::refused(['store_key' => ["No store with key {$input['store_key']} in this business."]]);
        }
        if ($store->status !== StoreStatus::Live) {
            // ⚠ A store that is not open does not take orders, drafts included.
            throw SalesOrderDraftRefused::refused(['store_key' => ["The store {$store->key} is not live, so it takes no orders."]]);
        }

        try {
            $destination = SalesOrderDestination::fromInput((array) $input['destination']);
        } catch (ValidationException $e) {
            throw SalesOrderDraftRefused::refused(collect($e->errors())->mapWithKeys(fn ($messages, $field) => ["destination.{$field}" => array_values($messages)])->all());
        }

        $phone = PhoneIdentity::normalize($destination->recipientPhone);
        if ($phone === null || preg_match(self::MOBILE, $phone) !== 1) {
            $refusals['destination.phone'][] = 'That is not a Bangladesh mobile number (eleven digits starting 013 to 019).';
        }

        $lines = [];
        $goods = Money::zero();
        $seen = [];
        foreach (array_values((array) $input['lines']) as $i => $line) {
            $sku = (string) $line['sku'];
            if (isset($seen[$sku])) {
                $refusals["lines.{$i}.sku"][] = "SKU {$sku} appears twice; name each product once with its whole quantity.";

                continue;
            }
            $seen[$sku] = true;

            /** @var Product|null $product */
            $product = Product::query()->where('business_id', $businessId)->where('sku', $sku)->first();
            if ($product === null) {
                $refusals["lines.{$i}.sku"][] = "No product with SKU {$sku} in this business.";

                continue;
            }
            if (! $product->type->isStocked()) {
                $refusals["lines.{$i}.sku"][] = "{$product->name} is a service and cannot be ordered.";

                continue;
            }
            if (($refusal = CartRules::refusalFor($store, $product)) !== null) {
                $refusals["lines.{$i}.sku"][] = "{$product->name}: {$refusal}";

                continue;
            }

            $quantity = (int) $line['quantity'];
            $resolved = $this->prices->find(product: $product, customer: null, quantity: (string) $quantity, on: $now, priceGroupId: $store->price_group_id);
            if ($resolved === null) {
                $refusals["lines.{$i}.sku"][] = "{$product->name} has no price in force at this store for {$quantity}.";

                continue;
            }

            if (! self::sameDecimal((string) $line['unit_price'], (string) $resolved->unitPrice)) {
                $mismatches["lines.{$i}.unit_price"][] = "Proposed {$line['unit_price']}, the ERP's price is ".self::plain((string) $resolved->unitPrice).'.';
            }

            $lineTotal = Money::ofProduct((string) $quantity, (string) $resolved->unitPrice);
            $goods = $goods->plus($lineTotal);
            $lines[] = [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'quantity' => $quantity,
                'unit_price' => (string) $resolved->unitPrice,
                'line_total' => $lineTotal->toTaka(),
                'price_note' => mb_substr($resolved->explain(), 0, 255),
                'made_to_order' => Availability::decide($store, $product)->madeToOrder,
            ];
        }

        $quote = DeliveryQuote::for($store, $destination->district);
        $charge = $quote->cannotQuote() ? null : $quote->charge();
        if ($charge === null) {
            $refusals['destination.district'][] = "The store cannot work out a delivery charge for {$destination->district}.";
        }

        if ($refusals !== []) {
            throw SalesOrderDraftRefused::refused($refusals);
        }

        $delivery = Money::ofTaka((string) $charge);
        $agreed = $goods->plus($delivery);
        foreach (['goods_total' => $goods, 'delivery_charge' => $delivery, 'agreed_total' => $agreed] as $field => $erp) {
            $proposed = (string) $input['proposed'][$field];
            if (Money::ofTaka($proposed)->minor !== $erp->minor) {
                $mismatches["proposed.{$field}"][] = "Proposed {$proposed}, the ERP's figure is {$erp->toTaka()}.";
            }
        }
        if ($mismatches !== []) {
            throw SalesOrderDraftRefused::quoteMismatch($mismatches);
        }

        // The customer the website would pick for this phone: active, not the seeded walk-in row, the
        // first by id. ⛔ Nobody is created: a new buyer stays "new" until an order exists.
        $customer = Customer::query()
            ->where('business_id', $businessId)
            ->where('phone_normalized', $phone)
            ->where('is_active', true)
            ->where('is_system', false)
            ->orderBy('id')
            ->first();

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
                    'recipient_phone_normalized' => (string) $phone,
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

    /** Two decimals equal to four places, compared as integers (no floating point, no bcmath). */
    private static function sameDecimal(string $a, string $b): bool
    {
        return self::scaled($a) === self::scaled($b);
    }

    private static function scaled(string $decimal): string
    {
        [$whole, $fraction] = array_pad(explode('.', trim($decimal), 2), 2, '');

        return ltrim($whole, '0').'.'.str_pad(substr($fraction, 0, 4), 4, '0');
    }

    /** "850.0000" as "850.00", the way a person reads a price. */
    private static function plain(string $decimal): string
    {
        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');
        $fraction = rtrim($fraction, '0');

        return $whole.'.'.str_pad($fraction, 2, '0');
    }
}
