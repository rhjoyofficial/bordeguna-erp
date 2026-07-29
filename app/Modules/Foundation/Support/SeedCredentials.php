<?php

namespace App\Modules\Foundation\Support;

use Illuminate\Support\Str;

/**
 * How a seeded development account gets its password.
 *
 * ⚠⚠ **THIS EXISTS BECAUSE FIVE ACCOUNTS WITH THE PASSWORD "password" WERE
 * FOUND LIVE ON THE PUBLIC PRODUCTION SYSTEM ON 2026-07-29** — including
 * `superadmin@bordeguna-erp.test`, which carries `is_super_admin`.
 *
 * They arrived the ordinary way: `db:seed` is part of provisioning a new
 * installation, and the seeders that create demo logins are the same seeders
 * that create roles, permissions, holiday calendars and salary components. The
 * useful half is required in production; the demo logins came along with it.
 *
 * Nobody had to make a mistake for this to happen, which is exactly why it
 * needs a mechanism rather than a note in a runbook.
 *
 * **The rule: outside local development, a seeded account gets a random
 * password nobody knows and is created inactive.** It still exists, so the
 * seeders stay idempotent and roles still attach; it simply cannot be logged
 * into. A real administrator is then created deliberately, by a human, with a
 * password that was never written down in a repository.
 */
class SeedCredentials
{
    /**
     * Whether seeded demo logins may use a known password.
     *
     * ⚠ Deliberately allowlists `local` and `testing` rather than denying
     * `production`. A new environment name — `staging`, `demo`, `uat` — is then
     * safe by default instead of dangerous by default, and the failure mode of
     * getting this wrong is "I cannot log in to staging" rather than "anyone
     * can log in to staging".
     */
    public static function allowKnownPassword(): bool
    {
        return in_array(app()->environment(), ['local', 'testing'], true);
    }

    /**
     * The password to seed a demo account with.
     *
     * `password` where that is safe and expected, otherwise a random string
     * that is generated, hashed and discarded — there is no value to leak,
     * because none is retained anywhere.
     */
    public static function demoPassword(): string
    {
        return self::allowKnownPassword() ? 'password' : Str::random(48);
    }

    /**
     * Whether a seeded demo account should be able to log in at all.
     *
     * ⚠ Belt and braces with the random password on purpose. Either alone would
     * do; both together mean that restoring one by hand (a developer flipping
     * `is_active` to debug something) does not silently restore the other.
     */
    public static function demoActive(): bool
    {
        return self::allowKnownPassword();
    }
}
