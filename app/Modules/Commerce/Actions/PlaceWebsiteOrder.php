<?php

namespace App\Modules\Commerce\Actions;

use App\Modules\Accounting\Support\Money;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StoreCoupon;
use App\Modules\Commerce\Support\CartRules;
use App\Modules\Commerce\Support\CataloguePublishing;
use App\Modules\Commerce\Support\ComboPricing;
use App\Modules\Commerce\Support\CouponPricing;
use App\Modules\Commerce\Support\DeliveryQuote;
use App\Modules\Commerce\Support\ShopOrderReference;
use App\Modules\Crm\Support\PhoneIdentity;
use App\Modules\Foundation\Support\SystemActor;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Support\SalesOrderDestination;
use App\Modules\Sales\Support\SalesOrderOrigin;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A basket becomes a real ERP sales order. Website Phase 2, slice 2.4.
 *
 * ⚠⚠ NOTHING HERE MOVES STOCK AND NOTHING POSTS A JOURNAL. A website sale under
 * the status-based policy records a COMMITMENT, which is precisely what a sales
 * order already is, so the rule ADR-004 insists on is satisfied by construction
 * rather than by care: there is no code path from here to the movement ledger or
 * to Accounting. `inventory:verify-ledger` would catch a lie; there is no lie to
 * catch.
 *
 * ⚠⚠ AND IT DOES NOT TAKE A PAYMENT. This is COD: the money arrives when the
 * rider hands over the parcel, and recording it is `RecordCustomerReceipt`
 * against a real money account - a separate event, later, by somebody. An order
 * is not a receipt, and LaLaDia fired a full-value Purchase for an order nobody
 * had paid for because those two were allowed to blur.
 *
 * ⭐ THE ORDER ARRIVES PENDING APPROVAL, and that is the feature rather than an
 * oversight. `SystemActor` holds no `sales.orders.approve`, so a website order
 * sits in the approval inbox and the holders of that permission are notified -
 * which is LaLaDia's B3 "confirm before dispatch" gate, free, on a shop where
 * 66% of COD orders were cancelled. Goods cannot leave against an unapproved
 * order (SalesOrder::isDeliverable), so the confirmation call is enforced by the
 * document and not by a habit.
 */
class PlaceWebsiteOrder
{
    public const CASH_ON_DELIVERY = 'cod';

    /** Paying online through the shop's gateway (SSLCommerz slice P2c). */
    public const ONLINE = 'sslcommerz';

    public function __construct(
        private readonly CreateSalesOrder $orders,
    ) {}

