<?php

namespace App\Modules\Crm\Messaging;

use App\Modules\Crm\Models\MessageProvider;
use App\Modules\Crm\Support\SmsText;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * One driver for every HTTP SMS gateway, described by settings rather than code.
 *
 * Joy, 30 August 2026: "make a system so that I can integrate any provider just
 * adding the variables". Every Bangladeshi gateway worth using is the same three
 * decisions wearing different names - where to POST, what to call the number and
 * the message, and how to tell acceptance from rejection. Those three are the
 * settings below. Adding BulkSMSBD, Alpha SMS, Elitbuzz or one nobody has heard
 * of yet is filling a form, and switching between them is changing one row.
 *
 * ⚠⚠ THE SUCCESS RULE IS THE WHOLE THING. Almost every BD gateway answers HTTP
 * 200 whether it accepted the message or refused it, and puts the truth in the
 * body: `response_code: 202`, or `error: 0`, or a bare code. A driver that trusts
 * the status line reports a perfect send for a campaign that never left the
 * building, and the first anybody knows is that no customer replied. So `http_ok`
 * is the LAST resort, offered only to get a Test through the door.
 *
 * ⚠⚠ BANGLA SWITCHES ITS OWN FLAG. Several gateways need `type=unicode` for
 * non-Latin text and silently mangle it otherwise. `unicode_params` is merged in
 * whenever the body leaves the GSM alphabet, which is the same test that decides
 * the message costs 70 characters a part instead of 160.
 *
 * ⚠ CREDENTIALS ARE NEVER LOGGED. Every log line and every message shown on a
 * screen goes through redact(): the request is recorded with the secret values
 * replaced, because a failed send is exactly when somebody pastes the log
 * somewhere public.
 */
class HttpSmsDriver implements MessageDriver
{
    public function label(): string
    {
        return 'SMS gateway (HTTP) - set up in Settings';
    }

    public function send(MessageProvider $provider, string $to, string $body, ?string $subject = null): SendResult
    {
        $attempt = $this->attempt($provider, $to, $body);

        return $attempt['accepted']
            ? SendResult::accepted($attempt['message_id'])
            : SendResult::failed($attempt['error'] ?? 'The gateway did not accept the message.');
    }

    /**
     * The same call as send(), but reporting everything it saw.
     *
     * ⚠ This is what the Test button uses. It hands back the RAW reply on
     * purpose: a preset marked `partial` cannot know the gateway's success rule,
     * and the fastest way to learn it is to read what the gateway actually said
     * once. Without this, configuring an unknown provider is guesswork against a
     * live account that charges for every guess.
     *
     * @return array{accepted:bool, status:int|null, body:string, error:string|null, message_id:string|null, sent:array<string,mixed>}
     */
    public function attempt(MessageProvider $provider, string $to, string $body): array
    {
        $config = $this->config($provider);

        $blank = ['accepted' => false, 'status' => null, 'body' => '', 'message_id' => null, 'sent' => []];

        if (($config['endpoint'] ?? '') === '') {
            return $blank + ['error' => 'This provider has no endpoint set, so there is nowhere to send it.'];
        }

        if ($reason = EndpointGuard::reject($config['endpoint'])) {
            return $blank + ['error' => $reason];
        }

        $payload = $this->payload($provider, $config, $to, $body);
        $request = Http::timeout((int) ($config['timeout'] ?? 20))->acceptJson();

        $credentials = $provider->credentials ?? [];

        // Auth styles beyond "it is just another parameter". Bangladeshi
        // gateways almost all use parameters; the rest exist so an
        // international fallback does not need a second driver.
        $request = match ($config['auth'] ?? 'params') {
            'basic' => $request->withBasicAuth((string) ($credentials['username'] ?? ''), (string) ($credentials['password'] ?? '')),
            'bearer' => $request->withToken((string) ($credentials['token'] ?? '')),
            'header' => $request->withHeaders($this->credentialHeaders($config, $credentials)),
            default => $request,
        };

        try {
            $response = match ($config['method'] ?? 'post_form') {
                'get' => $request->get($config['endpoint'], $payload),
                'post_json' => $request->asJson()->post($config['endpoint'], $payload),
                default => $request->asForm()->post($config['endpoint'], $payload),
            };
        } catch (ConnectionException $e) {
            // ⚠ A network failure is NOT a rejected message and must not be
            // recorded as one silently. It is returned as a failure for this
            // recipient with the reason kept, so a whole campaign failing on a
            // dead endpoint reads as a dead endpoint and not as 500 bad numbers.
            Log::warning('SMS gateway unreachable', [
                'provider' => $provider->name,
                'endpoint' => $config['endpoint'],
                'reason' => $e->getMessage(),
            ]);

            return $blank + ['error' => 'The gateway could not be reached: '.$e->getMessage()];
        }

        $raw = $response->body();
        $json = $this->decode($raw);
        $accepted = $this->accepted($config, $response->status(), $raw, $json);

        return [
            'accepted' => $accepted,
            'status' => $response->status(),
            'body' => mb_substr($raw, 0, 2000),
            'error' => $accepted ? null : $this->errorFrom($config, $json, $raw, $response->status()),
            'message_id' => $this->pluck($json, $config['message_id_path'] ?? null),
            // ⚠ Redacted. See the class note.
            'sent' => $this->redact($payload, $credentials),
        ];
    }

