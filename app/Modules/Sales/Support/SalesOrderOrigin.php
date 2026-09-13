<?php

namespace App\Modules\Sales\Support;

use App\Modules\Commerce\Models\Store;
use App\Modules\Sales\Enums\SalesChannel;

/**
 * Everything that follows from an order NOT having been typed in by a colleague.
 *
 * ⭐ One object rather than six more parameters on `CreateSalesOrder::handle()`,
 * which already takes seven. These facts arrive together or not at all - a
 * channel with no store, or a shop reference with no channel, is not a state
 * worth being able to express.
 *
 * ⭐⭐ `priceGroupId` IS THE POINT OF THIS OBJECT, and it is the decision worth
 * reading twice. A WEBSITE IS A PRICE GROUP (ADR-005, D8), and Joy's own
 * decision is that websites are B2C only - all wholesale, dealer and distributor
 * trade stays manual in the ERP. So a website order prices at THE SHOP's group,
 * not at the buyer's own, and a dealer who orders on the website pays the
 * website price.
 *
 * ⚠⚠ THE ALTERNATIVE IS THE DEFECT, NOT THE SAFER OPTION. The basket was priced
 * with no customer at the store's group; if the order then priced at the
 * customer's group, a dealer would be quoted retail on the page and billed
 * dealer on the document - the shop showing one number and recording another,
 * which is exactly what commit 83bfd9e had to fix one level down. Pricing the
 * order the way the page was priced is what keeps those two equal.
 *
 * ⚠ The buyer is still recorded as the customer on the order. Only their
 * PRICING identity is set aside, and only for a channel that has decided to be
 * B2C. Nothing here touches `customers.price_group_id`, so the same person
 * walking into the shop tomorrow is priced as themselves.
 */
final readonly class SalesOrderOrigin
{
    public function __construct(
        public SalesChannel $channel,
        public ?int $storeId = null,
        public ?string $shopReference = null,
        public ?int $priceGroupId = null,
        public ?string $deliveryCharge = null,
        public ?string $paymentMethod = null,
        public ?SalesOrderDestination $destination = null,
        /**
         * ⚠⚠ A SEPARATE FLAG, AND NOT `priceGroupId !== null`, WHICH IS THE
         * BUG THIS FIELD EXISTS TO AVOID. A store may legitimately point at NO
         * price group - slice 1.7 made the column nullable - and its basket is
         * then priced on the ungrouped default tier. Deriving "price as the
         * channel" from the group being set would send exactly that shop down
         * the CUSTOMER path instead, so a dealer buying from a shop with no
         * group would be quoted the default price and billed the dealer price.
         * The channel decides whose identity prices the order; the group is
         * only which group, and null is a real answer.
         */
        public bool $pricesAsChannel = false,
        public string $orderDiscount = '0',
        public ?string $couponCode = null,
        public ?int $promiseMinHours = null,
        public ?int $promiseMaxHours = null,
    ) {}

    /**
     * An order placed by a customer on one of the group's shops.
     *
     * ⚠ The price group is taken from the store rather than passed in, so a
     * caller cannot accidentally price a Bionic order at LaLaDia's group. If the
     * store points at no group the resolver falls through to the default tier,
     * which is the same answer the product page showed - the publish gate would
     * have refused the product otherwise.
     */
    public static function website(
        Store $store,
        string $shopReference,
        ?string $deliveryCharge,
        string $paymentMethod,
        SalesOrderDestination $destination,
        string $orderDiscount = '0',
        ?string $couponCode = null,
    ): self {
        return new self(
            channel: SalesChannel::Website,
            storeId: $store->getKey(),
            shopReference: $shopReference,
            priceGroupId: $store->price_group_id,
            deliveryCharge: $deliveryCharge,
            paymentMethod: $paymentMethod,
            destination: $destination,
            // ⚠ Always, even when the store points at no price group. That is
            // what keeps the order priced the way the page was priced.
            pricesAsChannel: true,
            // Slice 2.5b: a coupon's discount, agreed on the whole order.
            orderDiscount: $orderDiscount,
            couponCode: $couponCode,
            // ⭐ D24: the shop's one delivery window, frozen on the order.
            promiseMinHours: (int) $store->delivery_promise_min_hours,
            promiseMaxHours: (int) $store->delivery_promise_max_hours,
        );
    }
}
