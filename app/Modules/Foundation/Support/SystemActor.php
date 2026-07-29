<?php

namespace App\Modules\Foundation\Support;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * The identity automated work acts as (Correction C15c).
 *
 * Every audited column in this system stores a user id, and a scheduled job has
 * no `Auth::id()`. Writing null there would be the wrong answer twice over: it
 * is indistinguishable from a deleted user, and it makes "nobody knows who did
 * this" and "the system did this" look identical on every screen that resolves
 * the relation. Those are different facts and the audit trail should say so.
 *
 * ⚠⚠ **THIS ACCOUNT MUST NEVER BE ABLE TO AUTHENTICATE.** Seeding an actor must
 * not seed a way in. Three properties enforce that together, and none of them
 * is sufficient alone:
 *
 *  1. `is_active = false` — the login path already refuses an inactive user
 *     (Phase 0 slice 1), so this is the primary gate.
 *  2. The password is a discarded random string, never a known constant. There
 *     is no value anybody could type, including whoever reads this file.
 *  3. `is_super_admin = false` — an automated actor holds no privilege of its
 *     own. It records who acted; it does not authorise the action, which is
 *     already authorised by the code path that ran.
 *
 * ⚠ It is deliberately attached to **no business and no role**. It is an
 * identity for the audit trail, not an account with access, and giving it
 * either would turn a label into a live privilege.
 */
class SystemActor
{
    public const EMAIL = 'system@bordeguna-erp.internal';

    public const NAME = 'System';

    /**
     * The system user, created on first use.
     *
     * Resolved rather than seeded-and-assumed so a job cannot fail on a
     * database that predates the seeder. It is looked up by email, which is
     * unique, so this is safe to call repeatedly and from concurrent runs.
     */
    public static function user(): User
    {
        return User::withoutGlobalScopes()->firstOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => self::NAME,
                // Random and discarded. Not a constant, not derivable, and
                // never logged — there is deliberately no value to leak.
                'password' => bcrypt(Str::random(64)),
                'is_active' => false,
                'is_super_admin' => false,
            ],
        );
    }

    public static function id(): int
    {
        return self::user()->id;
    }

    /**
     * Whether a recorded actor was the system rather than a person. Lets a
     * screen say "System" instead of showing a name that looks like a colleague.
     */
    public static function isSystem(?User $user): bool
    {
        return $user !== null && $user->email === self::EMAIL;
    }
}
