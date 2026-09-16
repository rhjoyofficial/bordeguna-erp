<?php

namespace App\Modules\Assets\Enums;

/**
 * How a gap a stock-take found was settled (slice A3, plan §2.9).
 *
 * ⚠ `confirmed_lost` is the write-off decision: Owner or Manager only
 * (`assets.items.write_off`). Its financial side waits for slice A5.
 */
enum DiscrepancyResolution: string
{
    case Located = 'located';
    case WronglyRecorded = 'wrongly_recorded';
    case FoundDamaged = 'found_damaged';
    case ConfirmedLost = 'confirmed_lost';

    public function label(): string
    {
        return match ($this) {
            self::Located => 'Located, all in order',
            self::WronglyRecorded => 'The register was wrong',
            self::FoundDamaged => 'Found, but damaged',
            self::ConfirmedLost => 'Confirmed lost',
        };
    }

    /**
     * What the resolution means for each kind, as the words beside the choice.
     */
    public function meaningFor(DiscrepancyKind $kind): string
    {
        return match ([$kind, $this]) {
            [DiscrepancyKind::Missing, self::Located] => 'It was there after all. Its status goes back to what it was.',
            [DiscrepancyKind::Missing, self::WronglyRecorded] => 'It is somewhere else; record the hand-over that should have been made. Its status goes back.',
            [DiscrepancyKind::Missing, self::FoundDamaged] => 'It turned up damaged. Condition Damaged, status back.',
            [DiscrepancyKind::Missing, self::ConfirmedLost] => 'It is gone. It stays Missing until it is disposed of.',
            [DiscrepancyKind::Unexpected, self::Located] => 'It belongs here now; record the move that brought it.',
            [DiscrepancyKind::Unexpected, self::WronglyRecorded] => 'It was recorded wrongly; nothing changes.',
            [DiscrepancyKind::Short, self::Located] => 'The pieces turned up. Nothing changes.',
            [DiscrepancyKind::Short, self::ConfirmedLost] => 'The pieces are gone. The group is counted fewer.',
            [DiscrepancyKind::Over, self::Located] => 'The extra pieces are ours. The group is counted more.',
            [DiscrepancyKind::Over, self::WronglyRecorded] => 'The count was wrong. Nothing changes.',
            default => '',
        };
    }
}
