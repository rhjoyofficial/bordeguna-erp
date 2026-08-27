<?php

namespace App\Modules\Foundation\Support\Ai;

use App\Modules\Foundation\Support\AiCredentialResolver;

/**
 * Which providers exist, and which one is answering right now.
 *
 * ⚠⚠ THE ORDER OF `DRIVERS` IS THE PREFERENCE ORDER. With keys for two
 * providers saved, the first one listed here answers. That is a deliberate,
 * visible rule rather than "whichever row was saved last", because a silent
 * change of who is answering is a silent change in what the ERP costs to run.
 *
 * ⚠ A provider with no active key is not selectable, so removing a key falls
 * through to the next provider rather than breaking the panel.
 */
class AiProviders
{
    /** @var array<string, class-string<AssistantDriver>> */
    private const DRIVERS = [
        'anthropic' => AnthropicDriver::class,
        'openai' => OpenAiDriver::class,
    ];

    /**
     * Providers that have no driver yet but may still hold a key. They appear
     * on the settings screen so a key can be stored, and are skipped when
     * choosing who answers.
     *
     * @var array<string, string>
     */
    private const PLANNED = [
        'gemini' => 'Google Gemini',
        'openrouter' => 'OpenRouter',
    ];

    public function __construct(private AiCredentialResolver $resolver) {}

    public function driverFor(string $provider): ?AssistantDriver
    {
        $class = self::DRIVERS[$provider] ?? null;

        return $class === null ? null : app($class);
    }

    /**
     * The provider that will answer the next message, or null if none can.
     */
    public function activeProvider(): ?string
    {
        foreach (array_keys(self::DRIVERS) as $provider) {
            if ($this->resolver->isConfigured($provider)) {
                return $provider;
            }
        }

        return null;
    }

    public function isConfigured(): bool
    {
        return $this->activeProvider() !== null;
    }

    /**
     * Every provider the settings screen offers, as value => label.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        $providers = [];

        foreach (self::DRIVERS as $provider => $class) {
            $providers[$provider] = app($class)->label();
        }

        return $providers + self::PLANNED;
    }

    /** Whether messages can actually be sent through this provider. */
    public function isSupported(string $provider): bool
    {
        return isset(self::DRIVERS[$provider]);
    }
}
