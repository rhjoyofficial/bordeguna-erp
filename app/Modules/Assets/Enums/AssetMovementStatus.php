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

    public function label(): string
    {
        return match ($this) {
            self::Dispatched => 'On its way',
            self::Completed => 'Done',
        };
    }
}
