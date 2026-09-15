<?php

namespace App\Modules\Purchasing\Enums;

/**
 * The certifications a food supplier may hold (Joy's answer 18, CRM lead slice
 * 2e): the ones a Bangladeshi food buyer is actually asked about, and Other.
 *
 * ⚠ Answer 18: ONLY WHEN RELEVANT. A certification is an optional entry on the
 * supplier record; nothing requires one, and nothing is blocked without one.
 *
 * ⚠ Adding a case is safe while nothing branches on it. It is a label on a
 * record, not a switch that changes how a purchase behaves.
 */
enum CertificationType: string
{
    case BstiCm = 'bsti_cm';

    case Bfsa = 'bfsa';

    case Halal = 'halal';

    case Iso22000 = 'iso_22000';

    case Haccp = 'haccp';

    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::BstiCm => 'BSTI CM',
            self::Bfsa => 'BFSA',
            self::Halal => 'Halal',
            self::Iso22000 => 'ISO 22000',
            self::Haccp => 'HACCP',
            self::Other => 'Other',
        };
    }
}
