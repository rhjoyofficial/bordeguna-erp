<?php

namespace App\Modules\Foundation\Actions;

use App\Models\User;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Stores an uploaded file on a private disk and records it against any model.
 * When $singleton is true the collection keeps only the newest file (a logo
 * replaces the previous one, and the old file is removed from disk).
 */
class StoreAttachment
{
    public function __construct(
        private readonly DeleteAttachment $deleteAttachment,
    ) {}

    public function handle(
        Model $attachable,
        UploadedFile $file,
        ?string $collection = null,
        ?User $uploadedBy = null,
        bool $singleton = false,
        string $disk = 'local',
        ?string $expiresAt = null,
    ): Attachment {
        return DB::transaction(function () use ($attachable, $file, $collection, $uploadedBy, $singleton, $disk, $expiresAt) {
            if ($singleton) {
                $attachable->attachments()->where('collection', $collection)->get()
                    ->each(fn (Attachment $existing) => $this->deleteAttachment->handle($existing));
            }

            // A Business owns attachments under its own id; every other
            // (business-scoped) model carries the owning business on business_id.
            $businessId = $attachable instanceof Business
                ? $attachable->id
                : ($attachable->business_id ?? app(BusinessContext::class)->id());

            $path = $file->store("attachments/{$businessId}", $disk);

            return $attachable->attachments()->create([
                'business_id' => $businessId,
                'collection' => $collection,
                'disk' => $disk,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'expires_at' => $expiresAt,
                'uploaded_by' => $uploadedBy?->id,
            ]);
        });
    }
}
