<?php

namespace App\Modules\Assets\Http\Middleware;

use App\Modules\Assets\Models\AssetCredential;
use App\Modules\Assets\Support\CredentialAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A refused reveal is a log line too (review of 13 October 2026, ruling 11):
 * "X was refused Windows login of BDG-IT-0001 ThinkPad (not the current
 * holder)". Somebody trying door after door is a pattern only the log can
 * show, and the pattern is invisible if only the successes are written.
 *
 * ⚠ Wrapped AROUND `password.confirm` and the controller, so the 423 (no
 * fresh password) is seen here as well as the controller's 403; the reason
 * for a 403 is what the controller left in the request attributes. A 429 is
 * never seen: the framework's middleware priority runs the throttles before
 * this, and a throttle firing ten times a minute would be the noise anyway.
 *
 * ⚠ Never the secret: the line names the person, the login's label and its
 * owner, and the reason. Nothing else exists here to leak.
 */
class AuditRefusedReveal
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! in_array($response->getStatusCode(), [403, 423], true)) {
            return $response;
        }

        $user = $request->user();
        $credential = $request->route('credential');

        if ($user === null || ! $credential instanceof AssetCredential) {
            return $response;
        }

        $reason = $response->getStatusCode() === 423
            ? 'password not confirmed'
            : (string) ($request->attributes->get('reveal_denial') ?: 'not allowed');

        // ⚠ Without the business scope: the owner is named in the line even
        // when the request came through another business's context.
        $owner = $credential->credentialable()->withoutGlobalScopes()->first();

        $log = activity('assets.secrets')->causedBy($user);

        if ($owner !== null) {
            $log->performedOn($owner);
        }

        $log->log($user->name.' was refused '.$credential->label.' of '.($owner !== null ? CredentialAccess::ownerLabel($owner) : 'a removed owner').' ('.$reason.')');

        return $response;
    }
}
