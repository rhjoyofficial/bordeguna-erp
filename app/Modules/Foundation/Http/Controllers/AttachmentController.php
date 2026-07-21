<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Contracts\ControlsAttachmentAccess;
use App\Modules\Foundation\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a stored file inline. Access is confined to the current business
 * automatically: the {attachment} binding runs through Attachment's business
 * scope, so a file from another business simply 404s. When the owning model
 * declares stricter rules (ControlsAttachmentAccess — e.g. an employee's
 * personal documents), those are enforced on top of the business scope.
 */
class AttachmentController extends Controller
{
    public function show(Request $request, Attachment $attachment): StreamedResponse
    {
        $owner = $attachment->attachable;
        if ($owner instanceof ControlsAttachmentAccess) {
            abort_unless($owner->canAccessAttachments($request->user()), 403);
        }

        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        return Storage::disk($attachment->disk)->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type ?? 'application/octet-stream',
        ]);
    }
}
