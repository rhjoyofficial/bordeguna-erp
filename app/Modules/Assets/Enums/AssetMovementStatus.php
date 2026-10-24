<?php

namespace App\Modules\Assets\Enums;

/**
 * Where a movement stands. A one-step movement is `completed` the moment it is
 * recorded; a transfer is `dispatched` until the other place receives it.
 */
enum AssetMovementStatus: string
{
    case Dispatched = 'dispatched';
    case Completed = 'completed';
    // Corrections wave 3 (24 Oct 2026): a transfer taken off the road before
    // it arrived. Only ReverseAssetMovement::cancel writes it.
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Dispatched => 'On its way',
            self::Completed => 'Done',
            self::Cancelled => 'Cancelled',
        };
    }
}
