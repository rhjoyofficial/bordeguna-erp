<?php

namespace App\Modules\Crm\Enums;

/**
 * How an interaction happened.
 *
 * ⚠ Adding a case shatters every exhaustive match over this enum. Grep before
 * adding one - the shared-enum seam has bitten this project before.
 */
enum ActivityType: string
{
    case Call = 'call';
    case Note = 'note';
    case Meeting = 'meeting';
    case Email = 'email';
    case Sms = 'sms';
    case WhatsApp = 'whatsapp';
    case Visit = 'visit';

    public function label(): string
    {
        return match ($this) {
            self::Call => 'Call',
            self::Note => 'Note',
            self::Meeting => 'Meeting',
            self::Email => 'Email',
            self::Sms => 'SMS',
            self::WhatsApp => 'WhatsApp',
            self::Visit => 'Visit',
        };
    }

    /** ⚠ A note is somebody writing something down; it has no direction. */
    public function hasDirection(): bool
    {
        return $this !== self::Note;
    }

    /** Only a call is measured in seconds. */
    public function hasDuration(): bool
    {
        return $this === self::Call;
    }

    /** @return list<self> */
    public static function selectable(): array
    {
        return self::cases();
    }
}
