<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Actions\Loans\LoanAmendments;
use App\Modules\Accounting\Actions\Loans\LoanWriteOffs;
use App\Modules\Accounting\Models\LoanAmendment;
use App\Modules\Accounting\Models\LoanContract;
use App\Modules\Accounting\Models\LoanWriteOff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Changes that need the Owner (loans plan v2, phase 2): a restructure
 * (amendment), and a principal write-off or interest waiver. Asking is
 * `accounting.loans.manage`; deciding is the approval stage's (the Owner).
 */
class LoanChangeController extends Controller
{
    public function requestAmendment(Request $request, LoanContract $loan, LoanAmendments $amendments): RedirectResponse
    {
        $data = $request->validate([
            'effective_from' => ['required', 'date'],
            'annual_rate' => ['nullable', 'string', 'max:12'],
            'interest_free_reason' => ['nullable', 'string', 'max:1000'],
            'day_count' => ['nullable', 'string', 'max:12'],
            'repayment_type' => ['required', 'string', 'max:20'],
            'frequency' => ['required', 'string', 'max:12'],
            'grace_months' => ['nullable', 'integer', 'min:0', 'max:600'],
            'maturity_date' => ['required', 'date'],
            'waterfall' => ['nullable', 'string', 'max:16'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $amendments->request($loan, $data, $request->user());

        return back()->with('success', "Restructure of {$loan->loan_number} sent to the Owner. The current terms stay until it is approved.");
    }

    public function approveAmendment(Request $request, LoanAmendment $amendment, LoanAmendments $amendments): RedirectResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null;

        try {
            $applied = $amendments->approve($amendment, $request->user(), $note);
        } catch (ValidationException $e) {
            return back()->with('error', 'Not approved: '.collect($e->errors())->flatten()->first());
        }

        return back()->with('success', $applied ? 'Restructure approved: the new terms and schedule are in force; the old ones are kept as history.' : 'Approved. Sent to the next stage.');
    }

    public function rejectAmendment(Request $request, LoanAmendment $amendment, LoanAmendments $amendments): RedirectResponse
    {
        $data = $request->validate(['review_note' => ['nullable', 'string', 'max:1000'], 'note' => ['nullable', 'string', 'max:1000']]);
        $amendments->reject($amendment, $request->user(), $data['review_note'] ?? $data['note'] ?? null);

        return back()->with('success', 'Restructure turned down. The loan keeps its terms.');
    }

    public function requestWriteOff(Request $request, LoanContract $loan, LoanWriteOffs $writeOffs): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'string', 'max:24'],
            'amount' => ['nullable', 'string', 'max:20'],
            'forgo' => ['nullable', 'string', 'max:20'],
            'effective_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
            'evidence' => ['required', 'array', 'min:1', 'max:5'],
            'evidence.*' => ['file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ]);

        $writeOff = $writeOffs->request($loan, $data, array_values($request->file('evidence', [])), $request->user());

        return back()->with('success', "{$writeOff->label()} on {$loan->loan_number} sent to the Owner. Nothing is booked until they approve.");
    }

    public function approveWriteOff(Request $request, LoanWriteOff $writeOff, LoanWriteOffs $writeOffs): RedirectResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null;

        try {
            $posted = $writeOffs->approve($writeOff, $request->user(), $note);
        } catch (ValidationException $e) {
            return back()->with('error', 'Not approved: '.collect($e->errors())->flatten()->first());
        }

        return back()->with('success', $posted ? "{$writeOff->label()} approved and booked." : 'Approved. Sent to the next stage.');
    }

    public function rejectWriteOff(Request $request, LoanWriteOff $writeOff, LoanWriteOffs $writeOffs): RedirectResponse
    {
        $data = $request->validate(['review_note' => ['nullable', 'string', 'max:1000'], 'note' => ['nullable', 'string', 'max:1000']]);
        $writeOffs->reject($writeOff, $request->user(), $data['review_note'] ?? $data['note'] ?? null);

        return back()->with('success', "{$writeOff->label()} turned down. Nothing was booked.");
    }
}
