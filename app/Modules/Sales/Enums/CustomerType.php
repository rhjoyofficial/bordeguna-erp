<?php

namespace App\Modules\Sales\Enums;

/**
 * What kind of customer this is.
 *
 * ⚠⚠ Present from the FIRST migration on purpose. Joy's constraint is that a
 * customer must be able to become a distributor, a dealer or a corporate account
 * without the record being redesigned - and a `customers` table that starts with
 * only a name is exactly how a second customer table appears six months later.
 *
 * ⚠ Nothing in the sales engine BRANCHES on this. It is a classification for
 * pricing, reporting and eventually credit terms, not a switch that changes how
 * a sale behaves. The moment a `match` on this enum decides how stock leaves or
 * how a journal posts, the one-engine promise is broken and the Distributor
 * module has become a second implementation.
 *
 * ⚠ Adding a case here is safe only while that stays true - the shared-enum
 * incident on this project broke every exhaustive `match` at once.
 */
enum CustomerType: string
{
    case WalkIn = 'walk_in';
    case Retail = 'retail';
    /**
     * ⭐ A retail SHOP, added 26 Sep 2026 (Joy, Q15): a shop never pays MRP, it
     * needs a shop price on every product, or it cannot be sold to. `retail`
     * stays what website orders and CRM conversions make: a person buying for
     * themselves, priced at MRP when nothing else is set.
     */
    case Shop = 'shop';
    case Dealer = 'dealer';
    case Distributor = 'distributor';
    case Corporate = 'corporate';
    /**
     * ⭐ An EMPLOYEE buying under the staff benefit (ChatGPT ruling Q2, Phase 2,
     * 17 Oct 2026): one customer row per employee, linked by
     * `customers.employee_id`, created by the system on the first request and
     * never typed by hand. Its invoices are that employee's receivable; its
     * details are visible to Owner, HR authority and Finance only.
     */
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::WalkIn => 'Walk-in',
            self::Retail => 'Retail customer',
            self::Shop => 'Retail shop',
            self::Dealer => 'Dealer',
            self::Distributor => 'Distributor',
            self::Corporate => 'Corporate',
            self::Staff => 'Staff (employee)',
        };
    }

    /**
     * The types a person may pick on a form. Staff rows are made by the
     * staff-purchase flow only (an employee is linked, not typed).
     *
     * @return array<int, self>
     */
    public static function pickable(): array
    {
        return array_values(array_filter(self::cases(), fn (self $type) => $type !== self::Staff));
    }

    /**
     * The price group a customer of this type is priced in when none is chosen.
     *
     * ⚠ Classification for pricing, which is what this enum is for; the sales
     * engine still does not branch on it. Consumers (walk-in, retail) pay
     * RETAIL, the one group that falls back to MRP; everybody else is priced in
     * the group of their own name and is refused when it has no price
     * (ResolveSellingPrice). A business missing the group keeps RETAIL.
     */
    public function defaultPriceGroupCode(): string
    {
        return match ($this) {
            self::WalkIn, self::Retail => 'RETAIL',
            self::Shop => 'SHOP',
            self::Dealer => 'DEALER',
            self::Distributor => 'DISTRIBUTOR',
            self::Corporate => 'CORPORATE',
            // ⚠ Compatibility only: the staff price is COMPUTED from the
            // reserved batch cost (StaffPurchasePricing), never read from a
            // STAFF price row. The group stays so old rows still resolve.
            self::Staff => 'STAFF',
        };
    }
}
