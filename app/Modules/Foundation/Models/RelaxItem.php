<?php

namespace App\Modules\Foundation\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One item on the Relax zone (Settings > Relax zone, 14 Oct 2026): an audio
 * file, an uploaded video, or a YouTube video. Group-wide: no business.
 *
 * The title and description are data a manager typed, rendered as text. A
 * file sits on the private `local` disk under relax/ with a random name and is
 * streamed by RelaxController::media; a YouTube item holds only its id.
 */
class RelaxItem extends Model
{
    public const KINDS = ['audio', 'video', 'youtube'];

    public const DISK = 'local';

    public const DIRECTORY = 'relax';

    /**
     * What the uploader may send, by the type read FROM THE BYTES.
     * ⚠ application/mp4 is what Symfony's guesser reports for an MP4 or M4A
     * file; finfo itself may say video/mp4 or audio/mp4. Same container.
     */
    public const AUDIO_MIME_TYPES = ['audio/mpeg', 'audio/mp3', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/mp4', 'audio/x-m4a', 'video/mp4', 'application/mp4'];

    public const AUDIO_EXTENSIONS = ['mp3', 'ogg', 'm4a', 'wav'];

    public const AUDIO_MAX_KB = 20480;

    public const VIDEO_MIME_TYPES = ['video/mp4', 'video/webm', 'application/mp4'];

    public const VIDEO_EXTENSIONS = ['mp4', 'webm'];

    public const VIDEO_MAX_KB = 102400;

    protected $fillable = [
        'title', 'description', 'kind', 'media_path', 'media_mime', 'media_size', 'youtube_id',
        'sort_order', 'is_active', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'media_size' => 'integer',
        ];
    }

    public function isFileKind(): bool
    {
        return $this->kind === 'audio' || $this->kind === 'video';
    }

    /** The stream address, or null for YouTube or a file kind with no file yet. The version busts the cache after a replace. */
    public function mediaUrl(): ?string
    {
        if (! $this->isFileKind() || ! is_string($this->media_path) || $this->media_path === '') {
            return null;
        }

        return route('relax.media', ['item' => $this->id, 'v' => $this->updated_at?->timestamp ?? 0]);
    }
}
