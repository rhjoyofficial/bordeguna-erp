<?php

namespace App\Modules\Inventory\Enums;

enum MovementDirection: string
{
    case In = 'in';
    case Out = 'out';
}
