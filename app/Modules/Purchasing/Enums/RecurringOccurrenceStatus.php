<?php

namespace App\Modules\Purchasing\Enums;

/**
 * ⚠ Three states, and "skipped" is not a failure. A month the service was not
 * taken is a decision somebody made and stated, and it must not sit in the due
 * list forever nor pretend to have been paid.
 */
enum RecurringOccurrenceStatus: string
{
    case Due = 'due';
    case Recorded = 'recorded';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Due => 'Due',
            self::Recorded => 'Recorded',
            self::Skipped => 'Skipped',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Due;
    }
}
