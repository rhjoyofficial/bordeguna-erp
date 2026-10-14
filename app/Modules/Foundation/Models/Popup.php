<?php

namespace App\Modules\Foundation\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A popup the Owner made (Settings > Popups, 14 Oct 2026), or the built-in
 * Bismillah one (key 'bismillah': editable, pausable, never deleted).
 *
 * Group-wide. `lines` is plain text rendered as text: a popup can never carry
 * script. `audience_roles` are role names, matched against the person's roles
 * in the business they are working in.
 */
class Popup extends Model
{
    public const BISMILLAH = 'bismillah';

    public const STYLES = ['calm', 'bismillah', 'notice', 'warning'];

    public const LINE_KINDS = ['arabic', 'heading', 'body', 'note'];

    public const FREQUENCIES = ['every', 'daily', 'once'];

    public const SOUND_MODES = ['default', 'none', 'custom'];

    public const MEDIA_TYPES = ['none', 'image', 'video', 'youtube'];

    public const MEDIA_DISK = 'local';

    public const MEDIA_DIRECTORY = 'popup-media';

    // ⚠ application/mp4 is what Symfony's guesser reports for an MP4 file;
    // finfo itself says video/mp4. Both are the same container.
    public const MEDIA_MIME_TYPES = ['video/mp4', 'video/webm', 'application/mp4'];

    public const MEDIA_EXTENSIONS = ['mp4', 'webm'];

    public const MEDIA_MAX_KB = 25600;

    public const IMAGE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public const IMAGE_MAX_KB = 5120;

    public const MIN_IDLE_MINUTES = 5;

    protected $fillable = [
        'key', 'title', 'lines', 'style', 'on_login', 'idle_minutes', 'audience_roles', 'frequency',
        'starts_on', 'ends_on', 'sound_mode', 'sound_id', 'close_after_seconds', 'priority', 'is_active',
        'media_type', 'media_path', 'media_mime', 'media_size', 'youtube_id', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'audience_roles' => 'array',
            'on_login' => 'boolean',
            'is_active' => 'boolean',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function sound(): BelongsTo
    {
        return $this->belongsTo(Sound::class);
    }

    public function views(): HasMany
    {
        return $this->hasMany(PopupView::class);
    }

    public function isBuiltIn(): bool
    {
        return $this->key !== null;
    }

    public function hasMedia(): bool
    {
        return $this->media_type !== 'none';
    }

    /** A video plays sound of its own: no popup sound, no auto-close. An image is still. */
    public function hasPlayableMedia(): bool
    {
        return in_array($this->media_type, ['video', 'youtube'], true);
    }

    /** Active, and inside its date window on the given Dhaka date. */
    public function scopeLiveOn(Builder $query, string $date): Builder
    {
        // ⚠ whereDate, not where: a date column compared as a string against
        // 'Y-m-d' fails on SQLite, where it is stored with a time part.
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_on')->orWhereDate('starts_on', '<=', $date))
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date));
    }

    /** Everybody, or anyone holding one of the named roles here. */
    public function appliesToRoles(array $roleNames): bool
    {
        $audience = $this->audience_roles;

        if ($audience === null || $audience === []) {
            return true;
        }

        return array_intersect($audience, $roleNames) !== [];
    }

    /**
     * The 11-character id from a youtube.com or youtu.be address, or null.
     * Only the id is ever stored; the player address is built from it.
     */
    public static function youtubeId(?string $input): ?string
    {
        $input = trim((string) $input);

        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $input)) {
            return $input;
        }

        $parts = parse_url($input);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $host = preg_replace('/^(www|m|music)\./', '', $host);

        if (! in_array($host, ['youtube.com', 'youtube-nocookie.com', 'youtu.be'], true)) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '');
        $candidate = null;

        if ($host === 'youtu.be') {
            $candidate = ltrim($path, '/');
        } elseif (preg_match('#^/(?:embed|shorts|live|v)/([^/?]+)#', $path, $m)) {
            $candidate = $m[1];
        } else {
            parse_str((string) ($parts['query'] ?? ''), $query);
            $candidate = $query['v'] ?? null;
        }

        return is_string($candidate) && preg_match('/^[A-Za-z0-9_-]{11}$/', $candidate) ? $candidate : null;
    }
}
