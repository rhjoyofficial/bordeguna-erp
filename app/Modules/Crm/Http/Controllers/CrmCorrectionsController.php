<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Actions\ChangeCrmActivity;
use App\Modules\Crm\Actions\CorrectLead;
use App\Modules\Crm\Actions\DeleteContact;
use App\Modules\Crm\Actions\ReopenOpportunity;
use App\Modules\Crm\Actions\ReverseContactImport;
use App\Modules\Crm\Enums\ActivityDirection;
use App\Modules\Crm\Models\Contact;
use App\Modules\Crm\Models\ContactImportBatch;
use App\Modules\Crm\Models\CrmActivity;
use App\Modules\Crm\Models\Lead;
use App\Modules\Foundation\Corrections\ValidatesActionReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ⭐ The corrections of CRM (corrections wave 3, 24 Oct 2026; FINAL-PLAN 3.2
 * and 6.3). One controller for the CRM actions that post nothing:
 *
 *   activity   Edit (author, 24 hours), Delete (author, 24 hours, the bin),
 *              Correct (author, after that)
 *   lead       Reverse conversion, Reopen, Delete (the bin)
 *   deal       Reopen
 *   contact    Delete (the bin)
 *   import     Reverse import, with a preview
 *
 * ⚠⚠ The actions decide everything (each asks its policy again under the row
 * lock); this checks the shape of the request and the reason, server side,
 * so a request sent without the screen still needs one (FINAL-PLAN 6.1).
 *
 * ⚠ Every record is bound through its SCOPED model: a lead, or an entry on a
 * lead, outside the reader's own and team's leads is a 404 here exactly as on
 * its page (Sales Officer and Sales Lead see only their own).
 */
class CrmCorrectionsController extends Controller
{
    use ValidatesActionReason;

    public function editActivity(Request $request, CrmActivity $activity, ChangeCrmActivity $action): RedirectResponse
    {
        $data = $request->validate($this->activityRules(), [], ['occurred_at' => 'date']);

        $action->edit($activity, $data, $request->user(), $data['seen'] ?? null);

        return back()->with('success', 'Entry updated. History keeps what it said before.');
    }

    public function correctActivity(Request $request, CrmActivity $activity, ChangeCrmActivity $action): RedirectResponse
    {
        $data = $request->validate([...$this->activityRules(), ...$this->actionReasonRules('correction')], $this->actionReasonMessages());

        $action->correct($activity, $data, $request->user(), $this->actionReason($request), $data['seen'] ?? null);

        return back()->with('success', 'Corrected. The old entry stays, struck through, with your reason.');
    }

    public function deleteActivity(Request $request, CrmActivity $activity, ChangeCrmActivity $action): RedirectResponse
    {
        $request->validate($this->actionReasonRules('deletion'), $this->actionReasonMessages());

        $action->delete($activity, $request->user(), $this->actionReason($request));

        return back()->with('success', 'Moved to the Recycle bin. The Owner can restore it.');
    }

    public function reverseConversion(Request $request, Lead $lead, CorrectLead $action): RedirectResponse
    {
        $request->validate($this->actionReasonRules('reversal'), $this->actionReasonMessages());

        $action->reverseConversion($lead, $request->user(), $this->actionReason($request));

        return back()->with('success', 'Conversion reversed. The lead is open again.');
    }

    public function reopenLead(Request $request, Lead $lead, CorrectLead $action): RedirectResponse
    {
        $request->validate($this->actionReasonRules('correction'), $this->actionReasonMessages());

        $action->reopen($lead, $request->user(), $this->actionReason($request));

        return back()->with('success', 'Lead reopened.');
    }

    public function deleteLead(Request $request, Lead $lead, CorrectLead $action): RedirectResponse
    {
        $request->validate($this->actionReasonRules('deletion'), $this->actionReasonMessages());

        $cancelled = $action->delete($lead, $request->user(), $this->actionReason($request));

        return redirect()->route('crm.leads.index')->with('success', 'Lead moved to the Recycle bin. The Owner can restore it.'
            .($cancelled > 0 ? " {$cancelled} open follow-up(s) were cancelled with it." : ''));
    }

    public function reopenOpportunity(Request $request, int $opportunity, ReopenOpportunity $action): RedirectResponse
    {
        $request->validate($this->actionReasonRules('correction'), $this->actionReasonMessages());

        $action->handle($opportunity, $request->user(), $this->actionReason($request));

        return back()->with('success', 'Deal reopened. It is back on the board.');
    }

    public function deleteContact(Request $request, Contact $contact, DeleteContact $action): RedirectResponse
    {
        $request->validate($this->actionReasonRules('deletion'), $this->actionReasonMessages());

        $action->handle($contact, $request->user(), $this->actionReason($request));

        return back()->with('success', 'Contact moved to the Recycle bin. The Owner can restore it.');
    }

    /** The preview: what Reverse import would remove, put back and keep. Writes nothing. */
    public function previewImportReversal(ContactImportBatch $batch, ReverseContactImport $action): JsonResponse
    {
        return response()->json($action->plan($batch) + ['reversed' => $batch->isReversed()]);
    }

    public function reverseImport(Request $request, ContactImportBatch $batch, ReverseContactImport $action): RedirectResponse
    {
        $request->validate($this->actionReasonRules('reversal'), $this->actionReasonMessages());

        $plan = $action->handle($batch, $request->user(), $this->actionReason($request));

        return back()->with('success', sprintf(
            'Import reversed: %d removed, %d put back, %d kept.',
            count($plan['remove']), count($plan['restore']), count($plan['kept']),
        ));
    }

    /** The fields of an entry that may change; the proof (kind, place, photos) never does. */
    private function activityRules(): array
    {
        return [
            'occurred_at' => ['nullable', 'date'],
            'direction' => ['nullable', Rule::enum(ActivityDirection::class)],
            'summary' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'outcome' => ['nullable', 'string', 'max:30'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            // The entry's updated_at as the screen showed it (the stale guard).
            'seen' => ['nullable', 'string', 'max:30'],
        ];
    }
}
