<?php

namespace App\Modules\Sales\Couriers\Drivers;

use App\Modules\Sales\Couriers\Models\CourierAccount;
use App\Modules\Sales\Couriers\Models\CourierApiCall;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Throwable;

/**
 * Every courier request goes through here, so every one is logged and none can
 * leave for a URL outside the provider's allow-list.
 *
 * ⚠⚠ THE LOG HOLDS THE PATH, THE STATUS, THE TIME AND A SCRUBBED ERROR. Never a
 * header, a body or a query string, and every secret value the account holds is
 * replaced before the error is written, because a provider can echo a field
 * back in its complaint.
 */
class CourierApi
{
    public const TIMEOUT_SECONDS = 15;

    /**
     * @param  Closure(string $baseUrl): Response  $send
     */
    public function call(CourierAccount $account, string $method, string $path, Closure $send): CourierCall
    {
        // ⚠ Checked again here, not only at save: a row edited by hand or
        // restored from an old dump must not post credentials anywhere else.
        if (! $account->definition()->allowsBaseUrl((string) $account->environment, (string) $account->base_url)) {
            return new CourierCall(null, 'The saved address is not an official '.$account->definition()->name().' address for '.$account->environment.'. Choose one from the list and save again.');
        }

        $started = hrtime(true);
        $response = null;
        $error = null;

        try {
            $response = $send(rtrim((string) $account->base_url, '/'));
        } catch (ConnectionException $e) {
            $error = 'Could not reach '.$account->definition()->name().': '.$e->getMessage();
        } catch (Throwable $e) {
            $error = 'The request failed: '.$e->getMessage();
        }

        $ms = (int) round((hrtime(true) - $started) / 1_000_000);

        if ($response !== null && $error === null) {
            $error = self::errorFrom($response);
        }

        $error = $error === null ? null : $this->scrub($account, $error);

        CourierApiCall::create([
            'courier_account_id' => $account->id,
            'method' => strtoupper($method),
            'endpoint' => Str::limit((string) (parse_url($path, PHP_URL_PATH) ?? $path), 250, ''),
            'http_status' => $response?->status(),
            'duration_ms' => $ms,
            'ok' => $error === null,
            'error' => $error === null ? null : Str::limit($error, 490),
        ]);

        return new CourierCall($response, $error);
    }

    /**
     * Null when the response is a success by HTTP status and by the provider's
     * own `error` flag (CarryBee answers some failures with 200 and error:true).
     */
    private static function errorFrom(Response $response): ?string
    {
        $json = $response->json();
        $message = is_array($json) ? ($json['message'] ?? null) : null;

        if ($response->successful() && ! (is_array($json) && ($json['error'] ?? false) === true)) {
            return null;
        }

        $text = is_string($message) && $message !== '' ? $message : Str::limit(trim(strip_tags($response->body())), 200);

        return match (true) {
            in_array($response->status(), [401, 403], true) => "Refused the credentials (HTTP {$response->status()}): {$text}",
            default => "HTTP {$response->status()}: {$text}",
        };
    }

    private function scrub(CourierAccount $account, string $text): string
    {
        foreach ($account->secretValues() as $secret) {
            $text = str_replace($secret, '[hidden]', $text);
        }

        return $text;
    }
}
