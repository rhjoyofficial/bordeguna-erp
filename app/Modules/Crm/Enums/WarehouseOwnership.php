<?php

namespace App\Modules\Crm\Enums;

/** Whether a distributor's warehouses are its own, as the distributor tells it. */
enum WarehouseOwnership: string
{
    case Owned = 'owned';

    case Rented = 'rented';

    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::Owned => 'Owned',
            self::Rented => 'Rented',
            self::Both => 'Some of each',
        };
    }
}
