<?php

namespace App\Modules\Commerce\Support;

use App\Modules\Accounting\Support\Money;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StoreCoupon;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Carbon;

/**
 * What a coupon takes off a basket on this shop. Website Phase 2, slice 2.5b.
 *
 * ⭐⭐ ONE CLASS, ASKED BY EVERY READER - the basket, the checkout and the coupon
 * strip - for the reason `ComboPricing` gives: written twice, the page and the
 * order disagree, and the shape of that is a customer quoted one figure and
 * billed another.
 *
 * ⭐ LaLaDia's rules, carried over so nothing a shop relies on changes: a minimum
 * spend is checked against the WHOLE basket (a customer adding a jar the coupon
 * does not cover is not penalised for it); a scoped coupon discounts only its
 * eligible lines; a percentage is rounded to the paisa; a fixed amount is capped
 * at what it applies to. And Joy's two (13 September): a store-wide coupon
 * discounts bundles too, and a cancelled order does not use a coupon up.
 *
 * ⭐ A GIFT IS GIVEN AS AN ORDER DISCOUNT, not as a zero price. The gift line is
 * priced by the engine like any other - so its stock and cost are real - and its
 * value joins the coupon's order-level discount, which posts to 4210 Discounts
 * Allowed. That keeps the whole cost of a coupon on one line of the profit and
 * loss, which is the reason Joy chose 4210 for coupons at all.
 *
 * ⚠ It never throws. Every refusal is a sentence a customer can be shown.
 */
final class CouponPricing
{
    /**
     * @param  list<array{product_id: int, total: Money}>  $productLines  standalone product lines, at what the basket charges for them
     * @param  list<array{combo_id: int, total: Money}>  $comboLines  bundle lines, at the bundle's own total
     */
    public static function quote(
        Store $store,
        string $code,
        array $productLines,
        array $comboLines,
        ?Customer $customer = null,
        ?Carbon $now = null,
    ): CouponQuote {
        $now ??= Carbon::now();
        $code = StoreCoupon::normaliseCode($code);

        $coupon = StoreCoupon::query()
            ->with('products:id', 'combos:id', 'giftProduct')
            ->where('store_id', $store->id)
            ->where('code', $code)
            ->first();

        // ⚠ The same sentence for a code that does not exist and one that is
        // switched off, so the box cannot be used to find out which codes are
        // real by trying them.
        if ($coupon === null || ! $coupon->is_active) {
            return CouponQuote::refused($code, 'That code is not valid.');
        }

        if ($refusal = self::timingRefusal($coupon, $now)) {
            return CouponQuote::refused($code, $refusal);
        }

        if ($coupon->usage_limit !== null && self::timesUsed($coupon) >= $coupon->usage_limit) {
            return CouponQuote::refused($code, 'This code has already been used as many times as it allows.');
        }

        if ($customer !== null && $coupon->limit_per_customer !== null
            && self::timesUsed($coupon, $customer) >= $coupon->limit_per_customer) {
            return CouponQuote::refused($code, 'You have already used this code as many times as it allows.');
        }

        $subtotal = Money::sum([
            ...array_map(fn (array $line) => $line['total'], $productLines),
            ...array_map(fn (array $line) => $line['total'], $comboLines),
        ]);

        if ($coupon->min_purchase !== null && $subtotal->minor < Money::ofTaka((string) $coupon->min_purchase)->minor) {
            return CouponQuote::refused($code, sprintf(
                'This code needs a basket of at least BDT %s.',
                Money::ofTaka((string) $coupon->min_purchase)->format(),
            ));
        }

        $eligible = match ($coupon->applies_to) {
            StoreCoupon::APPLIES_PRODUCTS => Money::sum(array_map(
                fn (array $line) => $line['total'],
                array_filter($productLines, fn (array $line) => $coupon->products->contains('id', $line['product_id'])),
            )),
            StoreCoupon::APPLIES_COMBOS => Money::sum(array_map(
                fn (array $line) => $line['total'],
                array_filter($comboLines, fn (array $line) => $coupon->combos->contains('id', $line['combo_id'])),
            )),
            // ⭐ Joy, 13 September: a store-wide coupon discounts bundles too.
            default => $subtotal,
        };

        if ($coupon->applies_to !== StoreCoupon::APPLIES_ALL && $eligible->isZero()) {
            return CouponQuote::refused($code, 'This code does not apply to anything in your basket.');
        }

        $discount = self::discountOn($coupon, $eligible);

        [$gift, $giftProblem] = self::gift($store, $coupon);

        return new CouponQuote(
            code: $code,
            refusal: null,
            coupon: $coupon,
            discount: $discount,
            freeDelivery: $coupon->is_free_delivery,
            gift: $gift,
            giftProblem: $giftProblem,
        );
    }

