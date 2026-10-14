<?php

namespace App\Support\Popups;

use App\Models\User;
use App\Modules\Foundation\Models\Popup;
use App\Modules\Foundation\Models\PopupView;
use App\Modules\Foundation\Models\SoundSlot;
use App\Support\Dashboards\SoundPreferences;
use App\Support\Preferences\BismillahGreeting;
use App\Support\Preferences\PopupSound;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;

/**
 * What the browser's popup engine is handed on every page (14 Oct 2026): the
 * person's two switches, the sign-in nonce, the sound each event plays, and
 * the popups that apply to THIS person TODAY in the business they are in.
 *
 * ⚠ The list is small and deterministic for a person and a day: nothing in it
 * changes between requests, so the live refresh never fires on it. A popup
 * that was already shown under a once rule is left out here; the claim on
 * display (claim()) is still the real guard, because two tabs can both be
 * handed the same once-only popup before either shows it.
 */
final class PopupFeed
{
    /** @return array<string, mixed>|null null for a guest page */
    public static function share(Request $request): ?array
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        $today = now()->toDateString();
        $roles = $user->getRoleNames()->all();
        $sounds = self::sounds();
        $quiet = ($user->preferences ?? [])[SoundPreferences::KEY]['quiet_hours'] ?? null;

        $shown = PopupView::query()
            ->where('user_id', $user->id)
            ->whereIn('period', ['once', $today])
            ->get()
            ->map(fn (PopupView $view) => $view->popup_id.':'.$view->period)
            ->flip();

        $items = Popup::query()
            ->liveOn($today)
            ->with('sound')
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get()
            ->filter(fn (Popup $popup) => $popup->appliesToRoles($roles))
            ->reject(fn (Popup $popup) => $popup->frequency === 'once' && $shown->has($popup->id.':once'))
            ->reject(fn (Popup $popup) => $popup->frequency === 'daily' && $shown->has($popup->id.':'.$today))
            ->map(fn (Popup $popup) => self::item($popup, $sounds['popup']))
            ->values()
            ->all();

        return [
            'enabled' => BismillahGreeting::enabled($user),
            'sound' => PopupSound::enabled($user),
            'login' => $request->session()->get(BismillahGreeting::SESSION_KEY),
            'quiet_hours' => is_array($quiet) && ($quiet['enabled'] ?? false) ? ['from' => (string) $quiet['from'], 'to' => (string) $quiet['to']] : null,
            'sounds' => $sounds,
            'items' => $items,
        ];
    }

    /**
     * The URL each event plays, null for the built-in file (dashboard events
     * and the work schedule) or silence (popup).
     *
     * @return array{success: ?string, attention: ?string, error: ?string, popup: ?string, schedule: ?string}
     */
    public static function sounds(): array
    {
        $urls = SoundSlot::query()->with('sound')->get()->mapWithKeys(fn (SoundSlot $slot) => [$slot->slot => $slot->sound?->url()]);

        return [
            'success' => $urls['success'] ?? null,
            'attention' => $urls['attention'] ?? null,
            'error' => $urls['error'] ?? null,
            'popup' => $urls['popup'] ?? null,
            'schedule' => $urls['schedule'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private static function item(Popup $popup, ?string $defaultSound): array
    {
        $sound = match ($popup->sound_mode) {
            'none' => null,
            'custom' => $popup->sound?->url(),
            default => $defaultSound,
        };

        $media = match ($popup->media_type) {
            'youtube' => ['type' => 'youtube', 'id' => $popup->youtube_id],
            'video' => ['type' => 'video', 'url' => route('popups.media', ['popup' => $popup->id, 'v' => $popup->updated_at?->timestamp ?? 0])],
            default => null,
        };

        return [
            'id' => $popup->id,
            'key' => $popup->key,
            'title' => $popup->title,
            'lines' => $popup->lines,
            'style' => $popup->style,
            'on_login' => $popup->on_login,
            'idle_minutes' => $popup->idle_minutes,
            'frequency' => $popup->frequency,
            // ⚠ A video popup never plays the popup sound (it would clash with the video).
            'sound' => $media === null ? $sound : null,
            'close_after_seconds' => $media === null ? $popup->close_after_seconds : null,
            'priority' => $popup->priority,
            'media' => $media,
        ];
    }

    /**
     * Claim a once-a-day or once-per-person popup for this person before it
     * shows. True when this tab won; false when another tab or device already
     * did, so this one stays quiet. An every-time popup needs no claim.
     */
    public static function claim(Popup $popup, User $user): bool
    {
        if ($popup->frequency === 'every') {
            return true;
        }

        $period = $popup->frequency === 'once' ? 'once' : now()->toDateString();

        try {
            PopupView::create(['popup_id' => $popup->id, 'user_id' => $user->id, 'period' => $period, 'shown_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }
}