    /**
     * @param  array<string, mixed>  $input  the checkout form, as typed
     *
     * @throws ValidationException
     */
    public function handle(Store $store, Cart $cart, array $input): SalesOrder
    {
        // ⭐⭐ IDEMPOTENT ON THE BASKET, FIRST THING. A double-tapped button on a
        // poor connection is not an edge case, it is Tuesday - and the second
        // press must not write a twin order that somebody then has to spot and
        // cancel. The basket already knows which order it became.
        if ($cart->sales_order_id !== null) {
            /** @var SalesOrder|null $existing */
            $existing = SalesOrder::query()->find($cart->sales_order_id);

            if ($existing !== null) {
                return $existing;
            }
        }

        $lines = $cart->lines()->with('product')->orderBy('id')->get();
        $comboLines = $cart->comboLines()->with('combo.items.product')->orderBy('id')->get();

        if ($lines->isEmpty() && $comboLines->isEmpty()) {
            $this->refuse('lines', 'Your basket is empty.');
        }

        // ⚠ Re-asked here, at the last moment, and not trusted from the page
        // the customer was looking at. A price can expire and a product can be
        // taken off sale between loading the checkout and pressing the button,
        // and the answer must be a sentence about that product rather than a
        // server error about nothing.
        foreach ($lines as $line) {
            $product = $line->product;

            if ($product === null) {
                $this->refuse('lines', 'Something in your basket no longer exists. Please remove it and try again.');
            }

            if ($refusal = CartRules::refusalFor($store, $product)) {
                $this->refuse('lines', $product->name.': '.$refusal);
            }
        }

        // ⭐ Bundles, slice 2.5a: re-asked at the last moment for the same
        // reason, at the quantity in the basket. A part taken off sale, or a
        // price-list change that has pushed a bundle above its parts, is a
        // sentence here rather than an order at a figure nobody agreed.
        $bundles = [];

        foreach ($comboLines as $line) {
            $combo = $line->combo;

            if ($combo === null) {
                $this->refuse('lines', 'A bundle in your basket no longer exists. Please remove it and try again.');
            }

            if ($refusal = CartRules::refusalForCombo($store, $combo, $line->quantity)) {
                $this->refuse('lines', $combo->title.': '.$refusal);
            }

            $bundles[] = [$combo, ComboPricing::quote($store, $combo, $line->quantity)];
        }

        $quote = DeliveryQuote::for($store, $cart->delivery_district);

        if ($quote->district === null) {
            $this->refuse('district', 'Please choose the district we are delivering to.');
        }

        // ⛔ A shop that cannot quote a rate does not take the order. Slice 2.3
        // refuses to deliver free for a district nobody priced, and charging
        // zero at the last step would undo that decision at the one moment it
        // costs real money. This is the shop's setup being incomplete, so the
        // sentence says so rather than blaming the customer's address.
        if ($quote->cannotQuote()) {
            $this->refuse('district', 'We cannot work out a delivery charge for '.$quote->district.' yet. Please contact us and we will take your order by phone.');
        }

        // ⚠ Built BEFORE the transaction opens: it validates the district and
        // thana against the geography and derives the postcode, and a refusal
        // belongs to the form rather than to a rolled-back write.
        $destination = SalesOrderDestination::fromInput($input);

        $payment = $this->paymentMethod($store, $input);
        $email = $this->email($input);

        // ⭐⭐ WHAT THE BASKET SHOWED, computed by the classes the basket used:
        // each product at the price list at its own quantity, each bundle through
        // `ComboPricing`. The order is checked against it below.
        $quoted = '0';
        $couponProducts = [];
        $couponCombos = [];

        foreach ($lines as $line) {
            $price = CataloguePublishing::priceFor($store, $line->product, null, (string) $line->quantity);
            $lineTotal = bcmul((string) $price, (string) $line->quantity, 2);
            $quoted = bcadd($quoted, $lineTotal, 2);
            $couponProducts[] = ['product_id' => (int) $line->product_id, 'total' => Money::ofTaka($lineTotal)];
        }

        foreach ($bundles as [$combo, $bundle]) {
            $quoted = bcadd($quoted, $bundle->total->toTaka(), 2);
            $couponCombos[] = ['combo_id' => (int) $combo->id, 'total' => $bundle->total];
        }

        $orderLines = $lines->map(fn ($line) => [
            'product' => $line->product,
            'quantity' => (string) $line->quantity,
            // ⛔ NO OVERRIDE, EVER, from a public page. An override is taken
            // verbatim and marked "Manual override", and a stranger's browser
            // must never be able to write one.
            'unit_price' => null,
        ])->all();

        // ⭐⭐ A BUNDLE EXPLODES INTO ITS PARTS (D25). Each part is a real line
        // priced by the engine like any other, so stock leaves against the jar
        // and cost attaches to the jar; the bundle's saving travels as that
        // line's agreed discount (Joy, 13 September) and is billed from there.
        // ⛔ Still no unit price override: the discount is checked against the
        // engine's own price inside `CreateSalesOrder`.
        foreach ($bundles as [$combo, $bundle]) {
            foreach ($bundle->parts as $part) {
                $orderLines[] = [
                    'product' => $part->product,
                    'quantity' => (string) $part->quantity,
                    'unit_price' => null,
                    'discount' => $part->discount->toTaka(),
                    // ⚠ A label that reads on its own, because every screen shows
                    // it as written: a gift line's is "Free with CODE", and a
                    // prefix added by the screen would turn that into nonsense.
                    'bundle_label' => 'Part of '.$combo->title,
                ];
            }
        }

        return DB::transaction(function () use ($store, $cart, $orderLines, $quoted, $quote, $destination, $payment, $email, $couponProducts, $couponCombos) {
            // ⚠⚠ MFC-007 (audit 14 Oct 2026): the idempotency check above reads
            // the caller's copy of the basket, unlocked, so two presses that both
            // arrive before the first commits both wrote an order (the shopper
            // has no login, so the duplicate-submission middleware never sees
            // them). The basket row is locked here, FIRST, and asked again: the
            // second press waits for the first and then finds its order.
            $current = Cart::query()->whereKey($cart->id)->lockForUpdate()->first();

            if ($current?->sales_order_id !== null && ($existing = SalesOrder::query()->find($current->sales_order_id)) !== null) {
                return $existing;
            }

            // ⚠⚠ MFC-006 (audit 14 Oct 2026): the coupon row is locked BEFORE
            // anything else is read. On MySQL (REPEATABLE READ) the first plain
            // read fixes what the transaction can see; identify() used to read
            // the customers first, so a second checkout waited on this lock and
            // then counted the code's uses from a picture taken before the first
            // order existed, and a one-use code was spent twice. Locking reads
            // come first now, so the counting below sees the first order.
            if ($cart->coupon_code !== null) {
                StoreCoupon::query()
                    ->where('store_id', $store->id)
                    ->where('code', StoreCoupon::normaliseCode($cart->coupon_code))
                    ->lockForUpdate()
                    ->first();
            }

            $customer = $this->identify($store, $destination);

            // ⭐⭐ THE COUPON, slice 2.5b, decided HERE, inside the transaction,
            // with the buyer known and the coupon row locked. Locked because a
            // code with one use left must not be spent twice by two customers
            // pressing the button in the same second; with the buyer known
            // because a per-customer limit cannot be checked on a basket that
            // has no phone number yet.
            // ⛔ A code that no longer applies REFUSES the order with its reason.
            // Quietly dropping it would charge the customer more than the basket
            // they just read.
            $deliveryCharge = $quote->charge();
            $orderDiscount = '0';
            $couponCode = null;

            if ($cart->coupon_code !== null) {
                // The coupon row was locked at the top of this transaction.
                $coupon = CouponPricing::quote($store, $cart->coupon_code, $couponProducts, $couponCombos, $customer);

                if (! $coupon->applies()) {
                    $this->refuse('code', "Your code {$coupon->code} cannot be used: {$coupon->refusal} Remove it from your basket to order without it.");
                }

                // ⭐ A gift is a real line, priced by the engine like any other so
                // its stock and cost are real, and its value is part of the
                // coupon's order discount (see `CouponPricing`).
                if ($coupon->gift !== null) {
                    $orderLines[] = [
                        'product' => $coupon->gift['product'],
                        'quantity' => (string) $coupon->gift['quantity'],
                        'unit_price' => null,
                        'bundle_label' => 'Free with '.$coupon->code,
                    ];
                    $quoted = bcadd($quoted, $coupon->gift['gross']->toTaka(), 2);
                }

                if ($coupon->freeDelivery && $deliveryCharge !== null) {
                    $deliveryCharge = '0.00';
                }

                $orderDiscount = $coupon->orderDiscount()->toTaka();
                $couponCode = $coupon->code;
            }

            $order = $this->orders->handle(
                customer: $customer,
                lines: $orderLines,
                // ⚠ The system actor, which cannot authenticate (is_active
                // false, random discarded password, no role, no business). A
                // website order was typed by nobody in this company, and
                // writing a colleague's id there would put a name on a decision
                // they never made.
                createdBy: SystemActor::user(),
                // ⚠ Null, deliberately. A sales order's warehouse is only a
                // default for the delivery screen; which warehouse fulfils a
                // web order is decided when somebody picks the goods, and
                // committing one here would be a guess made by a shopper.
                warehouse: null,
                // ⚠ No date yet, deliberately. D24: the shop's promise counts
                // from CONFIRMATION, so the date is written by ApproveSalesOrder
                // when somebody confirms the order; the window itself is frozen
                // on the order through the origin below.
                expectedDate: null,
                origin: SalesOrderOrigin::website(
                    store: $store,
                    shopReference: ShopOrderReference::mint($store),
                    deliveryCharge: $deliveryCharge,
                    paymentMethod: $payment,
                    destination: $destination,
                    orderDiscount: $orderDiscount,
                    couponCode: $couponCode,
                    // ⭐ SSLCommerz slice P2c: an order to be paid online waits
                    // for its payment before anybody is asked to confirm it.
                    awaitingPayment: $payment === self::ONLINE,
                ),
            );

            // ⛔⛔ THE ORDER MUST RECORD WHAT THE BASKET QUOTED, TO THE PAISA, or
            // it is not written. The engine priced every line again inside
            // `CreateSalesOrder`; if a price rule changed in the moment between
            // the two (a rule starting at midnight, an edit in the ERP), the
            // customer would be billed a figure they never saw. Refusing rolls
            // the whole order back, customer record included, and sends them to
            // a basket that now shows the new figure.
            if ($order->goodsTotal() !== $quoted) {
                $this->refuse('lines', 'A price changed while you were ordering. Please check your basket and place the order again.');
            }

            // ⚠ The contact AS TYPED stays on the basket as well as becoming a
            // customer. The cart table exists so that a basket which never
            // becomes an order is still recoverable, and a converted one is how
            // recovery tells the two apart.
            $cart->update([
                'contact_name' => $destination->recipientName,
                'contact_phone' => $destination->recipientPhone,
                'contact_email' => $email,
            ]);
            $cart->markConverted($order);

            return $order;
        });
    }

