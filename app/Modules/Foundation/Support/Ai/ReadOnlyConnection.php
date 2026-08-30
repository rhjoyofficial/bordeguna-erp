<?php

namespace App\Modules\Foundation\Support\Ai;

use Illuminate\Support\Facades\Config;

/**
 * Registers the connection the data assistant reads through.
 *
 * ⚠⚠ IT MIRRORS THE APPLICATION'S OWN CONNECTION and swaps the credentials.
 * Hard-coding `driver => mysql` here was the first attempt and it was wrong:
 * the test suite runs on SQLite, so every query through this connection failed
 * for a reason that had nothing to do with what was being tested. Deriving it
 * means the assistant reads the same database the app does, on the same driver,
 * wherever it runs.
 *
 * ⚠⚠ THE CREDENTIALS ARE THE WHOLE POINT. In production DB_READONLY_USERNAME
 * names a MySQL user granted SELECT and nothing else, so writing is impossible
 * at the driver level rather than by policy - the last of the four barriers,
 * and the one that still holds if every check above it were bypassed at once.
 *
 * ⚠ When those variables are absent it falls back to the app's own credentials
 * so a development machine needs no setup. That fallback is a real risk - it is
 * exactly how this barrier would quietly stop existing - so `isTrulyReadOnly()`
 * reports the difference and the deploy checks it on production.
 */
final class ReadOnlyConnection
{
    public const NAME = 'readonly';

    public static function register(): void
    {
        $default = Config::get('database.default');
        $base = Config::get("database.connections.{$default}");

        if (! is_array($base)) {
            return;
        }

        // ⚠⚠ Config, NEVER env(). See config/database.php: after
        // `config:cache` - which production runs on every deploy - env() outside
        // a config file returns null, and this barrier would quietly disable
        // itself in the one environment that matters.
        $username = Config::get('database.readonly_username');
        $password = Config::get('database.readonly_password');

        // ⚠⚠ NO CREDENTIAL, NO SEPARATE CONNECTION - and this is not laziness.
        // A second connection with the SAME credential buys nothing at all, and
        // on SQLite it actively breaks: a second connection to `:memory:` opens
        // its own EMPTY database, so every query finds no such table. Better to
        // say plainly that the barrier is absent than to stand up a decorative
        // connection that hides it.
        if ($username === null || $username === '') {
            return;
        }

        Config::set('database.connections.'.self::NAME, array_merge($base, array_filter([
            'username' => $username,
            'password' => $password,
        ], fn ($value) => $value !== null && $value !== '')));
    }

    /**
     * The connection the assistant should actually read through.
     *
     * ⚠ The dedicated one when a SELECT-only credential is configured, and the
     * application's own otherwise. The other three barriers - the permission
     * map, the table allow-list and the app-injected scoping - do not depend on
     * this and are in force either way.
     */
    public static function name(): string
    {
        return self::isTrulyReadOnly() ? self::NAME : (string) Config::get('database.default');
    }

    /**
     * Whether the assistant is really reading through a separate credential.
     *
     * ⚠ False means the fallback is in use and the fourth barrier is not
     * actually present - the other three still are, but this one is decorative
     * until DB_READONLY_USERNAME is set to a SELECT-only MySQL user.
     */
    public static function isTrulyReadOnly(): bool
    {
        $configured = Config::get('database.connections.'.self::NAME);

        if (! is_array($configured)) {
            return false;
        }

        $appUser = Config::get('database.connections.'.Config::get('database.default').'.username');

        return ($configured['username'] ?? null) !== null && $configured['username'] !== $appUser;
    }
}
