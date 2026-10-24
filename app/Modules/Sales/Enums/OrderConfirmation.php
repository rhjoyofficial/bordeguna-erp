<?php

namespace App\Modules\Sales\Enums;

/**
 * ⭐ B18 (Joy's initial plan R92, 25 Oct 2026): "confirm it through call or
 * message by sales person". Where the confirmation call stands.
 *
 * ⚠⚠ SEPARATE FROM THE APPROVAL. The approval is the office agreeing to the
 * terms (price, credit, stock); this is the CUSTOMER saying yes on the phone.
 * A manager's own order is approved the moment it is saved and is still not
 * confirmed until somebody has rung the buyer. Nothing is blocked by it: the
 * order page and the "To confirm" list say it, the person decides.
 *
 * ⚠ Null on an order typed before this slice: no call was asked for.
 */
enum OrderConfirmation: string
{
    case Waiting = 'waiting';
    case Confirmed = 'confirmed';
    case NoAnswer = 'no_answer';
    case CustomerCancelled = 'customer_cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'Not called yet',
            self::Confirmed => 'Called, confirmed',
            self::NoAnswer => 'No answer',
            self::CustomerCancelled => 'Customer cancelled',
        };
    }

    /** Still somebody's job to ring. */
    public function isOpen(): bool
    {
        return $this === self::Waiting || $this === self::NoAnswer;
    }
}
