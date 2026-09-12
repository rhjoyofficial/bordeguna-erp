<?php

namespace App\Modules\Crm\Enums;

/**
 * What KIND of relationship a lead might become. Joy's decision, 10 Sep 2026.
 *
 * ⭐⭐ ONE TABLE WITH A TYPE, NOT FOUR TABLES. Everything a lead does -
 * activities, tasks, follow-ups, campaign attribution, conversion, notes,
 * duplicate links - is already built once against `leads`. Four tables would
 * mean teaching every one of those features about four models for ever, and a
 * fifth kind of relationship would mean doing it all again.
 *
 * ⚠⚠ NOTHING IN THE SALES ENGINE MAY EVER `match` ON THIS, for exactly the
 * reason written on LeadStatus: the moment selling behaviour branches on what
 * kind of lead somebody was, there are two engines rather than one. The only
 * thing this type is allowed to decide is stated below, and it is about
 * IDENTITY, not about money.
 */
enum LeadType: string
{
    /** A person who might buy for themselves. */
    case Customer = 'customer';

    /** A Dokandar or shop that might stock the product. */
    case Retailer = 'retailer';

    /** A wholesaler or area distributor. */
    case Distributor = 'distributor';

    /** Somebody who might sell TO the business rather than buy from it. */
    case Supplier = 'supplier';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Customer',
            self::Retailer => 'Retailer',
            self::Distributor => 'Distributor',
            self::Supplier => 'Supplier',
        };
    }

    /**
     * Is this lead a PLACE OF BUSINESS rather than a person?
     *
     * ⭐⭐ THE ONE RULE THIS TYPE DECIDES, and it decides identity rather than
     * behaviour. A shop's phone number IS the shop: two rows carrying it are not
     * a tidiness problem, they are two field officers each believing they own
     * the same relationship, which is the thing Joy asked to prevent.
     *
     * ⚠ A PERSON's number is not an identity in the same way. A household shares
     * one number, a family buys under one phone, and refusing the second member
     * of a household would be refusing a real customer - which is why
     * `PhoneIdentity` says in capitals that its output must not be made unique,
     * and why that rule still stands wherever this returns false.
     *
     * ⚠ A shop's landline is often the owner's own mobile. That is fine and is
     * why uniqueness is scoped BY TYPE rather than applied across the table: the
     * same number may be a retailer and a customer at once. What it may not be
     * is two retailers.
     */
    public function isShop(): bool
    {
        return $this !== self::Customer;
    }

    /** @return list<self> */
    public static function selectable(): array
    {
        return self::cases();
    }
}
