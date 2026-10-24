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
 * ⭐ B18 (Joy's initial plan R96, 25 Oct 2026): phone, WhatsApp, Facebook,
 * walk-in and other orders are now real, because staff type every one of them
 * with a channel picker (Q2 = B). They are still only a label: Joy's words are
 * that they are "handled the same way" as a website order, returns included.
 * Internal stays for every order typed before the picker existed, and for the
 * import sheet's rows that named no channel.
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

    // ⭐ B18: typed by staff from a call or a message.
    case Phone = 'phone';
    case WhatsApp = 'whatsapp';
    case Facebook = 'facebook';
    case WalkIn = 'walk_in';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Internal => 'Internal',
            self::Website => 'Website',
            self::Phone => 'Phone call',
            self::WhatsApp => 'WhatsApp',
            self::Facebook => 'Facebook / Messenger',
            self::WalkIn => 'Walk-in',
            self::Other => 'Other',
        };
    }

    /**
     * The channels a person picks on the order form (B18). Internal is not
     * offered: it only names orders typed before the picker existed.
     *
     * @return list<self>
     */
    public static function picked(): array
    {
        return [self::Website, self::Phone, self::WhatsApp, self::Facebook, self::WalkIn, self::Other];
    }

    /**
     * Does an order from this channel wait for a confirmation call (R92)? A
     * walk-in customer is standing in front of us; everything else is called.
     */
    public function needsConfirmation(): bool
    {
        return $this !== self::WalkIn && $this !== self::Internal;
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
