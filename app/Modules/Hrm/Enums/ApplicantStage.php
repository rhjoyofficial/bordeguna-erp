<?php

namespace App\Modules\Hrm\Enums;

/**
 * Where an applicant has got to.
 *
 * ⚠⚠ Adding a case shatters every exhaustive `match` over this enum. Grep before
 * you add one, exactly as ActivityType and PayrollStatus warn.
 *
 * ⚠ `Withdrawn` is separate from `Rejected` on purpose. "We said no" and "they
 * said no" are different facts about the business, and collapsing them makes a
 * hiring pipeline look healthier than it is.
 */
enum ApplicantStage: string
{
    case Applied = 'applied';
    case Screening = 'screening';
    case Interview = 'interview';
    case Offer = 'offer';
    case Hired = 'hired';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Applied => 'Applied',
            self::Screening => 'Screening',
            self::Interview => 'Interview',
            self::Offer => 'Offer made',
            self::Hired => 'Hired',
            self::Rejected => 'Not taken forward',
            self::Withdrawn => 'Withdrew',
        };
    }

    /** No further movement is expected from here. */
    public function isClosed(): bool
    {
        return in_array($this, [self::Hired, self::Rejected, self::Withdrawn], true);
    }

    /**
     * ⚠ The two that must carry a reason. A rejected pile with no reasons is a
     * list of names nobody can explain, and the same person is invited back
     * next year.
     */
    public function needsReason(): bool
    {
        return in_array($this, [self::Rejected, self::Withdrawn], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
