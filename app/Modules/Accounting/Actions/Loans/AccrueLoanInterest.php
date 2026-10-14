<?php

namespace App\Modules\Accounting\Actions\Loans;

use App\Models\User;
use App\Modules\Accounting\Enums\LoanDirection;
use App\Modules\Accounting\Enums\LoanEntryKind;
use App\Modules\Accounting\Enums\LoanStatus;
use App\Modules\Accounting\Models\LoanContract;
use App\Modules\Accounting\Models\LoanEntry;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Loans\LoanInterest;
use App\Modules\Accounting\Support\Loans\LoanPosting;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\AccountingPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Month-end "Accrue interest" (loans plan v2, phase 2; ChatGPT confirmation,
 * point 8): previewed, then posted by a person, for one loan or every active
 * interest-bearing loan.
 *
 *   Given   Dr 11753 Interest Receivable (counterparty, loan) / Cr 4920 Interest Income
 *   Taken   Dr 6478 Interest Expense / Cr 2127 Interest Payable (counterparty, loan)
 *
 * ⚠⚠ NEVER TWICE. Each run works out what the month's interest SHOULD be now
 * (LoanInterest: actual days, the terms in force each day, rounded once) and
 * posts only the difference from what is already accrued for that month:
 *
 *  - first run of the month: the month's interest, keyed loan + month + terms
 *    version, unique while live, so a rerun or a second person finds it taken;
 *  - a rerun with nothing changed: nothing;
 *  - an amendment (a new terms version) or a backdated repayment: a CONTROLLED
 *    ADJUSTMENT for the difference, keyed by the new total. A posted accrual is
 *    never rewritten.
 *
 * ⚠ Dated the month's last day; if that month is closed, the first open day
 * after it (the adjustment lands in an open period, keyed to its month).
 */
class AccrueLoanInterest
{
    public function __construct(private readonly LoanPosting $posting) {}

    /**
     * What the month would post for each loan: computed, already accrued, and
     * the difference.
     *
     * @param  Collection<int, LoanContract>|null  $loans
     * @return list<array{loan: LoanContract, computed: int, accrued: int, difference: int, date: ?string, note: ?string}>
     */
    public function preview(string $period, ?Collection $loans = null): array
    {
        [$start, $end] = self::month($period);
        $loans ??= self::candidates($start);
        $date = $this->postingDate($end);

        return $loans->map(function (LoanContract $loan) use ($start, $end, $period, $date) {
            $computed = LoanInterest::forPeriod($loan, $start, $end);
            $accrued = self::accruedFor($loan, $period);

            return [
                'loan' => $loan,
                'computed' => $computed,
                'accrued' => $accrued,
                'difference' => $computed - $accrued,
                'date' => $date['date'],
                'note' => $date['note'],
            ];
        })->values()->all();
    }

