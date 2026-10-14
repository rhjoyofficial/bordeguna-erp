<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The four browser security headers, on every response (audit #26, 14 Oct 2026).
 *
 * ⚠⚠ WHY HERE AND NOT deploy/nginx.conf. Traefik terminates TLS in front of
 * the container nginx, which only ever sees plain HTTP: HSTS sent from there
 * would describe a connection it does not have, and an `add_header` in the
 * server block is silently dropped by any `location` that has its own
 * `add_header`. One global middleware reaches every page, download, JSON
 * answer and error page the application produces.
 *
 *  - X-Frame-Options SAMEORIGIN: no other site can frame an ERP page (click-
 *    jacking an Approve button). Our OWN frames still work: the PDF and file
 *    previews are same-origin. The YouTube players in the Relax zone are
 *    frames of youtube-nocookie.com INSIDE our page, which this header does
 *    not govern at all (it only says who may frame US).
 *  - X-Content-Type-Options nosniff: an uploaded file is never re-read by the
 *    browser as script or HTML because its bytes looked like it.
 *  - Referrer-Policy strict-origin-when-cross-origin: another site sees
 *    "erp.bordeguna.com", never the path (which carries record ids).
 *  - Strict-Transport-Security, one year, ONLY on a secure request (Traefik's
 *    X-Forwarded-Proto, trusted in bootstrap/app.php): over plain HTTP the
 *    header is meaningless, and local http://*.test sites must not be pinned.
 *    No includeSubDomains: the shops and n8n live under other names and are
 *    not this application's to pin.
 *
 * ⚠ A header a response already carries is left alone, so a route that needs
 * something stricter (the sandboxed attachment and popup previews) keeps it.
 */
class SecurityHeaders
{
    private const HEADERS = [
        'X-Frame-Options' => 'SAMEORIGIN',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        foreach (self::HEADERS as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        if ($request->isSecure() && ! $response->headers->has('Strict-Transport-Security')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
