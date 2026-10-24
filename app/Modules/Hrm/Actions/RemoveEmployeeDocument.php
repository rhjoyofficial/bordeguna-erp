<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Foundation\Actions\DeleteAttachment;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ Delete an employee document (NID, contract, certificate) with a reason,
 * logged (corrections wave 3, 24 Oct 2026; FINAL-PLAN 3.4: "Delete to the bin,
 * logged. HR deletes. Owner restores").
 *
 * ⚠⚠ DEPENDENCY. The bin half needs the attachment itself to be Recyclable,
 * which is the files builder's row (FINAL-PLAN 3.5, "record attachment") and
 * changes every one of the 35 places a file is removed. Until that lands,
 * the deletion is what it was (the file and its row go) but it now asks why
 * and leaves a line in the employee's History saying what went, who and why.
 * The moment Attachment is in config('recycle_bin.models') this sends the
 * document to the bin instead, where the Owner restores it, with no change
 * here.
 */
class RemoveEmployeeDocument
{
    public function __construct(private readonly DeleteAttachment $delete) {}

    /** @return bool whether it went to the Recycle bin (false: removed for good, as before) */
    public function handle(Employee $employee, Attachment $document, User $by, ActionReason $reason): bool
    {
        return DB::transaction(function () use ($employee, $document, $by, $reason) {
            $toBin = in_array(Attachment::class, config('recycle_bin.models', []), true) && method_exists($document, 'recycle');

            CorrectionLog::event('document_deleted', $by, $employee, [
                'document' => $document->collection,
                'file' => $document->displayTitle(),
                'uploaded_at' => $document->created_at?->toDateString(),
                'reason' => $reason->display(),
                'to_recycle_bin' => $toBin,
            ]);

            if ($toBin) {
                $document->recycle($by, $reason);
            } else {
                $this->delete->handle($document);
            }

            return $toBin;
        });
    }
}
