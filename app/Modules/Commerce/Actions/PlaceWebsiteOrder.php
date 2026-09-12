<?php

namespace App\Modules\Commerce\Actions;

use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartLine;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Support\Availability;
use App\Modules\Commerce\Support\CartRules;
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
use Illuminate\Support\Collection;
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

        if ($lines->isEmpty()) {
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

        $payment = $this->paymentMethod($input);
        $email = $this->email($input);
        $expected = $this->promisedDate($store, $lines);

        return DB::transaction(function () use ($store, $cart, $lines, $quote, $destination, $payment, $email, $expected) {
            $customer = $this->identify($store, $destination);

            $order = $this->orders->handle(
                customer: $customer,
                lines: $lines->map(fn ($line) => [
                    'product' => $line->product,
                    'quantity' => (string) $line->quantity,
                    // ⛔ NO OVERRIDE, EVER, from a public page. An override is
                    // taken verbatim and marked "Manual override", and a
                    // stranger's browser must never be able to write one.
                    'unit_price' => null,
                ])->all(),
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
                // ⚠ What the SHOP PROMISED, recorded on the document that is
                // the promise. See promisedDate().
                expectedDate: $expected,
                origin: SalesOrderOrigin::website(
                    store: $store,
                    shopReference: ShopOrderReference::mint($store),
                    deliveryCharge: $quote->charge(),
                    paymentMethod: $payment,
                    destination: $destination,
                ),
            );

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
     * The date the shop has already told this customer to expect.
     *
     * ⭐⭐ IT IS NOT A NEW RULE, IT IS NOT LOSING ONE. The product page tells a
     * buyer "made to order, ready in N days" from `products.lead_time_days`
     * (slice 2.1), and until now nothing carried that sentence onto the order -
     * so the one document that IS the promise left `expected_date` empty on
     * exactly the orders that carry a promise, and nobody picking the goods knew
     * what the customer had been told.
     *
     * ⚠ The LONGEST lead time across the basket wins, because this ships as one
     * parcel: an order is ready when its slowest item is. Taking the shortest
     * would promise a date the shop cannot meet.
     *
     * ⚠ Null when nothing in the basket is made to order, or when the products
     * that are carry no lead time. A null promises nothing, which is the whole
     * reason that column is nullable - Q7 (who maintains lead time) is still
     * open, and a shop that has not answered it must not have a date invented
     * for it.
     *
     * @param  Collection<int, CartLine>  $lines
     */
    private function promisedDate(Store $store, $lines): ?string
    {
        $days = $lines
            ->map(fn ($line) => Availability::decide($store, $line->product)->leadTimeDays)
            ->filter()
            ->max();

        return $days === null ? null : now()->addDays((int) $days)->toDateString();
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
     * ⚠ COD is the only method this slice accepts, and it is checked rather
     * than assumed: a form that can post `payment_method` must not be able to
     * post `online` and have the shop record an order as paid-by-gateway that
     * no gateway ever saw. SSLCommerz arrives behind this same order, with its
     * own step and its own proof.
     *
     * @param  array<string, mixed>  $input
     */
    private function paymentMethod(array $input): string
    {
        $method = $input['payment_method'] ?? 'cod';

        if ($method !== 'cod') {
            $this->refuse('payment_method', 'Cash on delivery is the only payment method available right now.');
        }

        return 'cod';
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
