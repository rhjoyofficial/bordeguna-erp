<?php

namespace App\Modules\Purchasing\Support;

use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\Tk;
use App\Modules\Foundation\Models\AccountingPeriod;
use Illuminate\Support\Carbon;

/**
 * The limits a landed cost obeys before it may touch cost of sales.
 *
 * ⭐ Joy's Tk 10,000 sold-stock correction rule, applied to landed cost by the
 * ChatGPT ruling of 17 Oct 2026 (Q4): the share of a late cost that belongs to
 * stock ALREADY consumed or sold may be posted to the current period only up
 * to Tk 10,000 in total; above that an Owner-reviewed accounting decision is
 * required, and a closed month is never rewritten automatically.
 *
 * ⚠ Phase 1 REFUSES both cases with one sentence. The corrections Phase 0
 * Owner exception (ClosedPeriodExceptions) is a pass for ONE correction
 * request of ONE document and does not fit a new posting, so it is not
 * borrowed here; wiring an Owner decision for landed cost is Phase 2 and the
 * Owner's call.
 */
final class LandedCostPolicy
{
    /** Tk 10,000, in paisa. */
    public const CONSUMED_OWNER_DECISION_ABOVE_MINOR = 1_000_000;

    public const OWNER_DECISION = 'needs an Owner accounting decision';

    /** The refusal for a consumed share over the limit, or null when within it. */
    public static function consumedRefusal(Money $consumed): ?string
    {
        if ($consumed->minor <= self::CONSUMED_OWNER_DECISION_ABOVE_MINOR) {
            return null;
        }

        return 'The share for stock already consumed or sold is '.Tk::of($consumed->minor)
            .', above the '.Tk::of(self::CONSUMED_OWNER_DECISION_ABOVE_MINOR).' limit, so it '.self::OWNER_DECISION.'.';
    }

    /** The refusal for a date inside a closed month, or null when the month is open. */
    public static function closedMonthRefusal(Carbon $on): ?string
    {
        if (! AccountingPeriod::isClosedOn($on)) {
            return null;
        }

        return Tk::date($on).' is in a closed month, so this landed cost '.self::OWNER_DECISION.'.';
    }
}
