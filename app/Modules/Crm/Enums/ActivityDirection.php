<?php

namespace App\Modules\Crm\Enums;

enum ActivityDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';

    public function label(): string
    {
        return match ($this) {
            self::Inbound => 'They contacted us',
            self::Outbound => 'We contacted them',
        };
    }
}
