<?php

namespace App\Modules\Accounting\Actions\Loans;

use App\Models\User;
use App\Modules\Accounting\Enums\LoanDirection;
use App\Modules\Accounting\Enums\LoanEntryKind;
use App\Modules\Accounting\Enums\LoanStatus;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\LoanContract;
use App\Modules\Accounting\Models\LoanEntry;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Loans\LoanLedger;
use App\Modules\Accounting\Support\Loans\LoanMath;
use App\Modules\Accounting\Support\Loans\LoanPosition;
use App\Modules\Accounting\Support\Loans\LoanPosting;
use App\Modules\Accounting\Support\Loans\RepaymentPlan;
use App\Modules\Accounting\Support\Loans\TdsPolicy;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\MoneyAvailability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A repayment, from the loan page: received on a loan given, paid on a loan
 * taken. Partial, on time, late or early (settling the whole loan) alike.
 *
 *   GIVEN (we receive)                    TAKEN (we pay)
 *   Dr bank      gross - TDS withheld     Dr 2127  interest, up to what is accrued
 *   Dr 1190      TDS the borrower held    Dr 6478  the rest of the interest
 *   Cr 11753     interest, up to accrued  Dr 2128  principal, up to the current portion
 *   Cr 4920      the rest of the interest Dr 2210/2230/2126  the rest of the principal
 *   Cr 11754     principal, up to the         Cr 2180  TDS on the GROSS interest (rule on)
 *                current portion              Cr bank  gross interest - TDS + principal
 *   Cr 1270/11751/11752  the rest
 *
 * ⚠ The split follows the loan's waterfall (RepaymentPlan) unless the person
 * recording it gives the principal and interest parts themselves; interest is
 * never mixed into principal, and TDS is never treated as principal.
 */
class RecordLoanRepayment
{
    public function __construct(private readonly LoanPosting $posting) {}

