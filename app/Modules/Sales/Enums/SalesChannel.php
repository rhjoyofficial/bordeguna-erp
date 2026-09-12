<?php

namespace App\Modules\Sales\Enums;

/**
 * How an order reached us.
 *
 * ⚠⚠ NOTHING IN THE SALES ENGINE BRANCHES ON THIS, and the same warning
 * CustomerType carries applies here in full: the day a `match` on this enum
 * decides how a price resolves, how stock leaves or how a journal posts, the
 * one-engine promise is broken and the website has become a second
 * implementation of selling. It is a dimension for reporting and for a screen
 * to label a row with, not a switch.
 *
 * ⚠ There are deliberately only two cases. `phone` and `counter` will be real
 * one day and are not invented here: a counter sale does not create a sales
 * order at all (RecordCounterSale is its own path), and a phone order is typed
 * through the ERP's own screens, which is exactly what Internal means today.
 * Naming a case before anything writes it is how an enum acquires values that
 * mean two different things to two different readers.
 */
enum SalesChannel: string
{
    /**
     * Somebody in this company typed it in. Every order that existed before
     * the website did, which is why null in the column reads as this.
     */
    case Internal = 'internal';

    /** A customer placed it themselves, on one of the group's shops. */
    case Website = 'website';

    public function label(): string
    {
        return match ($this) {
            self::Internal => 'Internal',
            self::Website => 'Website',
        };
    }

    /**
     * Did a customer place this themselves?
     *
     * ⚠ Used by screens to decide whether a human may be telephoned about it -
     * a website order has a buyer who never spoke to anybody, which is exactly
     * the order that wants confirming before goods leave.
     */
    public function isSelfServe(): bool
    {
        return $this === self::Website;
    }
}
