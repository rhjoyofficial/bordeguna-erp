<?php

namespace App\Modules\Foundation\Support;

use App\Modules\Foundation\Models\Business;
use Throwable;

/**
 * Whether this business has the assistant switched on.
 *
 * ⚠⚠ THIS IS HALF OF THE ANSWER, NEVER THE WHOLE ONE. Availability is the
 * admin's switch; permission is the second half, and both callers check both.
 * A class named "availability" is easy to mistake for authorisation - it is not
 * one, and on its own it would let anybody in a business with the switch on
 * spend money on the API key.
 *
 * ⚠ Called from the root layout's shared props on every page, so like
 * DocumentFont it can never throw and never assume a session: it answers false
 * and moves on. A feature toggle that could 500 the login page would be a
 * remarkably expensive way to hide a button.
 */
final class AssistantAvailability
{
    public static function enabledForCurrentBusiness(): bool
    {
        try {
            $id = app(BusinessContext::class)->id();

            if ($id === null) {
                return false;
            }

            // ⚠ withoutGlobalScopes, for the same reason BusinessIdentity uses
            // it: the business is resolved BY ID from the request's own context
            // rather than filtered by the tenant scope that context sets up.
            return (bool) Business::withoutGlobalScopes()->find($id)?->ai_assistant_enabled;
        } catch (Throwable) {
            // No session, no business, no database: all of them mean nobody has
            // switched this on, which is the safe answer for a metered feature.
            return false;
        }
    }
}