    /**
     * Who this is, as far as the ERP is concerned.
     *
     * ⚠⚠ MATCHED ON THE NORMALISED PHONE, WHICH IS MATCHING AND NOT UNIQUENESS.
     * `customers.phone_normalized` carries no unique index and must not: a
     * household shares a number, and C-CRM-12/13 say so in capitals. So two
     * people in one family ordering from one phone become one customer record -
     * which is how every shop on earth works - and the person actually receiving
     * each parcel is kept verbatim on that order's own address row, so nobody is
     * lost.
     *
     * ⚠ AN EXISTING CUSTOMER IS NEVER REWRITTEN. Not their name, not their
     * address, not their price group. A stranger typing a name into a public
     * form must not be able to edit a dealer's record, and Joy's standing rule
     * from the supplier decision is that we do not silently correct somebody's
     * data.
     *
     * ⭐ A NEW ONE IS CREATED WITH NO PRICE GROUP, which means RETAIL (D-PRC-2).
     * Stamping the shop's own group onto the person would change what they are
     * charged AT THE COUNTER, which is not a decision a checkout form is allowed
     * to make. The order is priced as the shop; the buyer is priced as themselves
     * everywhere else.
     */
    private function identify(Store $store, SalesOrderDestination $destination): Customer
    {
        $key = PhoneIdentity::normalize($destination->recipientPhone);

        if ($key !== null) {
            /** @var Customer|null $existing */
            $existing = Customer::query()
                ->where('business_id', $store->business_id)
                ->where('phone_normalized', $key)
                ->where('is_active', true)
                // ⛔ Never the seeded walk-in row. Every counter sale in the
                // business's history points at it, and attaching a named web
                // buyer to it would merge one shopper with all of them.
                ->where('is_system', false)
                ->orderBy('id')
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return Customer::create([
            'business_id' => $store->business_id,
            'name' => $destination->recipientName,
            'type' => CustomerType::Retail,
            'phone' => $destination->recipientPhone,
            // ⚠ The address as one line, for a screen that has only a customer
            // in front of it. The order's own address row is the routing truth;
            // this is a convenience copy and must never be read for a parcel.
            'address' => $destination->oneLineAddress(),
            'price_group_id' => null,
            'is_active' => true,
        ]);
    }

    /**
     * Cash on delivery, or online through SSLCommerz when this shop can take it
     * right now (Joy, 13 September: those two, nothing else).
     *
     * ⚠ Checked rather than assumed: a form that can post `payment_method` must
     * not be able to choose a method the shop is not offering. Choosing online
     * records only the CHOICE; nothing is paid until the gateway proves it
     * (ConfirmGatewayPayment), and the order waits for that proof.
     *
     * @param  array<string, mixed>  $input
     */
    private function paymentMethod(Store $store, array $input): string
    {
        $method = $input['payment_method'] ?? self::CASH_ON_DELIVERY;

        if ($method === self::ONLINE) {
            if (! $store->takesOnlinePayment()) {
                $this->refuse('payment_method', 'Paying online is not available right now. Choose cash on delivery.');
            }

            return self::ONLINE;
        }

        if ($method !== self::CASH_ON_DELIVERY) {
            $this->refuse('payment_method', 'Choose cash on delivery or paying online.');
        }

        return self::CASH_ON_DELIVERY;
    }

    /** @param array<string, mixed> $input */
    private function email(array $input): ?string
    {
        $email = $input['email'] ?? null;

        if (! is_string($email) || trim($email) === '') {
            return null;
        }

        return trim($email);
    }

    private function refuse(string $field, string $reason): never
    {
        throw ValidationException::withMessages([$field => $reason]);
    }
}
