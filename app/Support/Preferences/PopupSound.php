<?php

namespace App\Support\Preferences;

use App\Models\User;

/**
 * The person's own popup-sound switch (14 Oct 2026): ON for everybody by
 * default (Joy's choice), off per person in Settings > Appearance. Separate
 * from the popup switch itself (BismillahGreeting::KEY): muting the sound
 * never hides a popup. Kept in users.preferences, no migration.
 */
final class PopupSound
{
    public const KEY = 'popup_sound';

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
}
