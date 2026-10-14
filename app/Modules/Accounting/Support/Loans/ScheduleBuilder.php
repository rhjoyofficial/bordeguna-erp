<?php

namespace App\Modules\Accounting\Support\Loans;

use App\Modules\Accounting\Enums\LoanFrequency;
use App\Modules\Accounting\Enums\LoanRepaymentType;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * The installments a loan's terms produce (loans plan v2, phase 2).
 *
 *  - EQUAL INSTALLMENTS: interest on the DECLINING balance, at the rate and day
 *    count, for the actual days of each period; the installment is the level
 *    annuity amount for the periodic rate, its principal part is what is left
 *    after that period's interest, and the last installment takes whatever
 *    principal remains, so the principal always adds up to the paisa.
 *  - BULLET: interest each period (or once, at maturity), all principal on the
 *    maturity date.
 *  - GRACE MONTHS: periods inside the grace carry interest only; principal
 *    starts after it.
 *  - CUSTOM: rows typed in by hand (validated by Actions\Loans\SaveLoanContract).
 *
 * ⚠ Due dates step from the start date by whole months without overflow
 * (31 Jan -> 28 Feb), and the last row is always the maturity date.
 *
 * ⚠ The schedule is the CONTRACT, never the accounting: interest that is
 * actually earned or owed is accrued from the real daily balance
 * (LoanInterest), whatever the schedule said.
 */
final class ScheduleBuilder
{
    /**
     * @param  int  $arrearsInterestMinor  unpaid interest carried from an
     *                                     earlier schedule (a restructure) onto
     *                                     the first row, never into principal
     * @return list<array{due_date: string, principal_minor: int, interest_minor: int}>
     */
    public static function build(LoanTerms $terms, int $principalMinor, Carbon $from, int $arrearsInterestMinor = 0): array
    {
        if ($principalMinor <= 0) {
            return [];
        }

        $from = $from->copy()->startOfDay();
        $dates = self::dueDates($from, $terms->maturityDate, $terms->frequency);

        $rows = match ($terms->repaymentType) {
            LoanRepaymentType::EqualInstallments => self::equal($terms, $principalMinor, $from, $dates),
            LoanRepaymentType::Bullet => self::bullet($terms, $principalMinor, $from, $dates),
            LoanRepaymentType::Custom => throw new InvalidArgumentException('A custom schedule is typed in, not generated.'),
        };

        if ($arrearsInterestMinor > 0 && $rows !== []) {
            $rows[0]['interest_minor'] += $arrearsInterestMinor;
        }

        return $rows;
    }

    /**
     * Every due date from the day after $from to maturity.
     *
     * @return list<Carbon>
     */
    public static function dueDates(Carbon $from, Carbon $maturity, LoanFrequency $frequency): array
    {
        $step = $frequency->months();
        $dates = [];

        if ($step !== null) {
            for ($k = 1; ; $k++) {
                $date = $from->copy()->addMonthsNoOverflow($k * $step);

                if ($date->greaterThanOrEqualTo($maturity)) {
                    break;
                }

                $dates[] = $date;
            }
        }

        $dates[] = $maturity->copy()->startOfDay();

        return $dates;
    }

    /**
     * Interest on custom rows: the declining balance between the rows' dates.
     * Used when a custom row is typed without interest.
     *
     * @param  list<array{due_date: string, principal_minor: int}>  $rows  sorted by date
     * @return list<int>
     */
    public static function interestFor(LoanTerms $terms, int $principalMinor, Carbon $from, array $rows): array
    {
        $balance = $principalMinor;
        $previous = $from->copy()->startOfDay();
        $interest = [];

        foreach ($rows as $row) {
            $date = Carbon::parse($row['due_date'])->startOfDay();
            $days = (int) $previous->diffInDays($date);
            $interest[] = LoanMath::interest($balance * $days, $terms->rateE4, $terms->basis());
            $balance -= (int) $row['principal_minor'];
            $previous = $date;
        }

        return $interest;
    }

    /**
     * @param  list<Carbon>  $dates
     * @return list<array{due_date: string, principal_minor: int, interest_minor: int}>
     */
    private static function bullet(LoanTerms $terms, int $principal, Carbon $from, array $dates): array
    {
        $rows = [];
        $previous = $from;
        $last = count($dates) - 1;

        foreach ($dates as $index => $date) {
            $days = (int) $previous->diffInDays($date);

            $rows[] = [
                'due_date' => $date->toDateString(),
                'principal_minor' => $index === $last ? $principal : 0,
                'interest_minor' => LoanMath::interest($principal * $days, $terms->rateE4, $terms->basis()),
            ];

            $previous = $date;
        }

        return $rows;
    }

    /**
     * @param  list<Carbon>  $dates
     * @return list<array{due_date: string, principal_minor: int, interest_minor: int}>
     */
    private static function equal(LoanTerms $terms, int $principal, Carbon $from, array $dates): array
    {
        $graceEnds = $from->copy()->addMonthsNoOverflow($terms->graceMonths);
        $principalIndexes = [];

        foreach ($dates as $index => $date) {
            if ($date->greaterThan($graceEnds)) {
                $principalIndexes[] = $index;
            }
        }

        if ($principalIndexes === []) {
            $principalIndexes = [count($dates) - 1];
        }

        $installment = self::installment($principal, $terms->rateE4, $terms->frequency->months() ?? 12, count($principalIndexes));
        $lastPrincipal = end($principalIndexes);

        $rows = [];
        $balance = $principal;
        $previous = $from;

        foreach ($dates as $index => $date) {
            $days = (int) $previous->diffInDays($date);
            $interest = LoanMath::interest($balance * $days, $terms->rateE4, $terms->basis());

            $principalDue = 0;

            if ($index === $lastPrincipal) {
                $principalDue = $balance;
            } elseif (in_array($index, $principalIndexes, true)) {
                $principalDue = max(0, min($balance, $installment - $interest));
            }

            $balance -= $principalDue;

            $rows[] = [
                'due_date' => $date->toDateString(),
                'principal_minor' => $principalDue,
                'interest_minor' => $interest,
            ];

            $previous = $date;
        }

        return $rows;
    }

    /**
     * The level installment for a periodic rate: P r / (1 - (1 + r)^-n), in
     * paisa. A target, not a ledger figure - the last row absorbs every paisa
     * of difference - so floating point is acceptable here and nowhere else.
     */
    public static function installment(int $principal, int $rateE4, int $monthsPerPeriod, int $periods): int
    {
        if ($periods <= 0) {
            return $principal;
        }

        $rate = ($rateE4 / LoanMath::WHOLE) * $monthsPerPeriod / 12;

        if ($rate <= 0) {
            return (int) round($principal / $periods);
        }

        return (int) round($principal * $rate / (1 - (1 + $rate) ** (-$periods)));
    }
}
