<?php

namespace App\Http\Middleware;

use App\Modules\Foundation\Support\DocumentRenderToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the headless browser fetch one document, as the person who asked for it.
 *
 * ⚠⚠ THIS IS AN AUTHENTICATION PATH IN THE WEB GROUP, so it is written to do
 * nothing at all unless a `render_token` is present, and to refuse rather than
 * fall back on anything if one is present and wrong. There is no "close enough".
 *
 * ⚠ IT DOES NOT GRANT ACCESS TO THE DOCUMENT. It only says who is asking. The
 * route's own permission middleware still runs and still decides - so a token
 * cannot reach a page its owner could not have opened themselves. That is the
 * whole reason the PDF renders the real page rather than a copy of it.
 *
 * ⚠ Registered at the HEAD of the web group's appended middleware, after the
 * session has started (it needs to put the business there for
 * ResolveCurrentBusiness to find) and before EnsureUserIsActive, so a token
 * belonging to a deactivated user is rejected exactly like a live session is.
 */
class AllowDocumentRenderToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->query('render_token');

        if (! is_string($token) || $token === '') {
            return $next($request);
        }

        $claim = DocumentRenderToken::claim($token, $request->path());

        if ($claim === null) {
            // ⚠ 403 and not a redirect to the login page. A browser sent to a
            // login form would render it and the caller would receive a
            // beautifully typeset PDF of a sign-in screen.
            abort(403, 'This render link is not valid, has already been used, or was minted for a different document.');
        }

        Auth::onceUsingId($claim['user_id']);

        if ($claim['business_id'] !== null) {
            // ResolveCurrentBusiness reads the session, so the business travels
            // with the token rather than being guessed from the user's default -
            // somebody who works across two companies must get the one they were
            // looking at, not the one that sorts first.
            $request->session()->put('current_business_id', $claim['business_id']);
        }

        $response = $next($request);

        DocumentRenderToken::recordOutcome($token, $response->getStatusCode());

        return $response;
    }
}
