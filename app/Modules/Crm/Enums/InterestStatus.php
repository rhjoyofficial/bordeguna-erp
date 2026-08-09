<?php

namespace App\Modules\Crm\Enums;

/**
 * ⚠⚠ There is no `deleted` and no `purchased`.
 *
 * A mistaken entry is WITHDRAWN with a reason, because a demand signal that
 * quietly disappears is one nobody can audit. And whether somebody actually
 * bought the thing is answered by `sales_invoice_lines`, never by a boolean
 * kept in step by hand here.
 */
enum InterestStatus: string
{
    case Open = 'open';
    case Fulfilled = 'fulfilled';
    case Withdrawn = 'withdrawn';
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Still wants it',
            self::Fulfilled => 'Fulfilled',
            self::Withdrawn => 'No longer wanted',
            self::Unavailable => 'We could not supply it',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Open;
    }

    /** The states a person may close an interest into by hand. */
    public static function closable(): array
    {
        return [self::Fulfilled, self::Withdrawn, self::Unavailable];
    }
}
