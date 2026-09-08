<?php

namespace App\Modules\Commerce\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * One image in a store's library.
 *
 * ⛔ A section references one of these BY ID and never by URL. A typed URL
 * cannot be checked, cannot be moved, and cannot tell anybody that the file
 * behind it has gone: laladia.com carried a hero image that 404'd on a live
 * landing page for weeks because the reference was a string.
 */
class StoreMedia extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $table = 'store_media';

    protected $fillable = [
        'business_id',
        'store_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'width',
        'height',
        'alt_text',
        'is_decorative',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'is_decorative' => 'boolean',
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    protected $attributes = [
        'is_decorative' => false,
    ];

    public static function booted(): void
    {
        // ⚠ Minted here rather than left to the caller, so no code path can
        // create a row whose public URL is missing.
        static::creating(function (self $media) {
            $media->uuid ??= (string) Str::uuid();
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * ⛔ Has somebody DECIDED about the alt text?
     *
     * ⚠ Not "is alt text present". An empty alt is correct for a decorative
     * flourish and wrong for a product photograph, so the two are stored as
     * different facts and this is the question worth asking. An undecided image
     * is usable in the library and refused at publish.
     */
    public function altTextIsDecided(): bool
    {
        return $this->is_decorative || trim((string) $this->alt_text) !== '';
    }

    /**
     * What a screen reader would be given.
     */
    public function altText(): string
    {
        return $this->is_decorative ? '' : trim((string) $this->alt_text);
    }

    /**
     * The public address of this file.
     *
     * ⚠ Deliberately a route on our own domain and NOT `/storage/...`. Two
     * reasons, both practical: the storage symlink is not created anywhere in
     * this stack's deployment, and a `/storage` URL publishes the internal
     * layout of the disk. This URL is stable, so when the storefront grows
     * enough traffic to want nginx serving these bytes directly, the web
     * container already mounts the storage volume read only and can take this
     * exact path over without a single URL changing.
     */
    public function url(): string
    {
        return route('commerce.media.show', ['uuid' => $this->uuid]);
    }

    public function exists(): bool
    {
        return Storage::disk($this->disk)->exists($this->path);
    }

    /**
     * ⚠ Width and height travel with the reference so the storefront can
     * reserve the space before the bytes arrive.
     */
    public function toReference(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'url' => $this->url(),
            'alt' => $this->altText(),
            'is_decorative' => $this->is_decorative,
            'alt_decided' => $this->altTextIsDecided(),
            'width' => $this->width,
            'height' => $this->height,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
        ];
    }
}