    /**
     * @param  array<string, mixed>  $data  date, money_account_id, amount (gross) or principal + interest, tds_withheld (given), note, submission_key
     */
    public function handle(LoanContract $loan, array $data, User $by): LoanEntry
    {
        // ⚠⚠ Bug hunt 16 Oct 2026: the limit was checked on a position read
        // BEFORE the loan was locked, so two people recording at once each
        // passed it and together over-repaid the loan. The loan is
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
        if ($loan->status !== LoanStatus::Active) {
            throw ValidationException::withMessages(['loan' => "{$loan->loan_number} is {$loan->status->label()}: there is nothing to repay on it."]);
        }

        $date = LoanPosting::date($data['date'] ?? null, $loan);
        $money = LoanPosting::moneyAccount($data['money_account_id'] ?? null);
        $position = LoanPosition::of($loan);
        [$principal, $interest] = $this->split($loan, $position, $data, $date);

        if ($principal + $interest <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter what was repaid.']);
        }

        if ($principal > max(0, $position->principal)) {
            throw ValidationException::withMessages(['principal' => 'That is more principal than is owed. '.Money::ofMinor(max(0, $position->principal))->format().' is outstanding.']);
        }

        if ($interest > 0 && $interest > RepaymentPlan::maxInterest($position)) {
            throw ValidationException::withMessages(['interest' => 'That is more interest than the schedule and the accrual can account for ('.Money::ofMinor(RepaymentPlan::maxInterest($position))->format().'). Check the split.']);
        }

        $given = $loan->direction === LoanDirection::Given;
        $party = $loan->counterparty;
        $fromAccrued = min($interest, max(0, $position->accrued));
        $toProfitAndLoss = $interest - $fromAccrued;
        $currentHeld = max(0, LoanLedger::owedOn($loan, $loan->current_portion_account_id === null ? null : (int) $loan->current_portion_account_id));
        $fromCurrent = min($principal, $currentHeld);
        $fromPrincipalHead = $principal - $fromCurrent;

        if ($given) {
            $tds = $this->withheld($data['tds_withheld'] ?? null, $interest);
            $tdsRateE4 = null;
        } else {
            $rate = TdsPolicy::rateFor($loan, $date);
            $tdsRateE4 = $rate['rule'] === null ? null : $rate['rate_e4'];
            $tds = TdsPolicy::tdsOn($interest, $rate['rate_e4']);
        }

        $cash = Money::ofMinor($principal + $interest - $tds);
        $memo = ($given ? 'Loan repayment received' : 'Loan repayment paid')." - {$loan->loan_number} {$party->legal_name}";

        if (! $given) {
            MoneyAvailability::assertCanPay($money, $cash, $date, 'money_account_id');
        }

        $loanHead = fn (int $accountId, int $minor) => $given
            ? JournalLineDraft::credit($accountId, Money::ofMinor($minor), $memo, $party, null, $loan->id)
            : JournalLineDraft::debit($accountId, Money::ofMinor($minor), $memo, $party, null, $loan->id);
        $plain = fn (int $accountId, int $minor, string $text) => $given
            ? JournalLineDraft::credit($accountId, Money::ofMinor($minor), $text)
            : JournalLineDraft::debit($accountId, Money::ofMinor($minor), $text);

        $lines = [];

        if ($cash->minor > 0) {
            $lines[] = $given
                ? JournalLineDraft::debit((int) $money->account_id, $cash, $memo, null, $money)
                : JournalLineDraft::credit((int) $money->account_id, $cash, $memo, null, $money);
        }

        if ($tds > 0) {
            $lines[] = $given
                ? JournalLineDraft::debit($this->head('advance_income_tax')->id, Money::ofMinor($tds), "TDS withheld by {$party->legal_name} on interest - {$loan->loan_number}")
                : JournalLineDraft::credit($this->head('tds_payable')->id, Money::ofMinor($tds), "TDS on interest paid to {$party->legal_name} - {$loan->loan_number}");
        }

        if ($fromAccrued > 0) {
            $lines[] = $loanHead((int) $loan->interest_accrual_account_id, $fromAccrued);
        }

        if ($toProfitAndLoss > 0) {
            $lines[] = $plain((int) $loan->interest_pl_account_id, $toProfitAndLoss, "Interest - {$loan->loan_number} {$party->legal_name}");
        }

        if ($fromCurrent > 0) {
            $lines[] = $loanHead((int) $loan->current_portion_account_id, $fromCurrent);
        }

        if ($fromPrincipalHead > 0) {
            $lines[] = $loanHead((int) $loan->principal_account_id, $fromPrincipalHead);
        }

        $note = trim((string) ($data['note'] ?? '')) ?: null;

        return $this->posting->post(
            $loan,
            LoanEntryKind::Repayment,
            $date,
            $lines,
            [
                'money_account_id' => $money->id,
                'principal_minor' => -$principal,
                'accrued_interest_minor' => -$fromAccrued,
                'interest_pl_minor' => $toProfitAndLoss,
                'interest_settled_minor' => $interest,
                'tds_minor' => $tds,
                'tds_rate' => $tds > 0 && $tdsRateE4 !== null ? LoanMath::rateString($tdsRateE4) : null,
                'cash_minor' => $cash->minor,
                'submission_key' => LoanPosting::submissionKey($data['submission_key'] ?? null),
                'note' => $note,
            ],
            $note === null ? $memo : "{$memo}: {$note}",
            $by,
            fn (LoanEntry $entry, LoanPosition $before) => RepaymentPlan::allocate($entry, $before, $principal, $interest),
        );
    }

    /**
     * The principal and interest parts: as typed, or proposed from the gross
     * amount by the loan's waterfall.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: int, 1: int}
     */
    private function split(LoanContract $loan, LoanPosition $position, array $data, Carbon $date): array
    {
        $typed = ($data['principal'] ?? '') !== '' || ($data['interest'] ?? '') !== '';

        try {
            if ($typed) {
                $principal = Money::ofTaka((string) (($data['principal'] ?? '') === '' ? '0' : $data['principal']))->minor;
                $interest = Money::ofTaka((string) (($data['interest'] ?? '') === '' ? '0' : $data['interest']))->minor;
            } else {
                $amount = Money::ofTaka((string) ($data['amount'] ?? ''))->minor;
                $proposal = RepaymentPlan::propose($loan, $position, $amount, $date);
                [$principal, $interest] = [$proposal['principal'], $proposal['interest']];
            }
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['amount' => 'Enter the amounts in taka.']);
        }

        if ($principal < 0 || $interest < 0) {
            throw ValidationException::withMessages(['amount' => 'A repayment cannot be negative.']);
        }

        return [$principal, $interest];
    }

    private function withheld(mixed $value, int $interest): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        try {
            $tds = Money::ofTaka((string) $value)->minor;
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['tds_withheld' => 'Enter the tax withheld in taka.']);
        }

        if ($tds < 0 || $tds > $interest) {
            throw ValidationException::withMessages(['tds_withheld' => 'Tax withheld comes out of the interest, so it is between nothing and the interest part.']);
        }

        return $tds;
    }

    private function head(string $slug): Account
    {
        return Account::bySlug($slug) ?? throw ValidationException::withMessages(['loan' => "The chart has no {$slug} head. Run the migrations first."]);
    }
}
