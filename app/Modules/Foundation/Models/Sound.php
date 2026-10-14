<?php

namespace App\Modules\Foundation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One sound in the library (Settings > Sounds, 14 Oct 2026). Group-wide: no
 * business. The file sits on the private `local` disk under sounds/ and is
 * streamed by SoundController::file to signed-in people; the row holds the
 * facts, never the bytes.
 */
class Sound extends Model
{
    public const DISK = 'local';

    public const DIRECTORY = 'sounds';

    /** The types the uploader may send, by the type finfo reads from the bytes. */
    public const MIME_TYPES = ['audio/mpeg', 'audio/mp3', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/mp4', 'audio/x-m4a', 'video/mp4', 'application/mp4'];

    public const EXTENSIONS = ['mp3', 'ogg', 'wav', 'm4a'];

    public const MAX_KB = 1024;

    protected $fillable = ['name', 'disk', 'path', 'mime_type', 'size', 'duration_ms', 'uploaded_by'];

    public function slots(): HasMany
    {
        return $this->hasMany(SoundSlot::class);
    }

    public function popups(): HasMany
    {
        return $this->hasMany(Popup::class);
    }

    /** Where it is used: slot names and popup titles. A sound in use is never deleted. */
    public function usedBy(): array
    {
        return [
            'slots' => $this->slots()->pluck('slot')->all(),
            'popups' => $this->popups()->pluck('title')->all(),
        ];
    }

    public function inUse(): bool
    {
        return $this->slots()->exists() || $this->popups()->exists();
    }

    /** The URL the browser plays; the version in the query busts the cache after a replace. */
    public function url(): string
    {
        return route('sounds.file', ['sound' => $this->id, 'v' => $this->updated_at?->timestamp ?? 0]);
    }
}
