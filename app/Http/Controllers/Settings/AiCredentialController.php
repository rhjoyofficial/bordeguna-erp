<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\AiCredential;
use App\Modules\Foundation\Support\Ai\AiProviders;
use App\Modules\Foundation\Support\Ai\AssistantUnavailable;
use App\Modules\Foundation\Support\AiCredentialResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
    public function index(AiCredentialResolver $resolver, AiProviders $providers): Response
    {
        $this->authorize('foundation.organization.manage');

        $configured = [];
        $models = [];

        foreach (array_keys($providers->all()) as $provider) {
            $configured[$provider] = $resolver->isConfigured($provider);

            // ⚠⚠ THE MODEL LIST IS ASKED OF THE PROVIDER, not hard-coded here.
            // A free-text model field is how `claude-haiku-4.5` came to be
            // stored: the id has a dot where it needs a hyphen, every message
            // came back 404, and the panel reported "temporarily unavailable"
            // for four days. An Owner now picks a name from a list; nobody
            // types an id.
            $driver = $providers->driverFor($provider);
            $key = $driver === null ? null : $resolver->keyFor($provider);
            $models[$provider] = $key === null ? [] : $driver->models($key);
        }

        return Inertia::render('settings/ai-providers', [
            'credentials' => AiCredential::with('creator')
                ->orderBy('provider')
                ->orderByDesc('id')
                ->get()
                ->map(fn (AiCredential $c) => $c->toSafeArray())
                ->values(),
            'providers' => $providers->all(),
            'supported' => collect($providers->all())
                ->map(fn (string $label, string $provider) => $providers->isSupported($provider))
                ->all(),
            'models' => $models,
            // The master switch, and who can currently see the button at all.
            'assistantEnabled' => (bool) $this->business()?->ai_assistant_enabled,
            // Every language on offer, and the two currently switched on.
            'languages' => collect(\App\Modules\Foundation\Support\Ai\AssistantLanguages::all())
                ->map(fn (array $l, string $code) => ['code' => $code, 'label' => $l['label'], 'native' => $l['native']])
                ->values()->all(),
            'activeLanguages' => \App\Modules\Foundation\Support\Ai\AssistantLanguages::activeFor($this->business()),
            'requiredLanguages' => \App\Modules\Foundation\Support\Ai\AssistantLanguages::REQUIRED_ACTIVE,
            'monthlyCap' => $this->business()?->ai_monthly_message_cap,
            'usedThisMonth' => \App\Modules\Foundation\Models\AssistantMessage::usedThisMonth((int) ($this->business()?->id ?? 0)),
            // ⚠ Booleans, never keys. This is what tells the screen the
            // assistant will work, without the screen learning why.
            'envFallback' => $configured,
            'activeProvider' => $providers->activeProvider(),
        ]);
    }

    /**
     * The chat toggle's two languages, and the monthly ceiling.
     *
     * ⚠⚠ EXACTLY TWO, REFUSED OTHERWISE. A toggle is a two-position control:
     * one language makes it pointless, three makes it a different control that
     * has to be designed. The rule is enforced here and in AssistantLanguages
     * rather than left to the screen, because the screen is not the only caller.
     */
    public function updatePreferences(Request $request): RedirectResponse
    {
        $this->authorize('foundation.organization.manage');

        $data = $request->validate([
            'languages' => ['required', 'array', 'size:'.\App\Modules\Foundation\Support\Ai\AssistantLanguages::REQUIRED_ACTIVE],
            'languages.*' => ['required', 'string', Rule::in(\App\Modules\Foundation\Support\Ai\AssistantLanguages::codes())],
            // ⚠ NULL means no ceiling, which is honest for "nobody has set one".
            // The assistant is off by default anyway, so this is a second brake
            // rather than the only one.
            'monthly_cap' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);

        // ⚠ The same language twice is two values that are one choice, and the
        // toggle would render two identical buttons.
        if (count(array_unique($data['languages'])) !== count($data['languages'])) {
            return back()->withErrors(['languages' => 'Choose two different languages.']);
        }

        $this->business()->update([
            'assistant_languages' => array_values($data['languages']),
            'ai_monthly_message_cap' => $data['monthly_cap'] ?? null,
        ]);

        return back()->with('success', 'Assistant preferences saved.');
    }

    /** The business in context, resolved by id rather than through the scope. */
    private function business(): ?\App\Modules\Foundation\Models\Business
    {
        return \App\Modules\Foundation\Models\Business::withoutGlobalScopes()
            ->find(app(\App\Modules\Foundation\Support\BusinessContext::class)->id());
    }

    /**
     * Switch the floating assistant on or off for this business.
     *
     * ⚠⚠ OFF MEANS NOBODY, including an Owner holding every permission: the
     * button is not rendered and the endpoint refuses. ON means only people
     * granted `foundation.ai.use` - the switch opens the door, the permission
     * says who walks through it.
     *
     * ⚠ Deliberately separate from storing a key. A key can be added and
     * verified while the feature stays off, which is exactly the order somebody
     * setting this up for the first time wants.
     */
    public function toggleAssistant(Request $request): RedirectResponse
    {
        $this->authorize('foundation.organization.manage');

        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        $this->business()->update(['ai_assistant_enabled' => $data['enabled']]);

        return back()->with('success', $data['enabled']
            ? 'The assistant is on. Only people with the AI assistant permission will see it.'
            : 'The assistant is off. The button is hidden for everybody.');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('foundation.organization.manage');

        $data = $request->validate([
            'provider' => ['required', 'string', 'in:' . implode(',', array_keys(app(AiProviders::class)->all()))],
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
            app(AiProviders::class)->all()[$data['provider']],
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

    /**
     * Change which model a stored key uses, without retyping the key.
     *
     * ⚠⚠ THIS EXISTS SO A WRONG MODEL IS NOT A REASON TO HANDLE A SECRET.
     * Before it, the only way to correct a model was to save the credential
     * again, which meant pasting the API key back in - turning a one-character
     * typo into a fresh chance to leak the key into a clipboard or a chat.
     */
    public function updateModel(Request $request, AiCredential $credential, AiProviders $providers): RedirectResponse
    {
        $this->authorize('foundation.organization.manage');

        $data = $request->validate([
            'model' => ['nullable', 'string', 'max:120'],
        ]);

        $model = ($data['model'] ?? null) ?: null;

        $credential->update([
            'model' => $model,
            // The stored verification refers to the old model, so it is no
            // longer evidence of anything. Cleared rather than left to
            // reassure somebody about a combination nobody has tested.
            'verified_at' => null,
            'verify_error' => null,
        ]);

        return back()->with('success', $model === null
            ? 'Model cleared; the provider default will be used.'
            : sprintf('Model set to %s. Press Test to confirm it answers.', $model));
    }

    /**
     * Send one real message to the provider and record what came back.
     *
     * ⚠⚠ THIS IS THE CHECK NOTHING PERFORMED BEFORE. A key and a model were
     * accepted, stored, and only found to be wrong when a user asked the
     * assistant a question and got "temporarily unavailable". The columns to
     * hold the answer (`verified_at`, `verify_error`) existed from the first
     * migration and nothing ever wrote them.
     *
     * ⚠ It costs a few tokens on the Owner's own account, deliberately: a test
     * that does not actually call the provider proves nothing.
     */
    public function verify(AiCredential $credential, AiProviders $providers, AiCredentialResolver $resolver): RedirectResponse
    {
        $this->authorize('foundation.organization.manage');

        $driver = $providers->driverFor($credential->provider);

        if ($driver === null) {
            return back()->with('error', 'There is no driver for this provider yet, so its key cannot be tested.');
        }

        if (! $credential->is_active) {
            return back()->with('error', 'Switch this key on before testing it.');
        }

        $key = $resolver->keyFor($credential->provider);

        if ($key === null) {
            return back()->with('error', 'This key cannot be read. It was saved by a different installation and must be replaced.');
        }

        $model = $credential->model ?: $driver->defaultModel();

        try {
            $driver->chat($key, $model, [['type' => 'text', 'text' => 'Reply with the single word: ready.']], [
                ['role' => 'user', 'content' => 'ready?'],
            ]);
        } catch (AssistantUnavailable $e) {
            $credential->update(['verified_at' => null, 'verify_error' => $e->getMessage()]);

            return back()->with('error', $e->getMessage());
        }

        $credential->update(['verified_at' => now(), 'verify_error' => null]);

        return back()->with('success', sprintf('Answered on %s. The assistant is working.', $model));
    }

    public function destroy(AiCredential $credential): RedirectResponse
    {
        $this->authorize('foundation.organization.manage');

        $credential->delete();

        return back()->with('success', 'Key removed.');
    }
}