    /**
     * What the account has left, when the gateway will say.
     *
     * @return array{ok:bool, balance:string|null, body:string, error:string|null}
     */
    public function balance(MessageProvider $provider): array
    {
        $config = $this->config($provider);
        $endpoint = $config['balance_endpoint'] ?? null;

        if (! $endpoint) {
            return ['ok' => false, 'balance' => null, 'body' => '', 'error' => 'This gateway has no balance endpoint set.'];
        }

        if ($reason = EndpointGuard::reject($endpoint)) {
            return ['ok' => false, 'balance' => null, 'body' => '', 'error' => $reason];
        }

        $params = [];

        foreach ($config['credential_map'] ?? [] as $secret => $wire) {
            $params[$wire] = (string) ($provider->credentials[$secret] ?? '');
        }

        try {
            $response = Http::timeout(15)->acceptJson()->get($endpoint, $params);
        } catch (ConnectionException $e) {
            return ['ok' => false, 'balance' => null, 'body' => '', 'error' => 'The gateway could not be reached.'];
        }

        $json = $this->decode($response->body());

        return [
            'ok' => $response->successful(),
            'balance' => $this->pluck($json, $config['balance_path'] ?? null),
            'body' => mb_substr($response->body(), 0, 2000),
            'error' => $response->successful() ? null : 'The gateway answered '.$response->status().'.',
        ];
    }

    /**
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     */
    private function payload(MessageProvider $provider, array $config, string $to, string $body): array
    {
        $payload = [];

        // Credentials first, so a badly chosen field name can never be
        // overwritten by a static parameter the user also typed.
        if (($config['auth'] ?? 'params') === 'params') {
            foreach ($config['credential_map'] ?? [] as $secret => $wire) {
                $payload[$wire] = (string) ($provider->credentials[$secret] ?? '');
            }
        }

        $payload[$config['recipient_field'] ?? 'to'] = $this->number($to, $config['number_format'] ?? 'msisdn88');
        $payload[$config['message_field'] ?? 'message'] = $body;

        if (($config['sender_field'] ?? null) && $provider->sender_id) {
            $payload[$config['sender_field']] = $provider->sender_id;
        }

        $payload = array_merge($payload, (array) ($config['extra_params'] ?? []));

        // ⚠⚠ See the class note: one Bangla character changes the wire format
        // for the whole message, not just its price.
        if (! SmsText::isGsm($body)) {
            $payload = array_merge($payload, (array) ($config['unicode_params'] ?? []));
        }

        return $payload;
    }

    /**
     * ⚠ Gateways disagree about what a Bangladeshi number looks like: 88017…,
     * 017… and +88017… are all "the number" somewhere. Getting this wrong does
     * not error - it silently delivers nothing.
     */
    private function number(string $to, string $format): string
    {
        $digits = preg_replace('/\D+/', '', $to) ?? '';

        // Reduce to the national 11-digit form first, whatever arrived.
        if (str_starts_with($digits, '88')) {
            $digits = substr($digits, 2);
        }

        if (! str_starts_with($digits, '0')) {
            $digits = '0'.$digits;
        }

        return match ($format) {
            'local0' => $digits,
            'plus88' => '+88'.$digits,
            'raw' => $to,
            default => '88'.$digits,
        };
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,mixed>|null  $json
     */
    private function accepted(array $config, int $status, string $raw, ?array $json): bool
    {
        $rule = (array) ($config['success'] ?? []);
        $mode = $rule['mode'] ?? 'http_ok';

        // ⚠ Every rule still requires a 2xx underneath. A gateway that answers
        // 500 with a body that happens to contain the success word has not
        // accepted anything.
        $ok = $status >= 200 && $status < 300;

        if (! $ok) {
            return false;
        }

        return match ($mode) {
            'json_in' => in_array(
                (string) $this->pluck($json, $rule['path'] ?? null),
                array_map('strval', (array) ($rule['values'] ?? [])),
                true,
            ),
            'body_contains' => (bool) array_filter(
                (array) ($rule['values'] ?? []),
                fn ($needle) => $needle !== '' && str_contains($raw, (string) $needle),
            ),
            default => true,
        };
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,mixed>|null  $json
     */
    private function errorFrom(array $config, ?array $json, string $raw, int $status): string
    {
        $message = $this->pluck($json, $config['error_path'] ?? null);

        if ($message !== null && $message !== '') {
            return $message;
        }

        // ⚠ The raw reply, trimmed, rather than "sending failed". The gateway
        // said something and whoever has to fix it needs to read it.
        $trimmed = trim(mb_substr($raw, 0, 300));

        return $trimmed === ''
            ? "The gateway answered {$status} and said nothing."
            : "The gateway answered {$status}: {$trimmed}";
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decode(string $raw): ?array
    {
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string,mixed>|null  $json
     */
    private function pluck(?array $json, ?string $path): ?string
    {
        if ($json === null || $path === null || $path === '') {
            return null;
        }

        $value = Arr::get($json, $path);

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,mixed>  $credentials
     * @return array<string,string>
     */
    private function credentialHeaders(array $config, array $credentials): array
    {
        $headers = [];

        foreach ($config['credential_map'] ?? [] as $secret => $wire) {
            $headers[$wire] = (string) ($credentials[$secret] ?? '');
        }

        return $headers;
    }

    /**
     * Replace every secret VALUE wherever it appears.
     *
     * ⚠ By value, not by field name. A gateway that wants the key in a field
     * called `u` would defeat a name-based filter, and the point of this is that
     * the field names are whatever the user typed.
     *
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $credentials
     * @return array<string,mixed>
     */
    private function redact(array $payload, array $credentials): array
    {
        $secrets = array_filter(array_map('strval', array_values($credentials)), fn ($v) => $v !== '');

        foreach ($payload as $key => $value) {
            if (is_string($value) && in_array($value, $secrets, true)) {
                $payload[$key] = '••••••';
            }
        }

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function config(MessageProvider $provider): array
    {
        return (array) ($provider->config ?? []);
    }
}
