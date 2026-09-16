<?php

namespace App\Modules\Assets\Enums;

/**
 * What a stock-take found wrong (slice A3), and how each may be settled.
 */
enum DiscrepancyKind: string
{
    case Missing = 'missing';
    case Unexpected = 'unexpected';
    case Short = 'short';
    case Over = 'over';

    public function label(): string
    {
        return match ($this) {
            self::Missing => 'Expected, not found',
            self::Unexpected => 'Found, not expected here',
            self::Short => 'Fewer pieces than expected',
            self::Over => 'More pieces than expected',
        };
    }

    /**
     * @return list<DiscrepancyResolution>
     */
    public function resolutions(): array
    {
        return match ($this) {
            self::Missing => [DiscrepancyResolution::Located, DiscrepancyResolution::WronglyRecorded, DiscrepancyResolution::FoundDamaged, DiscrepancyResolution::ConfirmedLost],
            self::Unexpected => [DiscrepancyResolution::Located, DiscrepancyResolution::WronglyRecorded],
            self::Short => [DiscrepancyResolution::Located, DiscrepancyResolution::ConfirmedLost],
            self::Over => [DiscrepancyResolution::Located, DiscrepancyResolution::WronglyRecorded],
        };
    }
}
