<?php

namespace App\Modules\Crm\Enums;

/**
 * Where a prospect stands. Joy's D1, approved 2026-08-09.
 *
 * ⚠⚠ AN ENUM RATHER THAN A TABLE, ON PURPOSE. This is a state machine the code
 * reasons about - `converted` in particular asserts that a financial
 * relationship exists. A table would let somebody invent a status no code
 * handles, and the reports would then quietly exclude those rows.
 *
 * ⚠⚠ NOTHING IN THE SALES ENGINE MAY EVER `match` ON THIS. The moment selling
 * behaviour branches on lead status or customer type, there are two engines
 * rather than one. This project has already been broken once by a shared enum
 * gaining a case and shattering every exhaustive match at the same time.
 *
 * ⚠ Adding a case is a deliberate architecture change, not a convenience.
 */
enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Qualified = 'qualified';
    case Opportunity = 'opportunity';
    case Converted = 'converted';
    case Lost = 'lost';
    case Nurture = 'nurture';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Contacted => 'Contacted',
            self::Qualified => 'Qualified',
            self::Opportunity => 'Opportunity',
            self::Converted => 'Converted',
            self::Lost => 'Lost',
            self::Nurture => 'Nurture',
        };
    }

    /**
     * The statuses a person may choose on the lead form.
     *
     * ⚠⚠ C-CRM-9: `Converted` IS DELIBERATELY ABSENT. It asserts that a customer
     * exists and is set only by the Phase 3 conversion action, which also writes
     * `converted_customer_id`. A lead reading `converted` with a null customer
     * link is a lie every report would repeat for ever.
     *
     * @return list<self>
     */
    public static function selectable(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $status) => $status !== self::Converted,
        ));
    }
}
