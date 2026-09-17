<?php

namespace App\Modules\Assets\Enums;

/**
 * Where a maintenance record stands (slice A4). Open is a fault reported or a
 * job under way; done and cancelled are final.
 */
enum MaintenanceState: string
{
    case Open = 'open';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Done => 'Done',
            self::Cancelled => 'Cancelled',
        };
    }
}
