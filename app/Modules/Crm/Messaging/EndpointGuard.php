<?php

namespace App\Modules\Crm\Messaging;

/**
 * Where a configurable endpoint is allowed to point.
 *
 * ⚠⚠ THE PRICE OF LETTING SOMEBODY TYPE A URL is that somebody can type any URL.
 * Without this, the SMS settings screen is a request forger: point it at
 * http://127.0.0.1 or at the Docker network and the server will happily call its
 * own database admin, its own metadata service or the container next door, with
 * the reply printed back on the Test screen. That is not a hypothetical - it is
 * the standard shape of the bug, and this module hands the response body to the
 * user on purpose.
 *
 * ⚠ Loopback, private ranges, link-local and anything that is not http/https are
 * refused. The refusal happens at SAVE time as well as at send time, because a
 * setting that is only checked when it fires is a setting nobody knows is wrong.
 */
final class EndpointGuard
{
    /**
     * @return string|null the reason it is not allowed, or null if it is fine
     */
    public static function reject(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return 'That is not a complete URL. It needs to start with https:// and name a host.';
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return 'An endpoint has to be http or https.';
        }

        $host = $parts['host'];

        // ⚠ A hostname is resolved before it is judged. `localtest.me` and
        // plenty of public names resolve to 127.0.0.1 on purpose, so checking
        // the spelling of the host and not its address checks nothing.
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            // Unresolvable. Not necessarily an attack - a typo, or DNS being
            // slow - so it is refused with the plain reason.
            return "The host {$host} could not be looked up.";
        }

        $public = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );

        if ($public === false) {
            return 'That address is inside this server or its private network, and an SMS gateway never is.';
        }

        return null;
    }
}
