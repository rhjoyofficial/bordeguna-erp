<?php

namespace App\Modules\Sales\Couriers\Booking;

use App\Modules\Accounting\Support\Money;
use App\Modules\Crm\Support\PhoneIdentity;
use App\Modules\Sales\Actions\ResolveSellingPrice;
use App\Modules\Sales\Couriers\Locations\CourierDestination;
use App\Modules\Sales\Couriers\Locations\ResolvedDestination;
use App\Modules\Sales\Couriers\Locations\Unresolved;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\DeliveryLine;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderAddress;
use App\Modules\Sales\Support\DeliveryBilling;
use App\Modules\Sales\Support\ParcelWeight;

/**
 * What the ERP would tell a courier about one challan, worked out from the
 * ERP's own records: the order's address, the challan's lines, what the
 * challan would bill. The order screen shows it before the button is pressed;
 * the booking action builds from the same thing, so the two never disagree.
 *
 * ⚠ The recipient comes from the ORDER's delivery address (sales_order_addresses),
 * never from the customer master: a gift goes to somebody else's door.
 */
final class ParcelDraft
{
    public const PHONE_PATTERN = '/^01[3-9]\d{8}$/';

    /** CarryBee 2-99, Pathao 3-100: the tighter of the two. */
    public const NAME_MIN = 3;

    public const NAME_MAX = 99;

    public const ADDRESS_MIN = 10;

    public const DESCRIPTION_MAX = 250;

    private function __construct(
        public readonly Delivery $delivery,
        public readonly ?SalesOrder $order,
        public readonly ?SalesOrderAddress $address,
        public readonly ?Money $due,
        public readonly ?int $weightSuggested,
        public readonly int $quantity,
        public readonly string $description,
        // Owner, 17 Oct 2026: the order's special instructions, offered as the
        // rider's note. The person booking may change or clear it.
        public readonly ?string $specialInstruction = null,
    ) {}

    public static function for(Delivery $delivery, ResolveSellingPrice $resolver): self
    {
        $delivery->loadMissing('lines.product', 'lines.orderLine.order.address');

        $orders = $delivery->lines->map(fn (DeliveryLine $line) => $line->orderLine?->order)->filter()->unique('id')->values();
        // ⚠ One order, one door. A challan carrying two orders has no single
        // address to send it to, so it books by hand.
        $order = $orders->count() === 1 ? $orders->first() : null;

        $quantity = (int) ceil((float) $delivery->lines->sum(fn (DeliveryLine $line) => (float) $line->quantity));

        return new self(
            delivery: $delivery,
            order: $order,
            address: $order?->address,
            due: DeliveryBilling::previewDue($delivery, $resolver),
            weightSuggested: ParcelWeight::suggestedForDelivery($delivery),
            quantity: max(1, min(200, $quantity)),
            description: self::describe($delivery),
            specialInstruction: $order?->special_instructions,
        );
    }

    /**
     * "2 x Royal Essence Ghee 400g (gift wrap), 1 x Mustard Oil 5L", cut short
     * to fit. A line's own note on the order rides in brackets (17 Oct 2026).
     */
    public static function describe(Delivery $delivery): string
    {
        $parts = $delivery->lines
            ->filter(fn (DeliveryLine $line) => (float) $line->quantity > 0)
            ->map(fn (DeliveryLine $line) => rtrim(rtrim(number_format((float) $line->quantity, 2, '.', ''), '0'), '.').' x '.($line->product?->name ?? 'item')
                .(filled($line->orderLine?->description) ? ' ('.trim((string) $line->orderLine->description).')' : ''));

        return mb_strimwidth($parts->implode(', '), 0, self::DESCRIPTION_MAX, '...');
    }

    public function recipientName(): string
    {
        return mb_substr(trim((string) $this->address?->recipient_name), 0, self::NAME_MAX);
    }

    /** 01XXXXXXXXX, or null when the number cannot be one. */
    public function recipientPhone(): ?string
    {
        $phone = PhoneIdentity::normalize($this->address?->recipient_phone);

        return $phone !== null && preg_match(self::PHONE_PATTERN, $phone) ? $phone : null;
    }

    /**
     * The street part of the address the courier's rider reads. District and
     * thana travel as the courier's own ids; they are kept in the text too,
     * because a rider reads text. The landmark is the first thing dropped when
     * the courier's limit is tight.
     */
    public function addressText(int $max): string
    {
        $parts = fn (bool $withLandmark) => collect([
            $this->address?->address_line,
            $withLandmark ? $this->address?->landmark : null,
            $this->address?->area,
            $this->address?->thana,
            $this->address?->district,
        ])->map(fn (?string $part) => trim((string) $part))->filter(fn (string $part) => $part !== '')->implode(', ');

        $text = $parts(true);

        return mb_strlen($text) <= $max ? $text : $parts(false);
    }

    /**
     * What stops this challan going to a courier through the API at all,
     * whichever courier. Empty when nothing does.
     *
     * @return array<string, string> field => sentence
     */
    public function problems(int $addressMax = 200): array
    {
        $problems = [];

        if ($this->order === null) {
            $problems['order'] = 'This challan carries more than one order (or none), so there is no single address to send it to. Book it by hand.';

            return $problems;
        }

        if ($this->address === null) {
            $problems['address'] = 'Order '.$this->order->order_number.' has no delivery address. A courier booking needs the recipient, phone, district and thana.';

            return $problems;
        }

        if (mb_strlen($this->recipientName()) < self::NAME_MIN) {
            $problems['recipient_name'] = 'The recipient name on the order is missing or too short for the courier.';
        }

        if ($this->recipientPhone() === null) {
            $problems['recipient_phone'] = 'The recipient phone on the order is not an 11-digit mobile number (01XXXXXXXXX).';
        }

        $text = $this->addressText($addressMax);

        if (mb_strlen($text) < self::ADDRESS_MIN) {
            $problems['address'] = 'The delivery address is too short for the courier (at least 10 letters).';
        } elseif (mb_strlen($text) > $addressMax) {
            $problems['address'] = "The delivery address is longer than the courier takes ({$addressMax} letters). Shorten it on the order.";
        }

        return $problems;
    }

    public function destinationFor(string $provider): ResolvedDestination|Unresolved
    {
        return CourierDestination::resolve($provider, $this->address?->district, $this->address?->thana, $this->address?->area);
    }

    /**
     * Couriers collect whole taka. The figure offered is the due amount rounded
     * UP: rounding down would quietly lose the paisa.
     */
    public function codWholeTaka(): ?int
    {
        return $this->due === null ? null : (int) ceil($this->due->minor / 100);
    }

    public function dueHasPaisa(): bool
    {
        return $this->due !== null && $this->due->minor % 100 !== 0;
    }
}
