<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hrm\Actions\DecideHrCorrectionRequest;
use App\Modules\Hrm\Models\HrCorrectionRequest;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ⭐ Corrections wave 3 (24 Oct 2026): the second person's approve and reject
 * for an HR cancellation or undo, from the approvals inbox card. Who may act
 * is the workflow stage's decision, never the requester's.
 */
class HrCorrectionRequestController extends Controller
{
    public function approve(Request $request, HrCorrectionRequest $hrCorrectionRequest, DecideHrCorrectionRequest $decide): RedirectResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        try {
            $result = $decide->approve($hrCorrectionRequest, $request->user(), $note);
        } catch (DomainException $e) {
            abort(403, $e->getMessage());
        }

        return back()->with($result['done'] || str_starts_with($result['message'], 'Approved.') ? 'success' : 'error', $result['message']);
    }

    public function reject(Request $request, HrCorrectionRequest $hrCorrectionRequest, DecideHrCorrectionRequest $decide): RedirectResponse
    {
        // The inbox sends `review_note` on a rejection.
        $note = $request->validate(['review_note' => ['nullable', 'string', 'max:500']])['review_note'] ?? null;

        try {
            $decide->reject($hrCorrectionRequest, $request->user(), $note);
        } catch (DomainException $e) {
            abort(403, $e->getMessage());
        }

        return back()->with('success', "Not approved. Nothing changed on {$hrCorrectionRequest->subject_label}.");
    }
}
