<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Messaging\DriverRegistry;
use App\Modules\Crm\Messaging\EndpointGuard;
use App\Modules\Crm\Messaging\HttpSmsDriver;
use App\Modules\Crm\Messaging\ProviderPresets;
use App\Modules\Crm\Models\MessageProvider;
use App\Modules\Crm\Models\MessagingSetting;
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
                    // ⚠ The wiring, in full. Endpoints and field names are not
                    // secrets, and hiding them would make a broken gateway
                    // undiagnosable from the screen that configures it.
                    'config' => $p->config ?? [],
                ]),
            'drivers' => $drivers->options(),
            // Joy, 30 Aug 2026: adding a gateway is filling in variables. These
            // are those variables already filled in for the common BD gateways.
            'presets' => ProviderPresets::all(),
            // Joy, 30 Aug 2026: the approval threshold is his to move.
            'approvalAbove' => MessagingSetting::approvalAbove(),
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

    /**
     * Move the threshold above which a send has to be approved.
     *
     * ⚠⚠ CLEARING THE FIELD DOES NOT MEAN "no approvals". It means every send
     * needs one - see MessagingSetting. A blank that read as "unlimited" would
     * be a way to switch off the spending control by deleting a number, which is
     * exactly the accident this whole module is braked against.
     */
    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'approval_above' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);

        MessagingSetting::forCurrentBusiness()->update(['approval_above' => $data['approval_above'] ?? null]);

        $above = $data['approval_above'] ?? null;

        return back()->with('success', $above === null
            ? 'Cleared. Every send now needs approving, whatever its size.'
            : "Sends to more than {$above} people will now need approving.");
    }

    /**
     * Send ONE real message and show exactly what came back.
     *
     * ⚠⚠ THIS SPENDS MONEY. It is a real send to a real handset through a real
     * account, and the screen says so before the button is pressed. It exists
     * because the alternative is finding out during a campaign to five hundred
     * people, which costs five hundred times as much to discover.
     *
     * ⚠⚠ IT PRINTS THE GATEWAY'S RAW REPLY BACK, and that is the point. A preset
     * marked `partial` cannot know how that gateway signals refusal; one look at
     * the real answer turns guesswork into a success rule typed into one field.
     * The request is shown too, with every secret VALUE replaced - see
     * HttpSmsDriver::redact().
     *
     * ⚠ It works on an INACTIVE provider deliberately. Testing before switching
     * on is the entire sequence being encouraged.
     */
    public function test(Request $request, MessageProvider $provider, HttpSmsDriver $driver): RedirectResponse
    {
        $data = $request->validate([
            'to' => ['required', 'string', 'max:32'],
            'body' => ['required', 'string', 'max:600'],
        ]);

        if ($provider->driver !== 'http_sms') {
            return back()->withErrors(['test' => 'Only an HTTP SMS gateway can be tested from here.']);
        }

        $attempt = $driver->attempt($provider, $data['to'], $data['body']);

        return back()->with('test_result', [
            'provider_id' => $provider->id,
            'accepted' => $attempt['accepted'],
            'status' => $attempt['status'],
            'body' => $attempt['body'],
            'error' => $attempt['error'],
            'message_id' => $attempt['message_id'],
            'sent' => $attempt['sent'],
        ]);
    }

    /**
     * What the gateway says is left in the account.
     *
     * ⚠ INFORMATION ONLY. Nothing is posted to the ledger from it: the real
     * bill arrives monthly and is entered as an expense voucher, exactly like
     * the hosting bill. A balance read off an API is not an accounting fact.
     */
    public function balance(MessageProvider $provider, HttpSmsDriver $driver): RedirectResponse
    {
        if ($provider->driver !== 'http_sms') {
            return back()->withErrors(['test' => 'Only an HTTP SMS gateway can report a balance.']);
        }

        $result = $driver->balance($provider);

        return back()->with('test_result', [
            'provider_id' => $provider->id,
            'accepted' => $result['ok'] && $result['balance'] !== null,
            'status' => null,
            'body' => $result['body'],
            'error' => $result['error'],
            'message_id' => null,
            'balance' => $result['balance'],
            'sent' => [],
        ]);
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

            // ⚠⚠ The wiring for the configurable HTTP driver. Validated loosely
            // on purpose: the whole point is that a gateway nobody has heard of
            // can be described here, and a strict allow-list of field names
            // would be the code change this exists to avoid.
            'config' => ['array'],
            'config.endpoint' => ['nullable', 'string', 'max:500'],
            'config.method' => ['nullable', Rule::in(['get', 'post_form', 'post_json'])],
            'config.auth' => ['nullable', Rule::in(['params', 'basic', 'bearer', 'header'])],
            'config.recipient_field' => ['nullable', 'string', 'max:80'],
            'config.message_field' => ['nullable', 'string', 'max:80'],
            'config.sender_field' => ['nullable', 'string', 'max:80'],
            'config.number_format' => ['nullable', Rule::in(['msisdn88', 'local0', 'plus88', 'raw'])],
            'config.credential_map' => ['array'],
            'config.extra_params' => ['array'],
            'config.unicode_params' => ['array'],
            'config.success' => ['array'],
            'config.success.mode' => ['nullable', Rule::in(['http_ok', 'json_in', 'body_contains'])],
            'config.success.path' => ['nullable', 'string', 'max:120'],
            'config.success.values' => ['array'],
            'config.error_path' => ['nullable', 'string', 'max:120'],
            'config.message_id_path' => ['nullable', 'string', 'max:120'],
            'config.balance_endpoint' => ['nullable', 'string', 'max:500'],
            'config.balance_path' => ['nullable', 'string', 'max:120'],
        ]);

        // ⚠⚠ THE ENDPOINT IS CHECKED AT SAVE, not only when it fires. A setting
        // that is only validated at send time is one nobody knows is wrong until
        // a campaign is already half out. See EndpointGuard for what it refuses
        // and why a typed URL is a request forger otherwise.
        foreach (['endpoint', 'balance_endpoint'] as $field) {
            $url = $data['config'][$field] ?? '';

            if ($url !== '' && $url !== null && ($reason = EndpointGuard::reject($url))) {
                abort(422, $reason);
            }
        }

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
