<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Foundation\Corrections\CorrectionRequestStatus;
use App\Modules\Foundation\Corrections\CorrectionService;
use App\Modules\Foundation\Corrections\Exceptions\ClosedPeriodPassException;
use App\Modules\Foundation\Corrections\Exceptions\CorrectionBlockedException;
use App\Modules\Foundation\Corrections\Exceptions\StaleCorrectionException;
use App\Modules\Foundation\Corrections\ValidatesActionReason;
use App\Modules\Foundation\Models\CorrectionRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deciding a correction request (PHASE-0-CONTRACT v2, 2.3): approve, reject,
 * withdraw, through the existing approval workflow.
 *
 * ⚠⚠ APPROVING RE-CHECKS EVERYTHING (v2, C9). The final approval executes the
 * correction through CorrectionService::executeApproved, which locks and re-reads
 * the record. When the record changed, or the correction no longer passes a
 * rule, the decision is still recorded, the request is closed as "failed, the
 * record changed", and NOTHING is posted.
 */
class CorrectionRequestController extends Controller
{
    use ValidatesActionReason;

    public function approve(Request $request, CorrectionRequest $correctionRequest, CorrectionService $service): RedirectResponse
    {
        $approval = $correctionRequest->approvalRequest;
        abort_if($approval === null, 404);
        abort_unless($approval->canBeActionedBy($request->user()), 403);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $note = $data['note'] ?? null;

        try {
            $result = DB::transaction(function () use ($approval, $correctionRequest, $request, $service, $note) {
                $final = $approval->approve($request->user(), $note);

                return $final ? $service->executeApproved($correctionRequest, $request->user()) : null;
            });
        } catch (StaleCorrectionException|CorrectionBlockedException|ClosedPeriodPassException|ValidationException $e) {
            $message = $e instanceof ValidationException ? (string) collect($e->errors())->flatten()->first() : $e->getMessage();

            // The approver's decision is kept; the correction is closed as failed.
            DB::transaction(function () use ($approval, $correctionRequest, $request, $service, $note, $message) {
                $approval->refresh()->approve($request->user(), $note);
                $service->markFailed($correctionRequest->refresh(), $message);
            });

            CorrectionLog::event('request_failed', $request->user(), $correctionRequest, ['message' => $message]);

            return back()->with('error', "Approved, but nothing was posted: {$message}");
        }

        return back()->with('success', $result === null ? 'Approved. It waits for the next stage.' : "Approved. {$result->message}");
    }

    public function reject(Request $request, CorrectionRequest $correctionRequest): RedirectResponse
    {
        $approval = $correctionRequest->approvalRequest;
        abort_if($approval === null, 404);
        abort_unless($approval->canBeActionedBy($request->user()), 403);

        $data = $request->validate(['review_note' => ['required', 'string', 'min:3', 'max:1000']], ['review_note.required' => 'Say why it is rejected.']);

        DB::transaction(function () use ($approval, $correctionRequest, $request, $data) {
            $approval->reject($request->user(), $data['review_note']);
            $correctionRequest->forceFill(['status' => CorrectionRequestStatus::Rejected, 'decided_at' => now(), 'pending_key' => null])->save();
        });

        return back()->with('success', "{$correctionRequest->document_number}: the correction was rejected. Nothing changed.");
    }

    public function withdraw(Request $request, CorrectionRequest $correctionRequest): RedirectResponse
    {
        abort_unless((int) $correctionRequest->requested_by === $request->user()->id, 403);

        if (! $correctionRequest->isPending()) {
            throw ValidationException::withMessages(['reason_text' => 'This request was already decided.']);
        }

        $request->validate($this->actionReasonRules('withdrawal'), $this->actionReasonMessages());
        $reason = $this->actionReason($request);

        DB::transaction(function () use ($correctionRequest, $request, $reason) {
            // ⚠ The approval engine has no "withdrawn" state: the request is
            // closed as rejected with a note saying it was withdrawn and why
            // (the TransitionPayrollRun::withdrawApproval precedent).
            $correctionRequest->approvalRequest?->reject($request->user(), 'Withdrawn: '.$reason->display());
            $correctionRequest->forceFill(['status' => CorrectionRequestStatus::Withdrawn, 'decided_at' => now(), 'pending_key' => null])->save();
        });

        return back()->with('success', 'Withdrawn. Nothing changed.');
    }
}