    /**
     * Should this code be shown to the public right now (the coupon strip)?
     *
     * ⚠ Only what the checkout would not refuse on the code alone: switched on,
     * within its dates, not used up. A basket-dependent rule (minimum spend,
     * scope) is a condition of the offer, not a reason to hide it.
     */
    public static function advertisable(Store $store, ?string $code, ?Carbon $now = null): ?StoreCoupon
    {
        $code = StoreCoupon::normaliseCode($code);

        if ($code === '') {
            return null;
        }

        $coupon = StoreCoupon::query()->where('store_id', $store->id)->where('code', $code)->first();

        if ($coupon === null || ! $coupon->is_active || self::timingRefusal($coupon, $now ?? Carbon::now()) !== null) {
            return null;
        }

        if ($coupon->usage_limit !== null && self::timesUsed($coupon) >= $coupon->usage_limit) {
            return null;
        }

        return $coupon;
    }

    /**
     * How many orders have used this coupon, optionally for one customer.
     *
     * ⛔⛔ COUNTED FROM ORDERS, NEVER STORED, and a CANCELLED or REJECTED order
     * does not count (Joy, 13 September). An order that never happened must not
     * spend a one-per-customer code.
     */
    public static function timesUsed(StoreCoupon $coupon, ?Customer $customer = null): int
    {
        return SalesOrder::query()
            ->where('store_id', $coupon->store_id)
            ->where('coupon_code', $coupon->code)
            ->where('status', '!=', SalesOrderStatus::Cancelled)
            ->whereDoesntHave('approvalRequest', fn ($query) => $query->where('status', ApprovalStatus::Rejected))
            ->when($customer !== null, fn ($query) => $query->where('customer_id', $customer->id))
            ->count();
    }

    private static function timingRefusal(StoreCoupon $coupon, Carbon $now): ?string
    {
        if ($coupon->starts_at !== null && $now->lt($coupon->starts_at)) {
            return 'This code is not active yet.';
        }

        if ($coupon->ends_at !== null && $now->gt($coupon->ends_at)) {
            return 'This code has expired.';
        }

        return null;
    }

    /**
     * ⚠ In integer paisa. A percentage is rounded half away from zero, the rule
     * `Money` uses everywhere; a fixed amount never exceeds what it applies to.
     */
    private static function discountOn(StoreCoupon $coupon, Money $eligible): Money
    {
        if ($eligible->minor <= 0) {
            return Money::zero();
        }

        if ($coupon->type === StoreCoupon::TYPE_PERCENT) {
            $percentHundredths = Money::ofTaka((string) $coupon->value)->minor;
            $product = $eligible->minor * $percentHundredths;
            $share = intdiv($product, 10000);

            if (($product % 10000) * 2 >= 10000) {
                $share++;
            }

            return Money::ofMinor(min($share, $eligible->minor));
        }

        return Money::ofMinor(min(Money::ofTaka((string) $coupon->value)->minor, $eligible->minor));
    }

    /**
     * The gift this coupon promises, priced, or the reason it cannot be given.
     *
     * ⚠⚠ A GIFT THAT CANNOT BE GIVEN IS SAID OUT LOUD, never dropped in silence.
     * LaLaDia computed a list of skipped gifts and showed it nowhere, so a
     * customer who added a jar to earn the honey got no honey and no reason.
     *
     * @return array{0: ?array{product: Product, quantity: int, gross: Money}, 1: ?string}
     */
    private static function gift(Store $store, StoreCoupon $coupon): array
    {
        $product = $coupon->giftProduct;

        if ($product === null) {
            return [null, null];
        }

        $quantity = max(1, (int) $coupon->gift_quantity);

        if (CataloguePublishing::refusalFor($store, $product) !== null || ! Availability::decide($store, $product)->sellable) {
            return [null, "The free {$product->name} with this code is not available right now, so it is not included."];
        }

        $price = CataloguePublishing::priceFor($store, $product, null, (string) $quantity);

        if ($price === null) {
            return [null, "The free {$product->name} with this code is not available right now, so it is not included."];
        }

        return [[
            'product' => $product,
            'quantity' => $quantity,
            'gross' => Money::ofProduct((string) $quantity, $price),
        ], null];
    }
}
