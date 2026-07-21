<?php

namespace App\Modules\Foundation\Actions;

use App\Modules\Foundation\Models\Attachment;
use Illuminate\Support\Facades\Storage;

/**
 * Removes an attachment's stored file and its record together, so the disk
 * never accumulates orphaned files.
 */
class DeleteAttachment
{
    public function handle(Attachment $attachment): void
    {
        Storage::disk($attachment->disk)->delete($attachment->path);
        $attachment->delete();
    }
}
