<?php

namespace App\Modules\Accounting\Support\Loans;

use App\Modules\Accounting\Models\LoanContract;
use App\Modules\Accounting\Models\LoanEntry;
use App\Modules\Accounting\Models\LoanTermsVersion;
use Illuminate\Support\Carbon;

/**
 * Interest a loan actually earns or costs over a stretch of days (loans plan
 * v2, phase 2; ChatGPT confirmation, point 8):
 *
 *  - DAY COUNT: each day carries the principal owed at the END of that day, so
 *    money lent on the 1st earns from the 1st and money repaid on the 31st
 *    stops earning on the 31st. Actual/365 by default (or /360 by the terms).
 *  - RATE: the terms version in force on that day, so an amendment from the
 *    15th changes the rate from the 15th.
 *  - ROUNDING: once, to the paisa, per rate in the period (never per day).
 *  - START AND STOP: nothing before the first money moves; nothing once the
 *    principal is back to zero, so a settled loan stops accruing by itself.
 *  - OVERDUE: overdue principal is still owed and keeps earning at the
 *    contract rate. There is no penalty rate in this phase.
 */
final class LoanInterest
{
    public static function forPeriod(LoanContract $loan, Carbon $from, Carbon $to): int
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();

        if ($to->lessThan($from)) {
            return 0;
        }

        $entries = LoanEntry::query()->live()
            ->where('loan_contract_id', $loan->id)
            ->where('principal_minor', '!=', 0)
            ->whereDate('entry_date', '<=', $to->toDateString())
            ->get(['entry_date', 'principal_minor']);

        $balance = (int) $entries->filter(fn (LoanEntry $e) => $e->entry_date->lessThan($from))->sum('principal_minor');
        $changes = $entries->filter(fn (LoanEntry $e) => ! $e->entry_date->lessThan($from))
            ->groupBy(fn (LoanEntry $e) => $e->entry_date->toDateString())
            ->map(fn ($group) => (int) $group->sum('principal_minor'));

        $versions = self::versions($loan);

        /** @var array<string, int> "rate|basis" => paisa-days */
        $buckets = [];

        for ($day = $from->copy(); $day->lessThanOrEqualTo($to); $day->addDay()) {
            $balance += $changes->get($day->toDateString(), 0);

            if ($balance <= 0) {
                continue;
            }

            $terms = self::termsOn($versions, $day) ?? LoanTerms::fromContract($loan);

            if ($terms->rateE4 === 0) {
                continue;
            }

            $key = $terms->rateE4.'|'.$terms->basis();
            $buckets[$key] = ($buckets[$key] ?? 0) + $balance;
        }

        $interest = 0;

        foreach ($buckets as $key => $paisaDays) {
            [$rate, $basis] = array_map('intval', explode('|', $key));
            $interest += LoanMath::interest($paisaDays, $rate, $basis);
        }

        return $interest;
    }

    /**
     * @return list<array{from: Carbon, terms: LoanTerms}> oldest first
     */
    public static function versions(LoanContract $loan): array
    {
        return LoanTermsVersion::query()
            ->where('loan_contract_id', $loan->id)
            ->orderBy('effective_from')->orderBy('version')
            ->get()
            ->map(fn (LoanTermsVersion $v) => ['from' => $v->effective_from->copy()->startOfDay(), 'terms' => LoanTerms::fromArray($v->terms)])
            ->all();
    }

    /**
     * @param  list<array{from: Carbon, terms: LoanTerms}>  $versions
     */
    public static function termsOn(array $versions, Carbon $day): ?LoanTerms
    {
        $found = null;

        foreach ($versions as $version) {
            if ($version['from']->lessThanOrEqualTo($day)) {
                $found = $version['terms'];
            }
        }

        // Before the first version took effect, the first version's terms.
        return $found ?? ($versions[0]['terms'] ?? null);
    }
}
