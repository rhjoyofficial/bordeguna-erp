<?php

namespace App\Modules\Commerce\Support;

use App\Modules\Commerce\Models\Store;
use App\Modules\Sales\Models\SalesOrder;

/**
 * The number a customer quotes when they ring up about their order.
 *
 * ⚠⚠ NOT THE ORDER NUMBER, and the difference matters. `SO-FY2026-0001` runs
 * sequentially across the whole business: it interleaves two shops' orders in
 * one run, and anybody holding two of them can read off how much the company
 * sold in between. A shop reference carries the shop's own prefix - unique
 * across every business, because the shops share one SSLCommerz merchant and a
 * gateway reference has to identify one order in the whole group - and ends in
 * a random tail rather than a count.
 *
 * ⚠ The tail's alphabet excludes O/0 and I/1. This number gets read down a
 * telephone by somebody holding a parcel, and the pair of characters nobody can
 * tell apart is the one a support call is then spent on.
 */
final class ShopOrderReference
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const TAIL = 8;

    /**
     * ⚠ The unique index on `sales_orders.shop_reference` is the real guard;
     * this loop only avoids the pointless collision. 32^8 is about a thousand
     * billion, so a second attempt is already a curiosity - but a reference
     * generator that CAN return a duplicate would fail at the worst moment, in
     * front of a customer pressing Place order.
     */
    public static function mint(Store $store, ?string $today = null): string
    {
        $date = $today ?? now()->format('Ymd');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $reference = $store->order_prefix.'-'.$date.'-'.self::tail();

            $taken = SalesOrder::query()
                ->withoutGlobalScopes()
                ->where('shop_reference', $reference)
                ->exists();

            if (! $taken) {
                return $reference;
            }
        }

        // ⚠ Never silently returns a reference it knows is taken. Five
        // collisions in a row is not bad luck, it is a broken random source,
        // and a duplicate here would be a second order wearing a first order's
        // name on a payment gateway.
        throw new \RuntimeException('Could not mint a unique shop order reference.');
    }

    private static function tail(): string
    {
        $tail = '';

        for ($i = 0; $i < self::TAIL; $i++) {
            $tail .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $tail;
    }
}
