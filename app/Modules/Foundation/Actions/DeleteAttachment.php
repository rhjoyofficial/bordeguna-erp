<?php

namespace App\Modules\Foundation\Actions;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Foundation\Models\Attachment;
use Illuminate\Support\Facades\Auth;

/**
 * Removes an attachment from wherever it hangs.
 *
 * ⭐⭐ Corrections wave 3, B11 (24 Oct 2026): it goes to the Recycle bin, it is
 * NOT destroyed (FINAL-PLAN 3.5: "Library file, folder, record attachment:
 * Delete to the bin, logged"; section 9 answer 1: no purge, ever). The row is
 * soft-deleted with who and why, and the stored file STAYS on the disk, so the
 * Owner can restore it; the download route cannot reach it meanwhile. Until
 * this date a removal deleted the file for good, with no trace.
 *
 * ⚠ The same call for every module, so a caller that says nothing still
 * leaves a who (the signed-in person) and a why (`DEFAULT_REASON`), and a
 * replaced logo or photo (StoreAttachment, singleton) lands in the bin too.
 */
class DeleteAttachment
{
    public const DEFAULT_REASON = 'Removed from its record';

    public function handle(Attachment $attachment, ?User $by = null, ?ActionReason $reason = null): void
    {
        $by ??= Auth::user();
        $why = $reason?->display() ?? self::DEFAULT_REASON;

        $attachment->forceFill(['deleted_by' => $by?->id, 'delete_reason' => $why])->saveQuietly();
        $attachment->delete();

        if ($by instanceof User) {
            CorrectionLog::event('deleted', $by, $attachment, ['reason' => $why, 'name' => $attachment->original_name]);
        }
    }
}
