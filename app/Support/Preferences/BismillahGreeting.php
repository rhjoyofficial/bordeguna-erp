<?php

namespace App\Support\Preferences;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The Bismillah greeting (owner request, 11 Oct 2026): a short popup with the
 * Basmala, shown right after a person signs in and when they come back to the
 * ERP after an hour away.
 *
 * ON by default for everybody. The person's own choice is kept in
 * users.preferences['bismillah_greeting'], the same per-person settings column
 * the dashboard sound uses, so it follows them to any device. No migration: the
 * column already exists.
 *
 * The "just signed in" signal is a nonce written into the session at login and
 * shared with every page. The browser remembers the last nonce it greeted, so a
 * print page that happens to be the first page after login does not swallow
 * the greeting, and a back button or a partial reload never shows it twice.
 * A new login regenerates the session, so it carries a new nonce.
 */
final class BismillahGreeting
{
    public const KEY = 'bismillah_greeting';

    public const SESSION_KEY = 'bismillah_login';

    public static function enabled(User $user): bool
    {
        $stored = ($user->preferences ?? [])[self::KEY] ?? null;

        return is_array($stored) ? (bool) ($stored['enabled'] ?? true) : true;
    }

    public static function save(User $user, bool $enabled): void
    {
        $preferences = $user->preferences ?? [];
        $preferences[self::KEY] = ['enabled' => $enabled];

        $user->forceFill(['preferences' => $preferences])->save();
    }

    /** Called once by the login controller, after the session is regenerated. */
    public static function markLogin(Request $request): void
    {
        $request->session()->put(self::SESSION_KEY, Str::random(16));
    }

    /**
     * The shared prop: null for a guest page.
     *
     * @return array{enabled: bool, login: ?string}|null
     */
    public static function share(Request $request): ?array
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        return [
            'enabled' => self::enabled($user),
            'login' => $request->session()->get(self::SESSION_KEY),
        ];
    }
}
