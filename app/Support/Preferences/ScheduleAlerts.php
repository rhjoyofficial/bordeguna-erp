<?php

namespace App\Support\Preferences;

use App\Models\User;

/**
 * The person's own work schedule switches (14 Oct 2026): show the alerts,
 * and play a sound with them. Both ON by default, both off per person in
 * Settings > Appearance (Joy: "this can be on and off from the employee
 * end"). Kept in users.preferences, no migration.
 */
final class ScheduleAlerts
{
    public const KEY = 'schedule_alerts';

    /** @return array{enabled: bool, sound: bool} */
    public static function for(User $user): array
    {
        $stored = ($user->preferences ?? [])[self::KEY] ?? null;
        $stored = is_array($stored) ? $stored : [];

        return [
            'enabled' => (bool) ($stored['enabled'] ?? true),
            'sound' => (bool) ($stored['sound'] ?? true),
        ];
    }

    /** @param  array{enabled?: bool, sound?: bool}  $changes */
    public static function save(User $user, array $changes): void
    {
        $preferences = $user->preferences ?? [];
        $preferences[self::KEY] = array_merge(self::for($user), array_intersect_key($changes, ['enabled' => true, 'sound' => true]));

        $user->forceFill(['preferences' => $preferences])->save();
    }
}
