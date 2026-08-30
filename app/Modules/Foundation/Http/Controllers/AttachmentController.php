<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Contracts\ControlsAttachmentAccess;
use App\Modules\Foundation\Contracts\OpensAttachmentCollections;
use App\Modules\Foundation\Models\Attachment;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a stored file inline. Access is confined to the current business
 * automatically: the {attachment} binding runs through Attachment's business
 * scope, so a file from another business simply 404s. When the owning model
 * declares stricter rules (ControlsAttachmentAccess — e.g. an employee's
 * personal documents), those are enforced on top of the business scope.
 *
 * ⚠⚠ THIS IS THE ONLY WAY A STORED FILE LEAVES THIS SYSTEM. Files sit on a
 * private disk with no public URL. Every screen that shows, previews or
 * downloads a file comes through here, and anything that did not would be a
 * second security surface doing the same job.
 */
class AttachmentController extends Controller
{
    public function show(Request $request, Attachment $attachment): StreamedResponse|BinaryFileResponse
    {
        $owner = $attachment->attachable;
        $open = $owner instanceof OpensAttachmentCollections
            && in_array($attachment->collection, $owner->openAttachmentCollections(), true);
        if ($owner instanceof ControlsAttachmentAccess && ! $open) {
            abort_unless($owner->canAccessAttachments($request->user()), 403);
        }

        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->path), 404);

        // ⚠ `attachment` only when explicitly asked for. The archive previews
        // images, PDFs and video in the page, and a Content-Disposition of
        // attachment makes the browser download instead of render - the
        // preview would silently become a download and look like a broken page.
        $disposition = $request->boolean('download')
            ? ResponseHeaderBag::DISPOSITION_ATTACHMENT
            : ResponseHeaderBag::DISPOSITION_INLINE;

        $mime = $attachment->mime_type ?? 'application/octet-stream';

        // ⚠⚠ NOSNIFF IS THE ACTUAL PROTECTION, and it matters more here than
        // almost anywhere else in the app. The stored mime is the one the
        // BROWSER CLAIMED at upload, so it is attacker-influenced; what stops a
        // file talking a browser into treating it as HTML and running script on
        // this origin is that we always declare a Content-Type and forbid
        // sniffing past it. The archive renders files in the page, so this is
        // the header that makes previewing somebody else's upload safe.
        $headers = ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff'];

        // ⚠⚠ RANGE REQUESTS, WITHOUT WHICH VIDEO DOES NOT WORK. A streamed
        // response sends the whole file and advertises no `Accept-Ranges`, so a
        // browser cannot seek; Safari refuses to play at all, and a long video
        // must download completely before it starts. BinaryFileResponse handles
        // Range natively, but it needs a real path on local disk - which is why
        // this is conditional rather than the default, and why the streamed
        // version is kept for any remote disk this moves to later.
        if ($disk instanceof FilesystemAdapter && ($local = $this->localPath($disk, $attachment->path)) !== null) {
            $response = response()->file($local, $headers);
            $response->setContentDisposition($disposition, $attachment->original_name);

            return $response;
        }

        return $disk->response($attachment->path, $attachment->original_name, $headers, $disposition);
    }

    /**
     * The absolute path, when the disk is a local one and the file is really
     * there. Null for anything remote, or if the driver will not say.
     */
    private function localPath(FilesystemAdapter $disk, string $path): ?string
    {
        try {
            $absolute = $disk->path($path);
        } catch (\Throwable) {
            // A remote adapter throws rather than answering. That is a normal
            // outcome here, not a failure: it just means stream instead.
            return null;
        }

        return is_string($absolute) && is_file($absolute) ? $absolute : null;
    }
}
