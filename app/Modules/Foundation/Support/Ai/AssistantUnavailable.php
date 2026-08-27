<?php

namespace App\Modules\Foundation\Support\Ai;

use RuntimeException;

/**
 * The provider could not answer, expressed as a sentence a user can read.
 *
 * ⚠⚠ THE MESSAGE IS SHOWN TO THE USER, so nothing that identifies a key may
 * ever be put in it. Drivers translate their own exceptions into this one and
 * are responsible for keeping the wording free of credentials, headers and
 * request bodies. The module error-surface rule, applied to the one screen in
 * this system that talks back.
 */
class AssistantUnavailable extends RuntimeException
{
    public static function badModel(string $model): self
    {
        return new self(sprintf(
            'The chosen model (%s) was rejected by the provider. An Owner can pick a different one under Settings, AI providers.',
            $model,
        ));
    }

    public static function badKey(): self
    {
        return new self('The provider rejected the API key. An Owner can replace it under Settings, AI providers.');
    }

    public static function rateLimited(): self
    {
        return new self('The assistant is busy right now. Please try again in a minute.');
    }

    public static function unreachable(): self
    {
        return new self('The assistant is temporarily unavailable. Please try again in a minute.');
    }
}
