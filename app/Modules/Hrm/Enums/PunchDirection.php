<?php

namespace App\Modules\Hrm\Enums;

/**
 * Which way a punch went, when the capture method actually knows.
 *
 * ATT-06 deliberately did NOT tick "use the device's status" — pairing is
 * decided by policy (first-in / last-out with break pairs) during Phase-2b2
 * processing, not at capture time. So this stays nullable: a ZKTeco row that
 * carries no usable status is stored as unknown rather than guessed, and the
 * processing engine derives the pairing from the ordered punches of the day.
 */
enum PunchDirection: string
{
    case In = 'in';
    case Out = 'out';

    public function label(): string
    {
        return match ($this) {
            self::In => 'In',
            self::Out => 'Out',
        };
    }
}
