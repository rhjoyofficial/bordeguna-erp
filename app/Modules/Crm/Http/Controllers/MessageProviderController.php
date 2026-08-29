<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Messaging\DriverRegistry;
use App\Modules\Crm\Models\MessageProvider;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings: how the business sends.
 *
 * ⚠⚠ CREDENTIALS ARE WRITE-ONLY FROM THIS SCREEN. They go in encrypted and only
 * their last four characters ever come back out. Joy types his own keys; nobody
 * else needs to read them back, and a page that could show them is a page that
 * eventually does - in a screenshot, in a support chat, on a shared monitor.
 *
 * ⚠ A provider is created INACTIVE. Switching it on is a separate, deliberate
 * act, because the moment one is active a send can spend money through it.
 */
class MessageProviderController extends Controller
{
    public function index(Request $request, DriverRegistry $drivers): Response
    {
        return Inertia::render('settings/messaging', [
            'providers' => MessageProvider::query()->orderBy('channel')->orderBy('priority')->get()
                ->map(fn (MessageProvider $p) => [
                    'id' => $p->id,
                    'channel' => $p->channel,
                    'driver' => $p->driver,
                    'name' => $p->name,
                    'sender_id' => $p->sender_id,
                    'is_active' => $p->is_active,
                    'priority' => $p->priority,
                    'cost_per_part' => $p->cost_per_part,
                    // ⚠ Masked, never the real values.
                    'credentials' => $p->maskedCredentials(),
                ]),
            'drivers' => $drivers->options(),
            // ⚠⚠ Said on the screen rather than left to be discovered: no mail
            // leaves the server while the mailer is the log driver.
            'mailer' => config('mail.default'),
        ]);
    }

    public function store(Request $request, DriverRegistry $drivers): RedirectResponse
    {
        $data = $this->rules($request, $drivers);

        MessageProvider::create(array_merge($data, [
            'business_id' => app(BusinessContext::class)->id(),
        ]));

        return back()->with('success', 'Provider saved, and left switched off until you turn it on.');
    }

    public function update(Request $request, MessageProvider $provider, DriverRegistry $drivers): RedirectResponse
    {
        $data = $this->rules($request, $drivers, $provider);

        // ⚠⚠ A BLANK CREDENTIAL FIELD MEANS "leave it alone", never "erase it".
        // The screen cannot show the existing secret, so it always arrives
        // blank unless somebody deliberately typed a new one. Treating blank as
        // an erase would wipe the key every time anybody renamed a provider.
        if (($data['credentials'] ?? []) === []) {
            unset($data['credentials']);
        }

        $provider->update($data);

        return back()->with('success', 'Provider saved.');
    }

    public function toggle(MessageProvider $provider): RedirectResponse
    {
        $provider->update(['is_active' => ! $provider->is_active]);

        return back()->with('success', $provider->is_active
            ? "{$provider->name} is on. Sends can now go through it."
            : "{$provider->name} is off.");
    }

    /**
     * @return array<string,mixed>
     */
    private function rules(Request $request, DriverRegistry $drivers, ?MessageProvider $provider = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'channel' => ['required', Rule::in(['sms', 'email'])],
            'driver' => ['required', Rule::in($drivers->keys())],
            'sender_id' => ['nullable', 'string', 'max:120'],
            'priority' => ['required', 'integer', 'min:1', 'max:1000'],
            'cost_per_part' => ['required', 'numeric', 'min:0'],
            'credentials' => ['array'],
            'credentials.*' => ['nullable', 'string', 'max:500'],
        ]);

        // ⚠ A driver belongs to one channel. Letting an email driver be filed
        // under SMS would make a send pick a provider that cannot send it, and
        // the failure would appear per recipient rather than at the settings.
        $expected = $drivers->channelFor($data['driver']);

        if ($expected !== null && $expected !== $data['channel']) {
            abort(422, "That driver sends {$expected}, not {$data['channel']}.");
        }

        $data['credentials'] = array_filter($data['credentials'] ?? [], fn ($v) => $v !== null && $v !== '');

        return $data;
    }
}
