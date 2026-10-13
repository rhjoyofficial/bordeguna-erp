<?php

namespace App\Modules\Assets\Enums;

/**
 * What sort of login a thing or an account carries (review of 13 October
 * 2026, ruling 1): the login its holder is meant to use, or something more.
 *
 * ⚠ `admin` and `recovery` are PRIVILEGED: a shared administrator password
 * or a recovery key is never the holder's to read merely because they hold
 * the laptop. Only `assets.secrets.reveal_privileged` opens them.
 */
enum CredentialKind: string
{
    case UserLogin = 'user_login';
    case Admin = 'admin';
    case Recovery = 'recovery';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::UserLogin => 'User login',
            self::Admin => 'Administrator',
            self::Recovery => 'Recovery key',
            self::Other => 'Other',
        };
    }

    public function isPrivileged(): bool
    {
        return $this === self::Admin || $this === self::Recovery;
    }

    /**
     * @return list<array{value: string, label: string, privileged: bool}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label(), 'privileged' => $case->isPrivileged()], self::cases());
    }
}
