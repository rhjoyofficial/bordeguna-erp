<?php

namespace App\Modules\Crm\Messaging;

/**
 * What happened to one message.
 *
 * ⚠⚠ `accepted` IS NOT `delivered`. It means the provider took the message.
 * Whether it reached a handset is a separate fact that only arrives if the
 * provider sends a receipt, and several Bangladeshi providers do not. A system
 * that wrote "delivered" on acceptance would be lying on every one of them.
 */
final class SendResult
{
    private function __construct(
        public readonly bool $accepted,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $error = null,
    ) {}

    public static function accepted(?string $providerMessageId = null): self
    {
        return new self(true, $providerMessageId);
    }

    public static function failed(string $error): self
    {
        return new self(false, null, $error);
    }
}
