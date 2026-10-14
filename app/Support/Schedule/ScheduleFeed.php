<?php

namespace App\Support\Schedule;

use App\Modules\Foundation\Models\SoundSlot;
use App\Modules\Foundation\Models\WorkScheduleSetting;
use App\Modules\Foundation\Models\WorkScheduleSlot;
use App\Support\Preferences\ScheduleAlerts;
use Illuminate\Http\Request;

/**
 * What the browser's work schedule alert engine is handed on every page
 * (14 Oct 2026): whether alerts are on for this person (the master switch AND
 * their own), their sound switch, how long an alert stays, the server's clock
 * (the browser derives Asia/Dhaka time from it, never from its own zone), the
 * sound to play, and every active slot with its weekdays.
 */
final class ScheduleFeed
{
    public const TIMEZONE = 'Asia/Dhaka';

    public const BUILT_IN_SOUND = '/sounds/success.mp3';

    /** @return array<string, mixed>|null null for a guest page */
    public static function share(Request $request): ?array
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        $settings = WorkScheduleSetting::current();
        $mine = ScheduleAlerts::for($user);
        $sound = SoundSlot::query()->with('sound')->find('schedule')?->sound?->url();

        return [
            'enabled' => $settings->is_enabled && $mine['enabled'],
            // The two halves, for Settings > Appearance.
            'own_enabled' => $mine['enabled'],
            'global_enabled' => $settings->is_enabled,
            'sound' => $mine['sound'],
            'alert_seconds' => $settings->alert_seconds,
            'server_now' => now(self::TIMEZONE)->toIso8601String(),
            'sound_url' => $sound ?? self::BUILT_IN_SOUND,
            'slots' => self::activeSlots(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function activeSlots(): array
    {
        return WorkScheduleSlot::query()
            ->where('is_active', true)
            ->ordered()
            ->get()
            ->map(fn (WorkScheduleSlot $slot) => $slot->toFeed())
            ->values()
            ->all();
    }
}
