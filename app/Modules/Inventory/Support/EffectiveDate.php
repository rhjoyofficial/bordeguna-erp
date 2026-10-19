<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Foundation\Corrections\ClosedPeriodPass;
use App\Modules\Foundation\Models\AccountingPeriod;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * When an inventory event actually happened, as opposed to when it was typed in.
 *
 * One place, because the rule must be identical for every path that moves stock.
 * Joy's standing principle: everything that changes inventory quantity or value
 * goes through the same pipeline, and a validation rule that lives in three
 * controllers is three rules waiting to disagree.
 */
class EffectiveDate
{
    /**
     * Resolves a caller-supplied date, defaulting to now.
     *
     * ⚠ A future date is refused rather than clamped. Future-dated stock is
     * availability that does not exist yet, and silently moving it to now would
     * hide a data-entry error that changes what the warehouse appears to hold.
     */
    public static function resolve(mixed $occurredAt, string $label = 'occurred_at', ?ClosedPeriodPass $pass = null): Carbon
    {
        $date = $occurredAt === null ? Carbon::now() : Carbon::parse($occurredAt);

        if ($date->isFuture()) {
            throw new InvalidArgumentException(
                "The {$label} date {$date->toDateTimeString()} is in the future; stock cannot move before it exists."
            );
        }

        // C-SC-2. Checked HERE, at the one point every stock path already passes
        // through, rather than in each action. Joy's principle is that nothing
        // may modify inventory down its own private route, and a rule copied
        // into five callers is five rules waiting to disagree - the next new
        // action would simply forget it.
        // ⭐ Corrections (20 Oct 2026): the Owner's one-document pass, handed
        // down by a correction that puts stock back inside a closed month.
        AccountingPeriod::assertOpenFor($date, $pass);

        return $date;
    }

    /**
     * `resolve()` for an event that CONSUMES stock and is entered as a bare
     * date: today means now, an earlier day means the end of that day.
     *
     * ⚠⚠ A bare date parses to midnight, so a production run dated today could
     * not use anything received after 00:00 today: assertNotBefore() refused
     * it. A full date-time is kept as given.
     *
     * ⚠ Not folded into resolve(). Stock-IN paths (receipts, opening stock)
     * rely on a bare date meaning the start of the day, so later movements
     * that day can draw on them.
     */
    public static function resolveConsumingDay(mixed $occurredAt, string $label = 'occurred_at'): Carbon
    {
        if (is_string($occurredAt) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($occurredAt)) === 1) {
            $day = Carbon::parse(trim($occurredAt));

            if ($day->isSameDay(Carbon::now())) {
                $occurredAt = Carbon::now();
            } elseif ($day->lt(Carbon::today())) {
                $occurredAt = $day->setTime(23, 59, 59);
            }
        }

        return self::resolve($occurredAt, $label);
    }

    /**
     * ⚠ A withdrawal cannot predate the stock it consumes. Taking goods out on
     * the 1st that arrived on the 5th is not a date to be sorted into place, it
     * is an impossible sequence, and accepting it would produce a ledger whose
     * running balance goes negative partway through its own history.
     */
    public static function assertNotBefore(Carbon $movedAt, Carbon $receivedAt, int $batchId): void
    {
        if ($movedAt->lt($receivedAt)) {
            throw new InvalidArgumentException(
                "Cannot move stock on {$movedAt->toDateTimeString()} from batch #{$batchId}, which was received on {$receivedAt->toDateTimeString()}."
            );
        }
    }
}
