<?php

/**
 * ⚠⚠ Production rendered a completely BLANK page on 2026-07-29 because Laravel
 * did not trust Traefik's X-Forwarded-Proto header.
 *
 * It built asset URLs with the `http` scheme on a page served over `https`, and
 * the browser silently blocked every script as mixed content. There was no
 * console error, no failed request in the server log, and curl returned a
 * perfectly good 200 with 49KB of HTML — just white in a real browser.
 *
 * `TRUSTED_PROXIES=*` had been set in `.env` and **does nothing on Laravel
 * 11/12**: it is a leftover convention from the old `TrustProxies` middleware
 * class. The real configuration is `trustProxies()` in `bootstrap/app.php`.
 *
 * These tests exist so nobody removes it and rediscovers that the hard way.
 *
 * ⚠ They assert on URL GENERATION rather than on page content. Locally, Vite
 * may be in dev mode and emit `http://[::1]:5173/...` script tags from a
 * leftover `public/hot` file — an artifact of the developer's machine that has
 * nothing to do with the bug, and asserting on markup would fail there while
 * passing in the environment that actually broke.
 */
it('trusts the reverse proxy and treats a forwarded request as secure', function () {
    $this->withServerVariables([
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
    ])->get('/login')->assertOk();

    expect(request()->isSecure())->toBeTrue();
});

it('generates https asset and route URLs behind the proxy', function () {
    $this->withServerVariables([
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_HOST' => 'erp.example.test',
    ])->get('/login')->assertOk();

    // The failure in one line: an https page must not link http assets.
    expect(asset('build/assets/app.css'))->toStartWith('https://');
    expect(url('/login'))->toStartWith('https://');
});

it('does not treat a plain request as secure', function () {
    // Trusting the proxy must not mean assuming https unconditionally — the
    // forwarded header is the only thing that should decide it.
    $this->get('/login')->assertOk();

    expect(request()->isSecure())->toBeFalse();
});
