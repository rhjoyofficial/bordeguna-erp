<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Crm\Enums\TaskStatus;
use App\Modules\Crm\Models\CrmTask;
use App\Modules\Sales\Models\SampleIssue;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ⭐ The approver's yes or no on a sample hand-out (Samples, 25 Oct 2026),
 * through the existing approvals engine (workflow `sample_issue`).
 *
 *   approve()  the decision first; the goods move and the ledger posts only
 *              when it was the LAST stage (PostSampleIssue), all in one
 *              transaction, so a refused posting (stock gone, a closed month)
 *              leaves the request waiting as it was
 *   reject()   nothing ever moved; the sample is marked rejected and its CRM
 *              follow-up, if it made one, is cancelled
 */
class ApproveSampleIssue
{
    public function __construct(private readonly PostSampleIssue $post) {}

    public function approve(SampleIssue $issue, User $by, ?string $note = null): SampleIssue
    {
        return DB::transaction(function () use ($issue, $by, $note) {
            $issue = SampleIssue::query()->whereKey($issue->id)->lockForUpdate()->with('approvalRequest')->firstOrFail();

            if (! $issue->isWaiting() || $issue->approvalRequest === null || ! $issue->approvalRequest->isPending()) {
                throw new RuntimeException("Sample {$issue->number} has already been decided.");
            }

            if (! $issue->approvalRequest->approve($by, $note)) {
                return $issue->fresh(['approvalRequest', 'lines']);
            }

            return $this->post->handle($issue, $by);
        });
    }

    public function reject(SampleIssue $issue, User $by, ?string $note = null): SampleIssue
    {
        return DB::transaction(function () use ($issue, $by, $note) {
            $issue = SampleIssue::query()->whereKey($issue->id)->lockForUpdate()->with('approvalRequest')->firstOrFail();

            if (! $issue->isWaiting() || $issue->approvalRequest === null || ! $issue->approvalRequest->isPending()) {
                throw new RuntimeException("Sample {$issue->number} has already been decided.");
            }

            $issue->approvalRequest->reject($by, $note);
            $issue->update(['status' => SampleIssue::REJECTED]);

            if ($issue->crm_task_id !== null) {
                CrmTask::query()->whereKey($issue->crm_task_id)->where('status', TaskStatus::Open)->update(['status' => TaskStatus::Cancelled]);
            }

            return $issue->fresh(['approvalRequest']);
        });
    }
}
