<?php

namespace App\Modules\Hrm\Enums;

/**
 * Mobile Financial Service providers used for salary disbursement in
 * Bangladesh (EMP-04 bank/MFS account). Seeded as a fixed list; add cases if
 * a new provider is onboarded.
 */
enum MfsProvider: string
{
    case Bkash = 'bkash';
    case Nagad = 'nagad';
    case Rocket = 'rocket';
    case Upay = 'upay';

    public function label(): string
    {
        return match ($this) {
            self::Bkash => 'bKash',
            self::Nagad => 'Nagad',
            self::Rocket => 'Rocket',
            self::Upay => 'Upay',
        };
    }
}
