<?php

namespace App\Modules\Foundation\Support;

use App\Modules\Foundation\Models\AiCredential;
use Illuminate\Contracts\Encryption\DecryptException;

/**
 * The one place that turns a provider name into a usable API key.
 *
 * ⚠⚠ THE DATABASE WINS, THE ENVIRONMENT IS THE FALLBACK. A key typed into
 * Settings takes precedence over `ANTHROPIC_API_KEY`, so Joy can add or rotate
 * one without a deploy - which is the whole point of the screen. If no row
 * exists, the env var still works exactly as it did before, so an existing
 * deployment does not change behaviour by upgrading.
 *
 * ⚠ Returns null rather than throwing when nothing is configured. The caller
 * decides what an unconfigured provider means; for the assistant it is an
 * honest sentence and a 503, never a blank 500.
 *
 * ⚠⚠ NOTHING IN HERE LOGS. A resolver that reported "using key sk-ant-..." on
 * failure would defeat the encrypted column it reads from. The only facts that
 * ever leave this class are "a key exists" and the key itself, handed straight
 * to the SDK.
 *
 * ℹ Resolved per call rather than cached in a static. A key rotated in Settings
 * has to take effect on the next request, and a process-lifetime cache is the
 * exact trap this project has been bitten by before.
 */
class AiCredentialResolver
{
    /**
     * The key to use for this provider, or null if none is configured.
     */
    public function keyFor(string $provider = 'anthropic'): ?string
    {
        $row = AiCredential::query()
            ->active()
            ->where('provider', $provider)
            ->latest('id')
            ->first();

        if ($row !== null) {
            // ⚠⚠ A STORED KEY THAT CANNOT BE DECRYPTED IS NOT A KEY. The
            // encrypted column is only readable with the APP_KEY that wrote it,
            // so a restore onto a fresh server (the documented recovery drill)
            // leaves rows nobody can read. Unguarded, the DecryptException
            // travelled all the way out: Settings > AI providers answered 500,
            // which is the one screen that could have replaced the key. It is
            // treated as "nothing configured" instead, which is exactly what it
            // is, and the environment fallback below still applies.
            try {
                $secret = (string) $row->secret;
            } catch (DecryptException) {
                $secret = '';
            }

            if ($secret !== '') {
                return $secret;
            }
        }

        $fromEnv = (string) config("services.{$provider}.key");

        return $fromEnv === '' ? null : $fromEnv;
    }

    /**
     * The model to ask for, with the same database-then-environment order.
     */
    public function modelFor(string $provider = 'anthropic'): string
    {
        $row = AiCredential::query()
            ->active()
            ->where('provider', $provider)
            ->latest('id')
            ->first();

        if ($row !== null && filled($row->model)) {
            return (string) $row->model;
        }

        return (string) config("services.{$provider}.model", 'claude-opus-5');
    }

    /**
     * Whether this provider can be used at all.
     *
     * ⚠ Deliberately does not return the key. Callers that only need to decide
     * whether to show a feature must not have to hold a secret to do it.
     */
    public function isConfigured(string $provider = 'anthropic'): bool
    {
        return $this->keyFor($provider) !== null;
    }
}
