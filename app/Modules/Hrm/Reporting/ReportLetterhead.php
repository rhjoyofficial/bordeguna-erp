<?php

namespace App\Modules\Hrm\Reporting;

use App\Modules\Foundation\Models\Attachment;
use App\Modules\Foundation\Models\Business;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The business's own logo, ready to be drawn on a printed report.
 *
 * Joy, 1 September 2026: "all the report top middle add the business logo,
 * Bor de Guna text font as said."
 *
 * ⚠⚠ RETURNED AS A DATA URI, NOT A URL, AND THAT IS THE WHOLE POINT OF THIS
 * CLASS. The logo lives on the PRIVATE disk behind an authenticated,
 * business-scoped route. dompdf is not a browser: it carries no session, so a
 * URL would render as a broken image on every PDF, while the browser print view
 * would look perfect - the worst kind of difference, because only one of the two
 * is ever checked. Reading the bytes and inlining them makes both identical.
 *
 * ⚠ The logo file already carries the wordmark in the brand script, which is
 * why the report does not try to set `Berkshire Swash` on text: dompdf has no
 * such font and would silently fall back, printing the brand in the wrong face
 * on the one document that leaves the building.
 *
 * ⚠ Per business, never a hard-coded file. Four businesses share this codebase
 * and a report printed for LaLaDia must not wear Bor de Guna's leaf.
 */
class ReportLetterhead
{
    /**
     * Bigger than any sane logo. A 40 MB upload inlined into every page of a
     * PDF would turn a report into something nobody can email.
     */
    private const MAX_BYTES = 2 * 1024 * 1024;

    public static function logoFor(?int $businessId): ?string
    {
        if ($businessId === null) {
            return null;
        }

        // ⚠ withoutGlobalScopes for the same reason BusinessIdentity uses it:
        // the business is resolved by id from the request's own context, not
        // filtered by the tenant scope that context sets up.
        $business = Business::withoutGlobalScopes()->find($businessId);
        $logo = $business?->attachmentInCollection('logo');

        if ($logo === null) {
            return null;
        }

        return self::inline($logo);
    }

    private static function inline(Attachment $logo): ?string
    {
        try {
            $disk = Storage::disk($logo->disk);

            if (! $disk->exists($logo->path) || $disk->size($logo->path) > self::MAX_BYTES) {
                return null;
            }

            $bytes = $disk->get($logo->path);
        } catch (Throwable) {
            // ⚠ A missing or unreadable logo prints a report without a logo. It
            // never fails the report: nobody is helped by a payroll register
            // that refuses to open because an image moved.
            return null;
        }

        if ($bytes === null || $bytes === '') {
            return null;
        }

        $mime = $logo->mime_type ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }
}
