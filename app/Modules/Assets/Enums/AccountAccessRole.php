<?php

namespace App\Modules\Assets\Enums;

/**
 * What an employee may do inside an account the business owns (slice 4):
 * administer it, or use it. Recorded, never enforced here - the provider
 * enforces it; this is the register of who was given what.
 */
enum AccountAccessRole: string
{
    case Admin = 'admin';
    case User = 'user';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::User => 'User',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
