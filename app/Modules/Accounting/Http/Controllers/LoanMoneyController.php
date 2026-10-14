<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Actions\Loans\AccrueLoanInterest;
use App\Modules\Accounting\Actions\Loans\RecordLoanAdvance;
use App\Modules\Accounting\Actions\Loans\RecordLoanRepayment;
use App\Modules\Accounting\Actions\Loans\ReverseLoanEntry;
use App\Modules\Accounting\Enums\LoanDirection;
use App\Modules\Accounting\Models\LoanContract;
use App\Modules\Accounting\Models\LoanEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Money through the loan page (loans plan v2, phase 2): disburse or receive
 * the principal, record a repayment, accrue a month's interest, reverse a
 * movement. Each is one person's action, posted through PostJournal and linked
 * to the loan once (Support\Loans\LoanPosting). `accounting.loans.manage`.
 */
class LoanMoneyController extends Controller
{
    public function advance(Request $request, LoanContract $loan, RecordLoanAdvance $record): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'money_account_id' => ['required', 'integer'],
            'amount' => ['required', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:500'],
            'submission_key' => ['nullable', 'string', 'max:64'],
        ]);

        $entry = $record->handle($loan, $data, $request->user());
        $what = $loan->direction === LoanDirection::Given ? 'disbursed' : 'received';

        return back()->with('success', "{$entry->cash()->format()} {$what} on {$loan->loan_number} ({$entry->journal?->journal_number}).");
    }

    public function repay(Request $request, LoanContract $loan, RecordLoanRepayment $record): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'money_account_id' => ['required', 'integer'],
            'amount' => ['nullable', 'string', 'max:20'],
            'principal' => ['nullable', 'string', 'max:20'],
            'interest' => ['nullable', 'string', 'max:20'],
            'tds_withheld' => ['nullable', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:500'],
            'submission_key' => ['nullable', 'string', 'max:64'],
        ]);

        $entry = $record->handle($loan, $data, $request->user());

        return back()->with('success', "Repayment of {$entry->cash()->format()} recorded on {$loan->loan_number} ({$entry->journal?->journal_number}).");
    }

    public function accrue(Request $request, LoanContract $loan, AccrueLoanInterest $accrue): RedirectResponse
    {
        $data = $request->validate(['period' => ['required', 'string', 'size:7']]);
        $entry = $accrue->handle($loan, $data['period'], $request->user());

        return back()->with('success', $entry === null
            ? "Nothing to accrue: {$loan->loan_number}'s interest for {$data['period']} is already booked in full."
            : "Interest for {$data['period']} booked on {$loan->loan_number} ({$entry->journal?->journal_number}).");
    }

    public function reverse(Request $request, LoanEntry $entry, ReverseLoanEntry $reverse): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $entry = $reverse->handle($entry, $request->user(), $data['reason']);

        return back()->with('success', 'Reversed'.($entry->reversalJournal ? " by {$entry->reversalJournal->journal_number}" : '').'. The installments it settled are open again.');
    }
}
