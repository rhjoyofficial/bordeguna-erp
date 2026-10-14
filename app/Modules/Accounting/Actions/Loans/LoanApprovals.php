<?php

namespace App\Modules\Accounting\Actions\Loans;

use App\Models\User;
use App\Modules\Accounting\Enums\LoanStatus;
use App\Modules\Accounting\Models\LoanContract;
use App\Modules\Accounting\Models\LoanEntry;
use App\Modules\Accounting\Models\LoanScheduleRow;
use App\Modules\Accounting\Models\LoanTermsVersion;
use App\Modules\Accounting\Support\Loans\LoanTerms;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Modules\Foundation\Models\ApprovalWorkflow;
use App\Modules\Foundation\Scopes\BusinessScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A loan agreement's way to the Owner and back (loans plan v2, phase 2):
 *
 *   submit     draft -> waiting for the Owner, through the Approvals engine
 *   approve    the Owner says yes -> approved (no money moves yet), and the
 *              approved terms become version 1
 *   send back  the Owner says "not like this" -> draft again, with the note,
 *              keeping the loan, its number and the decision history
 *   cancel     a draft, or an approved loan no money has moved on
 *
 * ⚠⚠ A submission with no workflow row would reach nobody (the expense-claim
 * lesson), so it is refused rather than left waiting for ever.
 *
 * ⚠ Self-approval follows the rest of the ERP (Rule #22): allowed, with a
 * reason, and marked in the history (LoanContract::isSelfApprovalBy).
 */
class LoanApprovals
{
    public function __construct(private readonly NotifyStageApprovers $notify) {}

    public function submit(LoanContract $loan, User $by): LoanContract
    {
        $this->assertWorkflow($loan, 'loan agreements');

        $request = DB::transaction(function () use ($loan, $by) {
            $loan = LoanContract::query()->lockForUpdate()->findOrFail($loan->id);

            if ($loan->status !== LoanStatus::Draft) {
                throw ValidationException::withMessages(['loan' => "{$loan->loan_number} is {$loan->status->label()}, not a draft."]);
            }

            if (! LoanScheduleRow::query()->where('loan_contract_id', $loan->id)->exists()) {
                throw ValidationException::withMessages(['loan' => "{$loan->loan_number} has no schedule yet. Save its terms first."]);
            }

            $loan->update(['status' => LoanStatus::PendingApproval, 'updated_by' => $by->id]);

            activity()->performedOn($loan)->causedBy($by)->log("Sent {$loan->loan_number} to the Owner for approval");

            return $loan->resubmitForApproval($by);
        });

        $loan->refresh();
        $this->notify->handle($request, "Loan {$loan->loan_number} ({$loan->counterparty?->legal_name}, {$loan->principal()->format()}) is waiting for your approval");

        return $loan;
    }

    /** @return bool whether the loan is now approved (false: it moved to a later stage) */
    public function approve(LoanContract $loan, User $by, ?string $note = null): bool
    {
        $approval = $this->pendingRequest($loan, $by);

        return DB::transaction(function () use ($loan, $approval, $by, $note) {
            if (! $approval->approve($by, $note)) {
                return false;
            }

            $loan = LoanContract::query()->lockForUpdate()->findOrFail($loan->id);

            if ($loan->status !== LoanStatus::PendingApproval) {
                throw ValidationException::withMessages(['loan' => "{$loan->loan_number} is no longer waiting for approval."]);
            }

            $loan->update(['status' => LoanStatus::Approved, 'approved_at' => now()]);

            LoanTermsVersion::query()->updateOrCreate(
                ['loan_contract_id' => $loan->id, 'version' => 1],
                [
                    'effective_from' => $loan->start_date->toDateString(),
                    'terms' => LoanTerms::fromContract($loan)->toArray(),
                    'reason' => 'The agreement as approved.',
                    'created_by' => $by->id,
                ],
            );

            activity()->performedOn($loan)->causedBy($by)->log("Approved {$loan->loan_number}");

            return true;
        });
    }

    public function sendBack(LoanContract $loan, User $by, string $note): void
    {
        $approval = $this->pendingRequest($loan, $by);

        DB::transaction(function () use ($loan, $approval, $by, $note) {
            $approval->sendBack($by, $note);
            $loan->update(['status' => LoanStatus::Draft]);
            activity()->performedOn($loan)->causedBy($by)->withProperties(['note' => $note])->log("Sent {$loan->loan_number} back as a draft");
        });
    }

    public function cancel(LoanContract $loan, User $by, string $reason): LoanContract
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Say why the loan is cancelled.']);
        }

        return DB::transaction(function () use ($loan, $by, $reason) {
            $loan = LoanContract::query()->lockForUpdate()->findOrFail($loan->id);

            if (! in_array($loan->status, [LoanStatus::Draft, LoanStatus::Approved], true)) {
                throw ValidationException::withMessages(['loan' => $loan->status === LoanStatus::PendingApproval
                    ? "{$loan->loan_number} is waiting for the Owner. Ask them to send it back first."
                    : "{$loan->loan_number} is {$loan->status->label()} and cannot be cancelled. Money has moved on it."]);
            }

            if (LoanEntry::query()->live()->where('loan_contract_id', $loan->id)->exists()) {
                throw ValidationException::withMessages(['loan' => "Money has moved on {$loan->loan_number}. Reverse it first, or settle the loan."]);
            }

            $loan->update(['status' => LoanStatus::Cancelled, 'cancel_reason' => $reason, 'updated_by' => $by->id]);
            activity()->performedOn($loan)->causedBy($by)->withProperties(['reason' => $reason])->log("Cancelled {$loan->loan_number}");

            return $loan;
        });
    }

    /** Refuses a submission that no workflow would carry to anybody. */
    public function assertWorkflow(Model $approvable, string $what): void
    {
        $exists = ApprovalWorkflow::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $approvable->business_id ?? null)
            ->where('key', $approvable->approvalWorkflowKey())
            ->where('is_active', true)
            ->whereHas('stages')
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages(['loan' => "Nobody is set up to approve {$what} yet. Ask the administrator to run the loan approval setup."]);
        }
    }

    private function pendingRequest(LoanContract $loan, User $by): ApprovalRequest
    {
        $approval = $loan->approvalRequest;

        abort_if($approval === null, 404);
        abort_unless($approval->canBeActionedBy($by), 403);

        return $approval;
    }
}
