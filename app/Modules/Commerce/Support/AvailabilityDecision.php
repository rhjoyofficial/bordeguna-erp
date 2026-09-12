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
        public ?int $leadTimeDays = null,
    ) {}

    /**
     * The sentence a storefront shows. Null when there is nothing worth saying,
     * which is the ordinary case of a thing being in stock.
     *
     * ⛔ It NEVER invents a date. A made-to-order product with no lead time
     * recorded says it is made to order and stops there, because "ships in a
     * few days" is a promise somebody would then have to keep.
     */
    public function message(): ?string
    {
        if (! $this->sellable) {
            return $this->reason;
        }

        if (! $this->madeToOrder) {
            return null;
        }

        return $this->leadTimeDays === null
            ? 'Made to order.'
            : sprintf('Made to order, ready in about %d day%s.', $this->leadTimeDays, $this->leadTimeDays === 1 ? '' : 's');
    }

    /**
     * The shape a template receives. ⚠ Only these four facts: a theme decides
     * how to say them and never re-derives them from stock.
     *
     * @return array{sellable: bool, message: ?string, made_to_order: bool, lead_time_days: ?int}
     */
    public function toArray(): array
    {
        return [
            'sellable' => $this->sellable,
            'message' => $this->message(),
            'made_to_order' => $this->madeToOrder,
            'lead_time_days' => $this->leadTimeDays,
        ];
    }
}
