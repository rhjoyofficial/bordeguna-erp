<?php

namespace App\Modules\Hrm\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ 27 Sep 2026 (Joy): "when any employee gets separated, deactivate their
 * status and remove login access, and the necessary things."
 *
 * Removing access is three acts, not one:
 *   1. `is_active = false` — the standing decision. EnsureUserIsActive reads it
 *      fresh on every request, so from here the account is refused at every
 *      door, login and remember-me alike.
 *   2. Delete their live sessions — so it is instant, not "on their next
 *      request". A person handed their notice should not keep a working tab
 *      open until they happen to click something (the 17 Aug 2026 finding that
 *      a deactivated user held ten live sessions is exactly this gap).
 *   3. Revoke their API tokens — a personal access token authenticates without
 *      a session and would otherwise outlive both of the above.
 *
 * Idempotent and safe to call on an already-revoked account: a second run
 * flips nothing and deletes nothing that is not there. Used by both
 * SeparateEmployee (a past- or same-day leaving date) and
 * hrm:disable-due-separations (a future date, the morning after it passes), so
 * the account ends up in one known-inert state whichever path reaches it.
 */
final class RevokeErpAccess
{
    public static function for(?User $user): void
    {
        if ($user === null) {
            return;
        }

        if ($user->is_active) {
            $user->update(['is_active' => false]);
        }

        // Instant logout everywhere: the session guard is database-backed, so a
        // deleted row is a signed-out browser, not one waiting to be caught.
        DB::table('sessions')->where('user_id', $user->id)->delete();

        // Sanctum tokens authenticate with no session at all; leaving them would
        // be a quiet way back in.
        $user->tokens()->delete();
    }
}
