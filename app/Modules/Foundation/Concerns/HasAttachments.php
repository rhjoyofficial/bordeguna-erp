<?php

namespace App\Modules\Foundation\Concerns;

use App\Modules\Foundation\Models\Attachment;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Gives any model a polymorphic file-attachment relationship. Storing and
 * deleting the underlying files goes through the StoreAttachment /
 * DeleteAttachment actions (so disk writes stay in one place); this trait is
 * only the relationship + a lookup helper.
 */
trait HasAttachments
{
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function attachmentInCollection(?string $collection): ?Attachment
    {
        return $this->attachments()->where('collection', $collection)->latest('id')->first();
    }
}
