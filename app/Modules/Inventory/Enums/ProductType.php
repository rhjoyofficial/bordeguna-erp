<?php

namespace App\Modules\Inventory\Enums;

enum ProductType: string
{
    case RawMaterial = 'raw_material';
    case FinishedGood = 'finished_good';
    case Service = 'service';

    public function isStocked(): bool
    {
        return $this !== self::Service;
    }
}