    /** Posts the month's interest (or its adjustment) for one loan; null when there is nothing to post. */
    public function handle(LoanContract $loan, string $period, User $by): ?LoanEntry
    {
        [$start, $end] = self::month($period);

        if (! in_array($loan->status, [LoanStatus::Active, LoanStatus::Settled, LoanStatus::WrittenOff], true)) {
            throw ValidationException::withMessages(['period' => "{$loan->loan_number} is {$loan->status->label()}: no interest runs on it."]);
        }

        $computed = LoanInterest::forPeriod($loan, $start, $end);
        $accrued = self::accruedFor($loan, $period);
        $difference = $computed - $accrued;

        if ($difference === 0) {
            return null;
        }

        $date = $this->postingDate($end);

        if ($date['date'] === null) {
            throw ValidationException::withMessages(['period' => $date['note']]);
        }

        $version = (int) $loan->terms_version;
        $baseKey = "{$loan->id}:{$period}:v{$version}";
        $baseTaken = LoanEntry::query()->live()->where('accrual_key', $baseKey)->exists();
        $first = $accrued === 0 && ! $baseTaken;
        $key = $baseTaken ? "{$baseKey}:t{$computed}" : $baseKey;
        $kind = $first ? LoanEntryKind::Accrual : LoanEntryKind::AccrualAdjustment;

        $party = $loan->counterparty;
        $amount = Money::ofMinor(abs($difference));
        $what = $first ? 'Interest accrued' : 'Interest accrual adjusted';
        $memo = "{$what} for {$start->format('F Y')} - {$loan->loan_number} {$party->legal_name}";
        $increase = $difference > 0;
        $given = $loan->direction === LoanDirection::Given;

        // Given: the receivable is the debit side; taken: the payable is the credit side.
        $accrualLine = $given === $increase
            ? JournalLineDraft::debit((int) $loan->interest_accrual_account_id, $amount, $memo, $party, null, $loan->id)
            : JournalLineDraft::credit((int) $loan->interest_accrual_account_id, $amount, $memo, $party, null, $loan->id);
        $profitAndLossLine = $given === $increase
            ? JournalLineDraft::credit((int) $loan->interest_pl_account_id, $amount, $memo)
            : JournalLineDraft::debit((int) $loan->interest_pl_account_id, $amount, $memo);

        return $this->posting->post(
            $loan,
            $kind,
            Carbon::parse($date['date']),
            $given ? [$accrualLine, $profitAndLossLine] : [$profitAndLossLine, $accrualLine],
            [
                'accrued_interest_minor' => $difference,
                'interest_pl_minor' => $difference,
                'accrual_period' => $period,
                'accrual_version' => $version,
                'accrual_key' => $key,
                'note' => $first ? null : 'Recomputed: '.Money::ofMinor($computed)->format().' for the month, '.Money::ofMinor($accrued)->format().' already accrued.',
            ],
            $memo,
            $by,
        );
    }

    /**
     * Every loan with interest that could run in a month: approved once,
     * interest-bearing at some point, and with money moved by the month's end.
     *
     * @return Collection<int, LoanContract>
     */
    public static function candidates(Carbon $monthStart): Collection
    {
        return LoanContract::query()
            ->with('counterparty')
            ->whereIn('status', [LoanStatus::Active->value, LoanStatus::Settled->value, LoanStatus::WrittenOff->value])
            ->whereDate('start_date', '<=', $monthStart->copy()->endOfMonth()->toDateString())
            ->where(fn ($q) => $q->whereNull('settled_on')->orWhereDate('settled_on', '>=', $monthStart->toDateString()))
            ->orderBy('loan_number')
            ->get()
            ->filter(fn (LoanContract $loan) => $loan->isInterestBearing() || $loan->terms_version > 1)
            ->values();
    }

    /** Interest already accrued (live) for the loan and month, every version together. */
    public static function accruedFor(LoanContract $loan, string $period): int
    {
        return (int) LoanEntry::query()->live()
            ->where('loan_contract_id', $loan->id)
            ->where('accrual_period', $period)
            ->whereIn('kind', [LoanEntryKind::Accrual->value, LoanEntryKind::AccrualAdjustment->value])
            ->sum('accrued_interest_minor');
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public static function month(string $period): array
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $period)) {
            throw ValidationException::withMessages(['period' => 'Choose a month.']);
        }

        $start = Carbon::parse("{$period}-01")->startOfDay();

        return [$start, $start->copy()->endOfMonth()->startOfDay()];
    }

    /**
     * @return array{date: ?string, note: ?string}
     */
    private function postingDate(Carbon $monthEnd): array
    {
        if ($monthEnd->isAfter(Carbon::today())) {
            return ['date' => null, 'note' => "{$monthEnd->format('F Y')} has not ended yet. Interest is accrued once the month is over."];
        }

        try {
            AccountingPeriod::assertOpenFor($monthEnd);

            return ['date' => $monthEnd->toDateString(), 'note' => null];
        } catch (PeriodClosedException) {
            try {
                $open = AccountingPeriod::firstOpenOnOrAfter($monthEnd);
            } catch (PeriodClosedException $e) {
                return ['date' => null, 'note' => $e->getMessage()];
            }

            return ['date' => $open->toDateString(), 'note' => "{$monthEnd->format('F Y')} is closed, so this posts on {$open->format('j M Y')}, the first open day."];
        }
    }
}
