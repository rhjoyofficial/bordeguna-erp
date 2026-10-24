<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Enums\JournalType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Foundation\Actions\RejectApprovable;
use App\Modules\Inventory\Models\CropWriteOff;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ⭐ Farm harvest (B9, 24 Oct 2026): the Owner's yes posts the write-off.
 *
 * ```
 * Dr  the loss head (5210 Crop Failure Loss)
 *     Cr  Crops in Progress
 * ```
 *
 * On the write-off's own date, through PostJournal. The approval decision and
 * the journal are ONE transaction: a closed month, a head emptied by a harvest
 * meanwhile, or a deactivated head refuses the approval with its sentence and
 * nothing is decided. The write-off is re-read under a lock, so two approvers
 * (or a double click) cannot post it twice.
 */
class ApproveCropWriteOff
{
    public function __construct(private readonly PostJournal $postJournal) {}

    public function handle(CropWriteOff $writeOff, User $approver, ?string $note = null): CropWriteOff
    {
        return DB::transaction(function () use ($writeOff, $approver, $note) {
            $current = CropWriteOff::query()->whereKey($writeOff->id)->lockForUpdate()->firstOrFail();

            if (! $current->isWaiting() || $current->approvalRequest === null || ! $current->approvalRequest->isPending()) {
                throw new RuntimeException("Crop write-off {$current->number} has already been decided.");
            }

            if (! $current->approvalRequest->canBeActionedBy($approver)) {
                throw new RuntimeException("Only the Owner approves crop write-off {$current->number}.");
            }

            if (! $current->approvalRequest->approve($approver, $note)) {
                return $current->fresh();
            }

            Account::query()->whereKey($current->crop_account_id)->lockForUpdate()->first();
            $date = Carbon::parse($current->written_off_on)->startOfDay();
            $amount = RaiseCropWriteOff::check($date, $current->cropAccount, $current->lossAccount, $current->amount()->toTaka());

            $journal = $this->postJournal->handle(new JournalDraft(
                date: $date,
                lines: [
                    JournalLineDraft::debit($current->loss_account_id, $amount, "Crop write-off {$current->number}"),
                    JournalLineDraft::credit($current->crop_account_id, $amount, "Crop write-off {$current->number}"),
                ],
                memo: "Crop write-off {$current->number}: {$current->reason_text}",
                source: $current,
                type: JournalType::Manual,
            ), $approver);

            $current->update([
                'status' => CropWriteOff::APPROVED,
                'journal_id' => $journal->id,
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);

            return $current->fresh();
        });
    }

    /** No: the write-off stays, rejected, and nothing posts. */
    public function reject(CropWriteOff $writeOff, User $approver, ?string $note, RejectApprovable $reject): CropWriteOff
    {
        return DB::transaction(function () use ($writeOff, $approver, $note, $reject) {
            $current = CropWriteOff::query()->whereKey($writeOff->id)->lockForUpdate()->firstOrFail();

            if (! $current->isWaiting()) {
                throw new RuntimeException("Crop write-off {$current->number} has already been decided.");
            }

            if ($current->approvalRequest === null || ! $current->approvalRequest->canBeActionedBy($approver)) {
                throw new RuntimeException("Only the Owner decides crop write-off {$current->number}.");
            }

            $reject->handle($current, $approver, $note);
            $current->update(['status' => CropWriteOff::REJECTED]);

            return $current->fresh();
        });
    }
}
