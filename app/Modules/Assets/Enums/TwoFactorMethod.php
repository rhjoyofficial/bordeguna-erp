<?php

namespace App\Modules\Assets\Enums;

/**
 * How an account's second factor works (review of 13 October 2026, ruling
 * 2). The employee column beside it is the CUSTODIAN of that factor; the
 * method says what they hold. ⛔ Seeds and recovery codes are not notes.
 */
enum TwoFactorMethod: string
{
    case None = 'none';
    case Authenticator = 'authenticator';
    case Sms = 'sms';
    case Email = 'email';
    case SecurityKey = 'security_key';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::None => 'None',
            self::Authenticator => 'Authenticator app',
            self::Sms => 'SMS to a phone',
            self::Email => 'Email code',
            self::SecurityKey => 'Security key',
            self::Other => 'Other',
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
