<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Actions\ApplyChangeRequest;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Foundation\Models\ChangeRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The generic, workflow-driven approve/reject endpoint for change requests —
 * subject-agnostic. Authorisation is the engine's stage check (canBeActionedBy),
 * so whoever the current stage allows may act, whatever module owns the subject.
 * A final approval applies the change through the subject's own applier; a
 * non-final approval advances the request and notifies the next stage.
 */
class ChangeRequestController extends Controller
{
    public function approve(
        Request $request,
        ChangeRequest $changeRequest,
        ApplyChangeRequest $applyChangeRequest,
        NotifyStageApprovers $notifyStageApprovers,
    ): RedirectResponse {
        $approvalRequest = $changeRequest->approvalRequest;
        abort_unless($approvalRequest !== null && $approvalRequest->canBeActionedBy($request->user()), 403);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        $completed = $approvalRequest->approve($request->user(), $data['note'] ?? null);

        if ($completed) {
            $applyChangeRequest->handle($changeRequest);

            return back()->with('success', 'Change request approved and applied.');
        }

        // Advanced to the next stage — tell its approvers.
        $notifyStageApprovers->handle($approvalRequest->fresh(), $changeRequest->summaryLine());

        return back()->with('success', 'Approved — sent to the next approver.');
    }

    public function reject(Request $request, ChangeRequest $changeRequest): RedirectResponse
    {
        $approvalRequest = $changeRequest->approvalRequest;
        abort_unless($approvalRequest !== null && $approvalRequest->canBeActionedBy($request->user()), 403);

        $data = $request->validate(['review_note' => ['nullable', 'string', 'max:1000']]);

        $approvalRequest->reject($request->user(), $data['review_note'] ?? null);

        return back()->with('success', 'Change request rejected.');
    }
}
