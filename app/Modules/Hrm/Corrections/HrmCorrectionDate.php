<?php

namespace App\Modules\Hrm\Corrections;

use App\Models\User;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Owners;
use App\Modules\Foundation\Corrections\Tk;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\AccountingPeriod;
use Illuminate\Support\Carbon;

/**
 * ⭐ Corrections wave 2, B8 (24 Oct 2026): which day an HRM money correction
 * (expense claim Cancel approval and Reverse payment, staff loan Cancel)
 * lands on.
 *
 *   month open     the original entry's own date (FINAL-PLAN section 9,
 *                  answer 4: "the original date everywhere")
 *   month closed   the Owner only, and it lands on the FIRST OPEN DAY
 *                  (FINAL-PLAN 4.2, "Expense claim: Reverse lands first open
 *                  day"); anybody else is told the month is closed
 *
 * ⚠⚠ Why not the Owner's one-document exception on the original date, as
 * every engine correction does: these are Cancel actions the module runs
 * itself, and the closed-month pass can only be granted inside the
 * CorrectionService (a structural test enforces it), which runs Reverse and
 * Correct only. Written down as a contract gap in the wave 2 report.
 */
final class HrmCorrectionDate
{
    public function __construct(
        public readonly ?Carbon $date,
        public readonly string $sentence,
        public readonly ?Blocker $blocker,
    ) {}

    public static function for(Carbon $original, ?User $actor, string $verb): self
    {
        $original = $original->copy()->startOfDay();

        if (! AccountingPeriod::isClosedOn($original)) {
            return new self($original, 'Lands on '.Tk::date($original).', its own date.', null);
        }

        $month = $original->format('F Y');

        if (! Owners::is($actor)) {
            return new self(null, '', new Blocker('period_closed_not_owner', "{$month} is closed. Only the Owner can {$verb} a closed month.", resolvableBy: 'owner'));
        }

        try {
            $open = AccountingPeriod::firstOpenOnOrAfter($original);
        } catch (PeriodClosedException) {
            return new self(null, '', new Blocker('no_open_day', "{$month} is closed and no later day is open yet."));
        }

        return new self($open, "{$month} is closed, so this lands on ".Tk::date($open).', the first open day.', null);
    }
}
