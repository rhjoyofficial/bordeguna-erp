<?php

namespace App\Modules\Hrm\Reporting;

use App\Models\User;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The signature images for a report's Prepared/Checked/Approved row
 * (Joy, 3 Sep 2026).
 *
 * Each name on the meta is resolved to a user, the user to their employee,
 * the employee to the one signature attachment HR uploaded - inlined as a
 * data URI, because dompdf has no session to fetch a private file with (the
 * letterhead logo's lesson, reapplied).
 *
 * ⚠ A missing or unreadable signature leaves that line blank for ink. It
 * never fails a report and never falls back to somebody else's image: the
 * name must match a user EXACTLY, or nothing is drawn.
 */
class SignatureImages
{
    /** A signature bigger than this is not a signature. */
    private const MAX_BYTES = 512 * 1024;

    /**
     * @return array{prepared: ?string, checked: ?string, approved: ?string}
     */
    public static function forMeta(ReportMeta $meta): array
    {
        return [
            'prepared' => self::forName($meta->preparedBy),
            'checked' => self::forName($meta->checkedBy),
            'approved' => self::forName($meta->approvedBy),
        ];
    }

    private static function forName(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        try {
            $user = User::query()->where('name', $name)->first();
            if ($user === null) {
                return null;
            }

            $employee = Employee::query()->where('user_id', $user->id)->first();
            $signature = $employee?->attachments()->where('collection', 'signature')->first();

            return $signature === null ? null : self::inline($signature);
        } catch (Throwable) {
            return null;
        }
    }

    private static function inline(Attachment $signature): ?string
    {
        try {
            $disk = Storage::disk($signature->disk);

            if (! $disk->exists($signature->path) || $disk->size($signature->path) > self::MAX_BYTES) {
                return null;
            }

            $bytes = $disk->get($signature->path);
        } catch (Throwable) {
            return null;
        }

        if ($bytes === null || $bytes === '') {
            return null;
        }

        return 'data:'.($signature->mime_type ?: 'image/png').';base64,'.base64_encode($bytes);
    }
}
