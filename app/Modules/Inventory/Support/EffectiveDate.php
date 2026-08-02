<?php

namespace App\Modules\Inventory\Support;

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
    public static function resolve(mixed $occurredAt, string $label = 'occurred_at'): Carbon
    {
        $date = $occurredAt === null ? Carbon::now() : Carbon::parse($occurredAt);

        if ($date->isFuture()) {
            throw new InvalidArgumentException(
                "The {$label} date {$date->toDateTimeString()} is in the future; stock cannot move before it exists."
            );
        }

        return $date;
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
