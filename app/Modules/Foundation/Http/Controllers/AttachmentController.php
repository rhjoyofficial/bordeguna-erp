<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\Attachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a stored file inline. Access is confined to the current business
 * automatically: the {attachment} binding runs through Attachment's business
 * scope, so a file from another business simply 404s. Consumers with stricter
 * needs (e.g. an employee's personal documents) layer a policy on top of this
 * in their own phase.
 */
class AttachmentController extends Controller
{
    public function show(Attachment $attachment): StreamedResponse
    {
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        return Storage::disk($attachment->disk)->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type ?? 'application/octet-stream',
        ]);
    }
}
