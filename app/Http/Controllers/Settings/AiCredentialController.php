<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\AiCredential;
use App\Modules\Foundation\Support\AiCredentialResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → AI providers. Where an API key is added, rotated and switched off.
 *
 * ⚠⚠ NO METHOD ON THIS CONTROLLER EVER RETURNS A SECRET. Every response is
 * built from `toSafeArray()`, which is a whitelist, so a column added to the
 * table later is invisible until somebody deliberately exposes it. The key
 * travels in one direction only: browser to server, once, at save time.
 *
 * ⚠ There is no edit form pre-filled with the current key, and that is the
 * point. Rotating means typing the new key; the old one is not shown to be
 * corrected, because a field that can display a secret is a field that can
 * leak one into a screenshot, a cache or a password manager.
 *
 * ⚠ Gated on `foundation.organization.manage`, which only Owner holds today.
 * An API key spends real money and is the credential most worth stealing in
 * the system, so it sits with the organisation settings rather than getting a
 * softer permission of its own.
 */
class AiCredentialController extends Controller
{
    /**
     * Providers the screen offers. Anthropic is the one in use; the others are
     * listed because Joy asked for "claude, anthropic, etc." and a provider
     * list that cannot grow is a list that gets edited in production.
     */
    private const PROVIDERS = [
        'anthropic' => 'Anthropic (Claude)',
        'openai' => 'OpenAI',
        'gemini' => 'Google Gemini',
        'openrouter' => 'OpenRouter',
    ];

    public function index(AiCredentialResolver $resolver): Response
    {
        $this->authorize('foundation.organization.manage');

        return Inertia::render('settings/ai-providers', [
            'credentials' => AiCredential::with('creator')
                ->orderBy('provider')
                ->orderByDesc('id')
                ->get()
                ->map(fn (AiCredential $c) => $c->toSafeArray())
                ->values(),
            'providers' => self::PROVIDERS,
            // ⚠ A boolean, never the key. This is what tells the screen the
            // assistant will work, without the screen learning why.
            'envFallback' => [
                'anthropic' => $resolver->isConfigured('anthropic'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('foundation.organization.manage');

        $data = $request->validate([
            'provider' => ['required', 'string', 'in:' . implode(',', array_keys(self::PROVIDERS))],
            'label' => ['nullable', 'string', 'max:120'],
            'model' => ['nullable', 'string', 'max:120'],
            'secret' => ['required', 'string', 'min:16', 'max:400'],
        ]);

        // ⚠⚠ Superseding rather than overwriting: the previous key for this
        // provider is switched off, not deleted, so a rotation leaves a record
        // that one happened and who did it. The secret in the old row stays
        // encrypted and unreadable either way.
        AiCredential::query()
            ->where('provider', $data['provider'])
            ->update(['is_active' => false]);

        $secret = trim($data['secret']);

        AiCredential::create([
            'provider' => $data['provider'],
            // ⚠ `?? null` before `?:` is load-bearing. A `nullable` rule leaves
            // the key ABSENT when the field was never sent, which is not the
            // same as sent-and-empty, and `$data['model']` throws on the
            // difference. This project has been bitten by exactly this before.
            'label' => ($data['label'] ?? null) ?: null,
            'model' => ($data['model'] ?? null) ?: null,
            'secret' => $secret,
            'hint' => AiCredential::hintFor($secret),
            'is_active' => true,
            'created_by' => Auth::id(),
        ]);

        // ⚠ The flash message names the provider and the last four characters
        // only. Saying more here would put the key in a session payload.
        return back()->with('success', sprintf(
            '%s key saved, ending %s.',
            self::PROVIDERS[$data['provider']],
            AiCredential::hintFor($secret),
        ));
    }

    /**
     * Switch a credential on or off without retyping it.
     *
     * ⚠ Activating one deactivates the rest for that provider, so "which key is
     * in use" has exactly one answer at any moment.
     */
    public function toggleActive(AiCredential $credential): RedirectResponse
    {
        $this->authorize('foundation.organization.manage');

        if (! $credential->is_active) {
            AiCredential::query()
                ->where('provider', $credential->provider)
                ->update(['is_active' => false]);
        }

        $credential->update(['is_active' => ! $credential->is_active]);

        return back()->with('success', $credential->is_active ? 'Key activated.' : 'Key switched off.');
    }

    public function destroy(AiCredential $credential): RedirectResponse
    {
        $this->authorize('foundation.organization.manage');

        $credential->delete();

        return back()->with('success', 'Key removed.');
    }
}
