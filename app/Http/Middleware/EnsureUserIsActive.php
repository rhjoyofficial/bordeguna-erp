<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * C-SEC-1: a deactivated user is rejected on EVERY request, not only at login.
 *
 * ⚠⚠ Why this exists. Login has refused inactive accounts since the Team
 * screen shipped - and on 2026-08-17 a user deactivated the day before was
 * found holding ten live sessions on production and rendering permission-gated
 * pages, because nothing ever asked the question again after login. A session
 * outlives the decision to revoke it unless something checks per request.
 *
 * ⚠ Global (appended to the `web` group), not per-route - the same reasoning
 * as EnsurePageBusinessMatches: a guard somebody has to remember to add to a
 * new route is a guard that will be missing from exactly the route nobody
 * thought about. It no-ops for guests, so public pages never meet it.
 *
 * ⚠ This also closes the remember-me path: a remember cookie re-authenticates
 * WITHOUT passing through LoginRequest, but whatever resolved the user, this
 * runs after it and logs the session straight back out.
 *
 * See SECURITY-INACTIVE-SESSION-DESIGN.md.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // ⚠⚠ Read the flag FRESH, never from the resolved model. The session
        // guard caches the user instance, and a cached model carries the
        // is_active value from whenever it was loaded - under a long-lived
        // runtime (queue-adjacent code, Octane, the test kernel) that is the
        // value from LOGIN time, which is precisely the staleness this
        // middleware exists to end. One indexed single-column read per
        // authenticated request; a vanished row counts as inactive.
        $active = $user !== null
            && (bool) User::whereKey($user->getAuthIdentifier())->value('is_active');

        if ($user !== null && ! $active) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // The same sentence login uses, so the person sees one message
            // whichever door refused them.
            return redirect()->route('login')
                ->with('status', 'This account has been deactivated. Contact your administrator.');
        }

        return $next($request);
    }
}
