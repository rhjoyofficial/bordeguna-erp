<?php

namespace App\Support\Dashboards;

use App\Models\User;

/**
 * The dashboard sound settings, kept in users.preferences['dashboard_sound'].
 *
 * Owner override of ChatGPT ruling 7 (Joy, 11 Oct 2026): sound is available to
 * everybody, ON by default only for holders of dashboard.view_all (Joy), OFF for
 * everybody else until they turn it on (the office-noise concern). A stored
 * choice always wins over the default.
 */
final class SoundPreferences
{
    public const KEY = 'dashboard_sound';

    public const CATEGORIES = ['success', 'attention', 'error'];

    /** @return array<string, mixed> */
    public static function defaults(bool $viewAll): array
    {
        return [
            'enabled' => $viewAll,
            'volume' => 0.6,
            'categories' => ['success' => true, 'attention' => true, 'error' => true],
            'quiet_hours' => ['enabled' => false, 'from' => '22:00', 'to' => '08:00'],
        ];
    }

    /** @return array<string, mixed> */
    public static function for(User $user, bool $viewAll): array
    {
        $stored = ($user->preferences ?? [])[self::KEY] ?? null;
        $defaults = self::defaults($viewAll);

        if (! is_array($stored)) {
            return $defaults + ['customised' => false];
        }

        return [
            'enabled' => (bool) ($stored['enabled'] ?? $defaults['enabled']),
            'volume' => (float) ($stored['volume'] ?? $defaults['volume']),
            'categories' => array_merge($defaults['categories'], array_map('boolval', (array) ($stored['categories'] ?? []))),
            'quiet_hours' => array_merge($defaults['quiet_hours'], (array) ($stored['quiet_hours'] ?? [])),
            'customised' => true,
        ];
    }

    /** @return array<string, mixed> the validation rules for a save */
    public static function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'volume' => ['required', 'numeric', 'min:0', 'max:1'],
            'categories' => ['required', 'array'],
            'categories.success' => ['required', 'boolean'],
            'categories.attention' => ['required', 'boolean'],
            'categories.error' => ['required', 'boolean'],
            'quiet_hours' => ['required', 'array'],
            'quiet_hours.enabled' => ['required', 'boolean'],
            'quiet_hours.from' => ['required', 'date_format:H:i'],
            'quiet_hours.to' => ['required', 'date_format:H:i'],
        ];
    }

    /** @param array<string, mixed> $validated */
    public static function save(User $user, array $validated): void
    {
        $preferences = $user->preferences ?? [];
        $preferences[self::KEY] = [
            'enabled' => (bool) $validated['enabled'],
            'volume' => round((float) $validated['volume'], 2),
            'categories' => array_map('boolval', array_intersect_key($validated['categories'], array_flip(self::CATEGORIES))),
            'quiet_hours' => [
                'enabled' => (bool) $validated['quiet_hours']['enabled'],
                'from' => $validated['quiet_hours']['from'],
                'to' => $validated['quiet_hours']['to'],
            ],
        ];

        $user->forceFill(['preferences' => $preferences])->save();
    }
}
