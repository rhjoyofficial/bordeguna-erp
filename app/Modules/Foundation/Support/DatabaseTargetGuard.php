<?php

namespace App\Modules\Foundation\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Refuses to migrate when the database you ASKED for is not the one you are
 * CONNECTED to.
 *
 * ⚠⚠ WRITTEN AFTER AN INCIDENT, NOT IN ANTICIPATION OF ONE. On 2026-08-23 a
 * scratch validation ran as
 * `docker compose exec -e DB_DATABASE=scratch_ai app php artisan migrate`.
 * The container's config was CACHED, so `config('database.*.database')` still
 * said `bordeguna_erp`, the `-e` override was silently ignored, and a brand new
 * migration was applied to PRODUCTION. Nothing failed. Nothing warned. The only
 * signal was a `SELECT DATABASE()` run afterwards out of habit.
 *
 * ⚠ The comparison is deliberately between the ENVIRONMENT and the LIVE
 * CONNECTION, not between config and the connection. Config is the thing that
 * was stale; asking it what was intended would be asking the liar to confirm
 * its own story. `env()` reads the real process environment and keeps working
 * when config is cached, which is exactly the case that has to be caught.
 *
 * ⚠ Silent when `DB_DATABASE` is not set in the environment at all. A
 * deployment that relies entirely on a cached config and sets nothing per-run
 * is normal and must not start failing; this guard exists to catch a stated
 * intention being ignored, not to demand one.
 *
 * ℹ Throws rather than warns. A warning scrolls past in a container log and the
 * migration still runs, which is precisely how the incident happened.
 */
class DatabaseTargetGuard
{
    /**
     * @param  string|null  $intended  The database that was ASKED for. Defaults
     *                                 to reading the environment, which is the
     *                                 production path; tests pass it directly.
     *
     * ⚠ Injectable on purpose. The first version of this read `env()`
     * unconditionally, so its test had to `putenv()` to exercise it - and that
     * mutation of process-global state leaked across a parallel worker and
     * broke dozens of unrelated tests. A guard whose test can only run by
     * corrupting the environment is a guard that damages more than it prevents.
     */
    public static function assertConnectedToIntendedDatabase(?string $intended = null): void
    {
        $intended ??= env('DB_DATABASE');

        // Nothing was asked for, so nothing can be contradicted.
        if (! is_string($intended) || $intended === '') {
            return;
        }

        $actual = (string) DB::connection()->getDatabaseName();

        if ($actual === $intended) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing to migrate: DB_DATABASE asks for "%s" but this connection is on "%s". '
            .'The config is almost certainly cached, so the override was ignored. '
            .'Run "php artisan config:clear" in this container first, or pass a fresh '
            .'connection, then try again. (2026-08-23: this exact mismatch put a '
            .'migration on production.)',
            $intended,
            $actual,
        ));
    }
}
