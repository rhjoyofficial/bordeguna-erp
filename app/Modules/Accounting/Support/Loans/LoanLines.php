<?php

namespace App\Modules\Accounting\Support\Loans;

use App\Modules\Accounting\Enums\LoanStatus;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Counterparty;
use App\Modules\Accounting\Models\LoanContract;
use App\Modules\Accounting\Support\PartyLedgers;
use Illuminate\Database\Eloquent\Model;

/**
 * ⚠⚠ THE LOAN REGISTER'S POSTING RULE (loans plan v2, phase 2; ChatGPT
 * confirmation, "unauthorised direct postings to loan heads").
 *
 *  1. A line that NAMES a loan must fit it: the loan is this business's, the
 *     Owner approved it, the line's counterparty is the loan's, and the head
 *     is one of the loan's own (principal, its current portion, accrued
 *     interest).
 *  2. A line on a loan head for a counterparty with an ACTIVE register loan on
 *     that head (approved or running) MUST name it: otherwise money would move
 *     on the loan's ledger with nothing in the register to say which loan, and
 *     the two would drift apart without anybody noticing.
 *  3. A counterparty with NO register loan is booked by hand exactly as
 *     before: the Tycoon one-off stays a plain voucher.
 *
 * Checked in PostJournal for every writer (reversals exempt: they undo exactly
 * what was posted), and again on the voucher screens so the sentence lands on
 * the line's own picker.
 *
 * ⚠ COUNTERPARTY ISOLATION PER BUSINESS: a counterparty is group-wide, a loan
 * is not. LaLaDia's lines are never forced to name Bor de Guna's loan, and can
 * never name it.
 */
final class LoanLines
{
    /** Why this line cannot be posted as it stands, or null when it can. */
    public static function refusal(Account $account, ?Model $party, ?int $loanId, int $businessId, bool $insistOnLoan = true): ?string
    {
        $isLoanHead = in_array($account->system_slug, LoanHeads::allLoanSlugs(), true);

        if ($loanId !== null) {
            return self::fitRefusal($account, $party, $loanId, $businessId, $isLoanHead);
        }

        if (! $insistOnLoan || ! $isLoanHead || ! $party instanceof Counterparty) {
            return null;
        }

        $loans = self::liveLoansOn($party->id, $businessId, $account->id);

        if ($loans === []) {
            return null;
        }

        $numbers = implode(', ', $loans);

        return "{$party->legal_name} has an active register loan on {$account->label()} ({$numbers}). Name the loan on this line, so the Loan Register and the ledger stay one record.";
    }

    /**
     * The numbers of this counterparty's approved or running loans in this
     * business that sit on this head.
     *
     * @return list<string>
     */
    public static function liveLoansOn(int $counterpartyId, int $businessId, int $accountId): array
    {
        return LoanContract::withoutGlobalScopes()
            ->where('business_id', $businessId)
            ->where('counterparty_id', $counterpartyId)
            ->whereIn('status', LoanStatus::liveValues())
            ->orderBy('loan_number')
            ->get()
            ->filter(fn (LoanContract $loan) => in_array($accountId, $loan->headIds(), true))
            ->pluck('loan_number')
            ->values()
            ->all();
    }

    private static function fitRefusal(Account $account, ?Model $party, int $loanId, int $businessId, bool $isLoanHead): ?string
    {
        if (! $isLoanHead) {
            return "{$account->label()} is not a loan head, so a line on it cannot name a loan.";
        }

        $loan = LoanContract::withoutGlobalScopes()->with('counterparty')->find($loanId);

        if ($loan === null) {
            return 'That loan is not in the Loan Register.';
        }

        if ((int) $loan->business_id !== $businessId) {
            return "{$loan->loan_number} belongs to another business. Each business keeps its own loans; money between our businesses goes through Inter-business transfers.";
        }

        if (! $loan->status->wasApproved()) {
            return "{$loan->loan_number} is {$loan->status->label()}. A loan takes postings only once the Owner has approved it.";
        }

        if (! $party instanceof Counterparty || $party->id !== (int) $loan->counterparty_id) {
            $named = $party === null ? 'nobody' : PartyLedgers::label($party);

            return "{$loan->loan_number} is with {$loan->counterparty?->legal_name}, but this line names {$named}.";
        }

        if (! in_array($account->id, $loan->headIds(), true)) {
            $heads = Account::query()->whereIn('id', $loan->headIds())->get()->map->label()->implode(', ');

            return "{$loan->loan_number} is kept on {$heads}, not on {$account->label()}.";
        }

        return null;
    }
}
