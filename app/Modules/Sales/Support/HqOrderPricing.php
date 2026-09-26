<?php

namespace App\Modules\Sales\Support;

use App\Modules\Accounting\Support\Money;
use App\Modules\Commerce\Enums\StoreStatus;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Support\Availability;
use App\Modules\Commerce\Support\CartRules;
use App\Modules\Commerce\Support\DeliveryQuote;
use App\Modules\Crm\Support\PhoneIdentity;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Actions\ResolveSellingPrice;
use App\Modules\Sales\Exceptions\SalesOrderDraftRefused;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The ERP's own questions about an order HQ proposes, asked in one place (HQ docs/79 C.1 to C.12).
 *
 * ⭐ ONE ANSWER FOR THREE MOMENTS. A quote (before anyone approves), a draft (after a person approved on
 * the HQ side) and the order made from that draft (HQ docs/88 A point 3) must all be priced the same
 * way, or a draft could be written at one price and turned into an order at another. They all ask
 * here: the store is this business's and LIVE; the destination is `SalesOrderDestination`; the phone is
 * a Bangladesh mobile; each SKU is this business's, stocked, and what the website would sell
 * (`CartRules`: published, priced, sellable); each price is the one engine at the store's price group
 * and at that quantity; the delivery charge is `DeliveryQuote`.
 *
 * ⚠ It REFUSES, it never repairs. A request the ERP will not take comes back as `refused` with every
 * sentence at once; the caller then compares HQ's figures against the answer and refuses a mismatch.
 * Nothing here writes anything.
 */
final class HqOrderPricing
{
    /** docs/79 C.7: a Bangladesh mobile number, as HQ's PhoneCanonical and the ERP's PhoneIdentity write it. */
    private const MOBILE = '/^01[3-9]\d{8}$/';

    public function __construct(private readonly ResolveSellingPrice $prices) {}

    /**
     * @param  array<string, mixed>  $destination  name, phone, district, thana, area, address_line, landmark
     * @param  list<array<string, mixed>>  $lines  each with sku and quantity (a whole number)
     *
     * @throws SalesOrderDraftRefused
     */
    public function price(int $businessId, string $storeKey, array $destination, array $lines, Carbon $now): PricedHqOrder
    {
        $refusals = [];

        $store = Store::query()->where('business_id', $businessId)->where('key', $storeKey)->first();
        if ($store === null) {
            throw SalesOrderDraftRefused::refused(['store_key' => ["No store with key {$storeKey} in this business."]]);
        }
        if ($store->status !== StoreStatus::Live) {
            // ⚠ A store that is not open does not take orders, drafts included.
            throw SalesOrderDraftRefused::refused(['store_key' => ["The store {$store->key} is not live, so it takes no orders."]]);
        }

        try {
            $place = SalesOrderDestination::fromInput($destination);
        } catch (ValidationException $e) {
            throw SalesOrderDraftRefused::refused(collect($e->errors())->mapWithKeys(fn ($messages, $field) => ["destination.{$field}" => array_values($messages)])->all());
        }

        $phone = PhoneIdentity::normalize($place->recipientPhone);
        if ($phone === null || preg_match(self::MOBILE, $phone) !== 1) {
            $refusals['destination.phone'][] = 'That is not a Bangladesh mobile number (eleven digits starting 013 to 019).';
        }

        $priced = [];
        $goods = Money::zero();
        $seen = [];
        foreach (array_values($lines) as $i => $line) {
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

            $lineTotal = Money::ofProduct((string) $quantity, (string) $resolved->unitPrice);
            $goods = $goods->plus($lineTotal);
            $priced[] = [
                'index' => $i,
                'product' => $product,
                'product_id' => $product->id,
                'sku' => $product->sku,
                'quantity' => $quantity,
                'unit_price' => (string) $resolved->unitPrice,
                'line_total' => $lineTotal->toTaka(),
                'price_note' => mb_substr($resolved->explain(), 0, 255),
                'made_to_order' => Availability::decide($store, $product)->madeToOrder,
            ];
        }

        $quote = DeliveryQuote::for($store, $place->district);
        $charge = $quote->cannotQuote() ? null : $quote->charge();
        if ($charge === null) {
            $refusals['destination.district'][] = "The store cannot work out a delivery charge for {$place->district}.";
        }

        if ($refusals !== []) {
            throw SalesOrderDraftRefused::refused($refusals);
        }

        return new PricedHqOrder(
            store: $store,
            destination: $place,
            phone: (string) $phone,
            lines: $priced,
            goods: $goods,
            delivery: Money::ofTaka((string) $charge),
            pricedAt: $now,
        );
    }

    /** Two decimals equal to four places, compared as integers (no floating point, no bcmath). */
    public static function sameDecimal(string $a, string $b): bool
    {
        return self::scaled($a) === self::scaled($b);
    }

    /** "850.0000" as "850.00", the way a person reads a price. */
    public static function plain(string $decimal): string
    {
        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');
        $fraction = rtrim($fraction, '0');

        return $whole.'.'.str_pad($fraction, 2, '0');
    }

    private static function scaled(string $decimal): string
    {
        [$whole, $fraction] = array_pad(explode('.', trim($decimal), 2), 2, '');

        return ltrim($whole, '0').'.'.str_pad(substr($fraction, 0, 4), 4, '0');
    }
}
