<?php

namespace App\Modules\Sales\Support;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Sales\Enums\DeliveryMethod;
use App\Modules\Sales\Enums\OrderConfirmation;
use App\Modules\Sales\Enums\OrderPayment;
use App\Modules\Sales\Enums\SalesChannel;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ B18 (Joy's initial plan R91 to R96, 25 Oct 2026; Q2 = B, staff type every
 * order): what the order form says about HOW an order came and travels, read
 * the same way on a new order and on an edit.
 *
 * - Channel: Website (laladia.com, bionic.garden, or another shop named), Phone
 *   call, WhatsApp, Facebook / Messenger, Walk-in, Other; plus the other shop's
 *   own order number, which only WARNS on a repeat (`allow_duplicate_reference`).
 * - Delivery method and its charge: Courier = the zone charge, fixed (only a
 *   Manager or the Owner types another figure, and who did is kept); Rider and
 *   Own person = typed; Hand = no charge and no address.
 * - Payment: cash on delivery (the default), paid online through SSL (marked
 *   paid into a gateway account), or paid before (the customer's advance).
 * - The selling date: today for staff (the counter's rule); a Manager or the
 *   Owner any day of an open month.
 *
 * ⚠ Nothing here posts. The charge and the order discount ride on the order
 * exactly as before and are billed by the invoice; paid-online money is booked
 * by RecordOrderPayment, the order page's own payment.
 */
final class OrderChannelDetails
{
    /** The shops a website order is picked from; anything else is typed. */
    public const SITES = ['laladia.com', 'bionic.garden'];

    /** @return array<string, mixed> */
    public static function rules(int $businessId): array
    {
        return [
            'channel' => ['nullable', Rule::in(array_map(fn (SalesChannel $c) => $c->value, SalesChannel::picked()))],
            'channel_site' => ['nullable', 'string', 'max:60'],
            'external_reference' => ['nullable', 'string', 'max:60'],
            'allow_duplicate_reference' => ['nullable', 'boolean'],
            'delivery_method' => ['nullable', Rule::enum(DeliveryMethod::class)],
            'rider_employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')],
            'cod_money_account_id' => ['nullable', 'integer', Rule::exists('money_accounts', 'id')->where('business_id', $businessId)],
            'payment' => ['nullable', Rule::enum(OrderPayment::class)],
            'paid_money_account_id' => ['nullable', 'integer', Rule::exists('money_accounts', 'id')->where('business_id', $businessId)],
            'paid_reference' => ['nullable', 'string', 'max:255'],
            'order_discount' => ['nullable', 'numeric', 'min:0', 'max:10000000', 'decimal:0,2'],
            'lines.*.is_gift' => ['nullable', 'boolean'],
        ];
    }

    /** May this person date an order on any open day (else today only)? */
    public static function mayBackdate(User $user): bool
    {
        return $user->hasRole('Owner') || $user->hasRole('Manager');
    }

    /** May this person change a courier's fixed zone charge? */
    public static function mayChangeCourierCharge(User $user): bool
    {
        return $user->hasRole('Owner') || $user->hasRole('Manager');
    }

    /**
     * The selling date rule (Joy: "current date default selected, can be
     * changeable"). Staff: today, as at the counter. Manager / Owner: any day
     * not in a closed month. On an edit an unchanged date is not asked again.
     */
    public static function assertSaleDate(?string $date, User $user, ?SalesOrder $existing = null): void
    {
        if ($date === null || $date === '') {
            return;
        }

        $day = Carbon::parse($date)->startOfDay();

        if ($existing !== null && $existing->ordered_at?->toDateString() === $day->toDateString()) {
            return;
        }

        if (! $day->isToday() && ! self::mayBackdate($user)) {
            throw ValidationException::withMessages(['ordered_at' => 'Staff take orders on today\'s date. Ask a Manager or the Owner to enter an order for another day.']);
        }

        if (AccountingPeriod::isClosedOn($day)) {
            throw ValidationException::withMessages(['ordered_at' => 'That day is in a closed month. Choose a day of an open month.']);
        }
    }

    /** ⭐ IP1: the person's own active Employee cash account (the first one), or null. */
    public static function employeeCashOf(int $employeeId): ?MoneyAccount
    {
        return MoneyAccount::query()
            ->where('type', MoneyAccountType::EmployeeCash->value)
            ->where('custodian_employee_id', $employeeId)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
    }

    /**
     * The same reference on another live order of this business, as a sentence,
     * or null. A warning: the person may save anyway.
     */
    public static function duplicateReference(?string $reference, ?int $exceptOrderId = null): ?string
    {
        $reference = trim((string) $reference);

        if ($reference === '') {
            return null;
        }

        $other = SalesOrder::query()
            ->where(fn ($q) => $q->where('external_reference', $reference)->orWhere('shop_reference', $reference))
            ->when($exceptOrderId !== null, fn ($q) => $q->whereKeyNot($exceptOrderId))
            ->orderByDesc('id')
            ->first(['id', 'order_number', 'ordered_at']);

        return $other === null ? null : "Order {$other->order_number} of ".$other->ordered_at?->format('d/m/Y')." already carries reference {$reference}. Tick \"Save anyway\" if this is a different order.";
    }

    /**
     * What the form's channel, delivery and payment boxes become on the order,
     * and the delivery charge to keep. Refuses with a sentence under the box.
     *
     * @param  array<string, mixed>  $data
     * @return array{attributes: array<string, mixed>, delivery_charge: string|null, hand_over: bool}
     */
    public static function resolve(array $data, User $user, ?SalesOrderDestination $destination, ?int $brandId, ?SalesOrder $existing = null): array
    {
        $channel = isset($data['channel']) && $data['channel'] !== '' ? SalesChannel::from($data['channel']) : null;
        $site = trim((string) ($data['channel_site'] ?? ''));
        $reference = trim((string) ($data['external_reference'] ?? ''));

        if ($channel === SalesChannel::Website && $site === '') {
            throw ValidationException::withMessages(['channel_site' => 'Say which shop the website order came from.']);
        }

        if (! ($data['allow_duplicate_reference'] ?? false) && ($repeat = self::duplicateReference($reference, $existing?->id)) !== null
            && $reference !== (string) $existing?->external_reference) {
            throw ValidationException::withMessages(['external_reference' => $repeat]);
        }

        $method = isset($data['delivery_method']) && $data['delivery_method'] !== '' ? DeliveryMethod::from($data['delivery_method']) : null;
        $typed = isset($data['delivery_charge']) && $data['delivery_charge'] !== '' && $data['delivery_charge'] !== null
            ? Money::ofTaka((string) $data['delivery_charge'])
            : null;
        $charge = $typed !== null && $typed->minor > 0 ? $typed->toTaka() : null;
        $zone = null;
        $setBy = null;

        if ($method === DeliveryMethod::Hand) {
            $charge = null;
        } elseif ($method === DeliveryMethod::Courier) {
            $quote = CourierZoneCharge::for($brandId, $destination?->district);

            if ($quote === null) {
                throw ValidationException::withMessages(['address.district' => 'A courier delivery needs the district it goes to; the charge is that zone\'s.']);
            }

            $zone = $quote->zone;
            $keptBefore = $existing !== null && $existing->delivery_method === DeliveryMethod::Courier
                && $existing->delivery_charge_set_by !== null
                && $typed !== null && $typed->minor === $existing->deliveryCharge()->minor;

            if ($keptBefore) {
                // A charge a Manager set stays when somebody else saves the order unchanged.
                $setBy = (int) $existing->delivery_charge_set_by;
            } elseif ($typed !== null && $typed->minor !== Money::ofTaka($quote->charge)->minor) {
                if (! self::mayChangeCourierCharge($user)) {
                    throw ValidationException::withMessages(['delivery_charge' => "The courier charge to {$quote->district} is ".Money::ofTaka($quote->charge)->format()." ({$quote->zone}). Only a Manager or the Owner can change it."]);
                }

                $setBy = (int) $user->id;
            } else {
                $charge = Money::ofTaka($quote->charge)->minor > 0 ? Money::ofTaka($quote->charge)->toTaka() : null;
            }
        }

        $rider = null;
        $codAccount = null;

        if ($method === DeliveryMethod::OwnStaff) {
            if (isset($data['rider_employee_id'])) {
                $rider = Employee::query()->find((int) $data['rider_employee_id']);
            }

            // ⭐ IP1 meets IP16 (integration, 25 Oct 2026): the cash our own
            // person collects is his to hand in, so with no account chosen it
            // goes into HIS Employee cash account (holder = the chosen person),
            // when he has one. A cash box may still be chosen instead, and the
            // order's Record payment box starts on whichever this is.
            if (isset($data['cod_money_account_id'])) {
                $codAccount = MoneyAccount::query()->find((int) $data['cod_money_account_id']);

                if ($codAccount !== null && ! in_array($codAccount->type, [MoneyAccountType::Cash, MoneyAccountType::EmployeeCash], true)) {
                    throw ValidationException::withMessages(['cod_money_account_id' => 'The money our own person collects goes into a cash account or his own Employee cash account.']);
                }
            } elseif ($rider !== null) {
                $codAccount = self::employeeCashOf($rider->id);
            }
        }

        $payment = isset($data['payment']) && $data['payment'] !== '' ? OrderPayment::from($data['payment']) : null;

        $attributes = [
            'channel_site' => $channel === SalesChannel::Website ? mb_substr($site, 0, 60) : null,
            'external_reference' => $reference === '' ? null : mb_substr($reference, 0, 60),
            'delivery_method' => $method,
            'delivery_zone' => $zone,
            'delivery_charge_set_by' => $setBy,
            'rider_employee_id' => $rider?->id,
            'cod_money_account_id' => $codAccount?->id,
        ];

        if ($channel !== null) {
            $attributes['channel'] = $channel;
        }

        if ($payment !== null) {
            $attributes['payment_method'] = $payment->value;
        }

        // A new order from a channel that is called starts "not called yet"; a
        // walk-in buyer is standing here. An edit keeps what the calls found.
        if ($existing === null && $channel !== null) {
            $attributes['confirmation_status'] = $channel->needsConfirmation() ? OrderConfirmation::Waiting : OrderConfirmation::Confirmed;

            if (! $channel->needsConfirmation()) {
                $attributes['confirmed_by'] = $user->id;
                $attributes['confirmed_at'] = now();
            }
        } elseif ($existing !== null && $channel !== null && $existing->confirmation_status === null) {
            $attributes['confirmation_status'] = $channel->needsConfirmation() ? OrderConfirmation::Waiting : OrderConfirmation::Confirmed;
        }

        return [
            'attributes' => $attributes,
            'delivery_charge' => $charge,
            'hand_over' => $method === DeliveryMethod::Hand,
        ];
    }

    /**
     * ⭐ The order discount (on top of line discounts) held to the person's
     * discount cap (IP8, Q12 = A), measured over the order's own lines at their
     * agreed prices. Gift lines are the business's offer and never counted.
     */
    public static function assertOrderDiscount(SalesOrder $order, User $user): void
    {
        $discount = $order->orderDiscount();

        if ($discount->isZero()) {
            return;
        }

        $order->loadMissing('lines.product');
        $lines = $order->lines->reject(fn ($line) => (bool) $line->is_gift)->map(fn ($line) => [
            'quantity' => (string) $line->quantity_ordered,
            'unitPrice' => (string) $line->unit_price,
            'discount' => (string) $line->line_discount,
            'listPrice' => (string) $line->unit_price,
            'name' => $line->product?->name,
        ])->values()->all();

        $refusal = DiscountCap::refusal($user, (int) $order->business_id, $lines, $discount->toTaka());

        if ($refusal !== null) {
            throw ValidationException::withMessages(['order_discount' => $refusal]);
        }
    }
}
