<?php

namespace App\Modules\Commerce\Support;

/**
 * What a shop may do with one product right now, and what it should say.
 *
 * ⚠ Two questions, deliberately kept apart. `sellable` decides whether a button
 * works. `madeToOrder` decides what the page SAYS, and it is true only when the
 * shop is selling something it does not currently hold. A shop that is quietly
 * out of stock and says nothing is how a sale becomes a complaint.
 */
final readonly class AvailabilityDecision
{
    public function __construct(
        public bool $sellable,
        public ?string $reason = null,
        public bool $madeToOrder = false,
    ) {}

    /**
     * The sentence a storefront shows. Null when there is nothing worth saying,
     * which is the ordinary case of a thing being in stock.
     *
     * ⛔ It NEVER states a date. When the goods arrive is the shop's ONE promise
     * (D24, `Store::deliveryPromise()`), said once by the page, not per product.
     */
    public function message(): ?string
    {
        if (! $this->sellable) {
            return $this->reason;
        }

        if (! $this->madeToOrder) {
            return null;
        }

        return 'Made to order.';
    }

    /**
     * The shape a template receives. ⚠ Only these three facts: a theme decides
     * how to say them and never re-derives them from stock.
     *
     * @return array{sellable: bool, message: ?string, made_to_order: bool}
     */
    public function toArray(): array
    {
        return [
            'sellable' => $this->sellable,
            'message' => $this->message(),
            'made_to_order' => $this->madeToOrder,
        ];
    }
}
