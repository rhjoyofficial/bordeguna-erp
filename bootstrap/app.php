<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use App\Modules\Foundation\Exceptions\ApprovalAlreadyDecidedException;
use App\Modules\Foundation\Http\Middleware\EnsurePageBusinessMatches;
use App\Modules\Foundation\Http\Middleware\ResolveCurrentBusiness;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // HQ read-first API (BordeGuna-HeadQuarter docs/35): token auth, business taken from the token.
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // ⚠⚠ WITHOUT THIS, EVERY PAGE IS BLANK IN PRODUCTION.
        //
        // Traefik terminates TLS and forwards plain HTTP to the `web` container
        // with `X-Forwarded-Proto: https`. Laravel ignores that header unless
        // the proxy is trusted, so it builds asset URLs with the `http` scheme
        // — on a page served over `https`. The browser then blocks every script
        // as mixed content and renders nothing at all: no console error, no
        // failed request in the server log, just white.
        //
        // ⚠ A `TRUSTED_PROXIES` entry in `.env` does NOTHING on Laravel 11/12.
        // It is a leftover convention from the old `TrustProxies` middleware
        // class; the framework now reads only what is configured here. That
        // assumption is exactly how this shipped.
        //
        // `at: '*'` trusts any proxy, which is safe HERE and for a specific
        // reason: the container publishes no ports and sits on a private bridge
        // with nothing else on it, so Traefik is the only thing that can reach
        // it. ⚠ If a port is ever published, or another service joins that
        // network, this must be narrowed to the proxy's address — a spoofable
        // `X-Forwarded-For` becomes a real problem the moment anything else can
        // connect.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->web(append: [
            // C-SEC-1. A deactivated user is rejected on every request, not
            // only at login - a session must not outlive the decision to
            // revoke it. Global for the same reason as the W3 guard below.
            EnsureUserIsActive::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            // W3. Refuses a mutating request whose PAGE was built for a
            // different business than the session now holds. Global rather than
            // per-route on purpose: a guard somebody has to remember to add to a
            // new route is a guard that will be missing from exactly the route
            // nobody thought about. It no-ops when no business is resolved.
            EnsurePageBusinessMatches::class,
        ]);

        $middleware->alias([
            'business' => ResolveCurrentBusiness::class,
        ]);

        // ResolveCurrentBusiness must run before SubstituteBindings, or route
        // model binding on any business-scoped model (e.g. GET .../{purchaseOrder})
        // resolves with no tenant context set yet — BusinessScope then forces
        // "1=0" and every such route 404s, even for a record that legitimately
        // exists. Custom middleware aliases are otherwise unordered relative to
        // framework middleware, so this must be explicit.
        $middleware->priority([
            HandlePrecognitiveRequests::class,
            EncryptCookies::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            AuthenticatesRequests::class,
            ResolveCurrentBusiness::class,
            // W3: must run AFTER the business is resolved, or it compares the
            // page's stamp against nothing and lets every write through.
            EnsurePageBusinessMatches::class,
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
            AuthenticatesSessions::class,
            SubstituteBindings::class,
            Authorize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Two approvers, one request. The loser of the race gets a sentence
        // telling them what happened and where it stands, not a blank 500.
        //
        // ⚠ Handled centrally rather than in each controller because approvals
        // are raised from a dozen places (leave, loans, corrections, movements,
        // bonuses, stock requests, purchase orders, change requests) and a
        // guard that only some of them catch is a guard with holes in it.
        $exceptions->render(function (ApprovalAlreadyDecidedException $e, $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 409);
            }

            return back()->with('error', $e->getMessage());
        });
    })->create();
