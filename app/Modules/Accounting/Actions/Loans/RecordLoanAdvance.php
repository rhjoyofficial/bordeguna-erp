<?php

namespace App\Modules\Accounting\Actions\Loans;

use App\Models\User;
use App\Modules\Accounting\Enums\LoanDirection;
use App\Modules\Accounting\Enums\LoanEntryKind;
use App\Modules\Accounting\Models\LoanContract;
use App\Modules\Accounting\Models\LoanEntry;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Loans\LoanPosition;
use App\Modules\Accounting\Support\Loans\LoanPosting;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\MoneyAvailability;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The principal moving at the start of a loan, from the loan page:
 *
 *   Given (disburse)        Dr principal head (counterparty, loan)
 *                               Cr the bank, cash or wallet it left from
 *   Taken (loan received)   Dr the bank it arrived in
 *                               Cr principal head (counterparty, loan)
 *
 * ⚠ Only an approved loan, never more than the approved principal in total
 * (several tranches are fine), never before it starts. A disbursement checks
 * the money is there (finance policy B14).
 */
class RecordLoanAdvance
{
    public function __construct(private readonly LoanPosting $posting) {}

    /**
     * @param  array<string, mixed>  $data  date, money_account_id, amount, note, submission_key
     */
    public function handle(LoanContract $loan, array $data, User $by): LoanEntry
    {
        // ⚠⚠ Bug hunt 16 Oct 2026: the limit was checked on a position read
        // BEFORE the loan was locked, so two people recording at once each
        // passed it and together moved more than the approved principal. The loan is
        // locked and re-read first; every check and every line below is built
        // from that locked position. LoanPosting::post() locks it again inside
        // this same transaction, which is harmless.
        return DB::transaction(fn () => $this->record(LoanContract::query()->lockForUpdate()->findOrFail($loan->id), $data, $by));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function record(LoanContract $loan, array $data, User $by): LoanEntry
    {
        if (! $loan->status->isLive()) {
            throw ValidationException::withMessages(['loan' => "{$loan->loan_number} is {$loan->status->label()}. Money moves only on an approved loan."]);
        }

        $date = LoanPosting::date($data['date'] ?? null, $loan);
        $money = LoanPosting::moneyAccount($data['money_account_id'] ?? null);

        try {
            $amount = Money::ofTaka((string) ($data['amount'] ?? ''));
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['amount' => 'Enter the amount in taka.']);
        }

        if ($amount->minor <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter the amount that moved.']);
        }

        $advanced = LoanPosition::of($loan)->advanced;
        $room = (int) $loan->principal_minor - $advanced;

        if ($amount->minor > $room) {
            throw ValidationException::withMessages(['amount' => 'That is more than the approved principal. '.Money::ofMinor(max(0, $room))->format().' is left to '.($loan->direction === LoanDirection::Given ? 'disburse' : 'receive').'; more money than that is a new loan agreement.']);
        }

        $given = $loan->direction === LoanDirection::Given;
        $party = $loan->counterparty;
        $note = trim((string) ($data['note'] ?? '')) ?: null;
        $memo = ($given ? 'Loan disbursed' : 'Loan received')." - {$loan->loan_number} {$party->legal_name}";

        if ($given) {
            MoneyAvailability::assertCanPay($money, $amount, $date, 'money_account_id');
        }

        $lines = $given
            ? [
                JournalLineDraft::debit((int) $loan->principal_account_id, $amount, $memo, $party, null, $loan->id),
                JournalLineDraft::credit((int) $money->account_id, $amount, $memo, null, $money),
            ]
            : [
                JournalLineDraft::debit((int) $money->account_id, $amount, $memo, null, $money),
                JournalLineDraft::credit((int) $loan->principal_account_id, $amount, $memo, $party, null, $loan->id),
            ];

        return $this->posting->post(
            $loan,
            $given ? LoanEntryKind::Disbursement : LoanEntryKind::Receipt,
            $date,
            $lines,
            [
                'money_account_id' => $money->id,
                'principal_minor' => $amount->minor,
                'cash_minor' => $amount->minor,
                'submission_key' => LoanPosting::submissionKey($data['submission_key'] ?? null),
                'note' => $note,
            ],
            $note === null ? $memo : "{$memo}: {$note}",
            $by,
        );
    }
}
