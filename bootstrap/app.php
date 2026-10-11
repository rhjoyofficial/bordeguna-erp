<?php

use App\Http\Middleware\AllowDocumentRenderToken;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use App\Modules\Commerce\Http\Middleware\ResolveStorefront;
use App\Modules\Foundation\Exceptions\ApprovalAlreadyDecidedException;
use App\Modules\Foundation\Http\Middleware\EnsurePageBusinessMatches;
use App\Modules\Foundation\Http\Middleware\PreventDuplicateSubmission;
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

        // ⚠⚠ THE PAYMENT GATEWAY POSTS BACK FROM ITS OWN PAGES, WITH NO CSRF TOKEN
        // AND NO SESSION COOKIE (SSLCommerz slice P2c). Without this every
        // callback is a 419 and a customer who has paid lands on an error page.
        // ⚠ And no test can see it: the framework skips CSRF checks while
        // running tests, which is why GatewayCallbackTest asserts this list
        // directly. Safe because nothing on these routes is believed until the
        // gateway's signature and Validation API agree.
        $middleware->validateCsrfTokens(except: [
            'payments/sslcommerz/*',
            // Courier webhooks sit outside the web group already; listed so a
            // later move into it cannot quietly 419 every courier call.
            'webhooks/couriers/*',
        ]);

        $middleware->web(append: [
            // ⚠⚠ FIRST IN THIS LIST AND THAT IS LOAD BEARING. It runs after the
            // session has started, because it puts the business there for
            // ResolveCurrentBusiness to find, and BEFORE EnsureUserIsActive
            // below, so a render token belonging to a deactivated user is
            // refused exactly as that user's own session would be. It does
            // nothing at all unless a `render_token` is on the query string.
            // ⚠⚠ FIRST OF ALL. It decides whether this hostname is a shop,
            // and on a shop the ERP does not exist. Running it after
            // anything that assumes an authenticated back office would
            // let that thing act on a stranger's request.
            ResolveStorefront::class,
            AllowDocumentRenderToken::class,
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
            // Refuses an identical write from the same user while the first is
            // still in flight - the server-side twin of the app.tsx double-submit
            // guard, for the double-tap that a slow network or a second tab slips
            // past the browser. Global for the same reason as W3 above.
            PreventDuplicateSubmission::class,
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
            // ⚠⚠ BEFORE AUTHENTICATION, and this list is what decides that -
            // not the order it was appended to the web group. Without the
            // entry here `auth` ran first and redirected a customer on the
            // shop's own hostname to the ERP's login form. Second time this
            // exact trap has been hit today.
            ResolveStorefront::class,
            // ⚠⚠ AFTER THE SESSION, BEFORE AUTHENTICATION, and it must be named
            // HERE rather than only in the web group. Middleware missing from
            // this list keeps its own position while prioritised middleware jump
            // ahead of it - so `auth` ran first, saw a guest, and redirected
            // every PDF render to the login page. Nothing about appending it to
            // the group first had any effect at all.
            AllowDocumentRenderToken::class,
            AuthenticatesRequests::class,
            ResolveCurrentBusiness::class,
            // W3: must run AFTER the business is resolved, or it compares the
            // page's stamp against nothing and lets every write through.
            EnsurePageBusinessMatches::class,
            // After authentication (it keys on the user) and next to W3, its
            // sibling global write-guard.
            PreventDuplicateSubmission::class,
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
