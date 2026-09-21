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

    /**
     * ⚠⚠ NOT "BUSY", AND NOT "TRY AGAIN". A provider account with no credit, or
     * one that reached its spend limit, refuses every message until a person
     * adds money or raises the limit; waiting a minute changes nothing. Until
     * 21 Sep 2026 this read as "busy" on OpenAI (a 429 is also its billing
     * answer) and as "temporarily unavailable" on Anthropic (a 400), so the
     * panel asked people to retry something no retry could fix.
     */
    public static function noCredit(): self
    {
        return new self('The AI provider account has no credit left, or it has reached its spending limit. An Owner can add credit or raise the limit on the provider billing page; the key itself is fine.');
    }

    public static function unreachable(): self
    {
        return new self('The assistant is temporarily unavailable. Please try again in a minute.');
    }
}
