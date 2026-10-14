<?php

namespace App\Modules\Accounting\Support\Loans;

use App\Modules\Accounting\Enums\LoanDirection;
use App\Modules\Accounting\Models\LoanContract;
use App\Modules\Accounting\Models\LoanTdsRule;
use Illuminate\Support\Carbon;

/**
 * Whether tax is withheld from interest the business PAYS on a loan, and at
 * what rate (ChatGPT confirmation, point 6).
 *
 *  - Only a loan TAKEN, only while an ENABLED rule is in force on the payment
 *    date, and only when the lender's kind is one the rule names (a bank or
 *    NBFI is not, by default).
 *  - A lender's NBR certificate for a reduced or nil rate, recorded on its own
 *    loan, replaces the rule's rate.
 *  - TDS is on the GROSS interest: Cr 2180 TDS Payable, and the lender is paid
 *    gross interest less the TDS plus the principal.
 *
 * ⚠ The seeded rule is OFF until the accountant confirms the business is a
 * "specified person" (ITA 2023 s.104).
 */
final class TdsPolicy
{
    public static function ruleFor(LoanContract $loan, Carbon $on): ?LoanTdsRule
    {
        if ($loan->direction !== LoanDirection::Taken) {
            return null;
        }

        $kind = $loan->counterparty?->kind?->value;

        return LoanTdsRule::query()
            ->where('applies_to', LoanTdsRule::INTEREST_PAID_ON_LOANS_TAKEN)
            ->inForceOn($on->toDateString())
            ->orderByDesc('effective_from')
            ->get()
            ->first(fn (LoanTdsRule $rule) => in_array($kind, $rule->payee_kinds ?? [], true));
    }

    /**
     * @return array{rate_e4: int, rule: ?LoanTdsRule, certificate: ?string}
     */
    public static function rateFor(LoanContract $loan, Carbon $on): array
    {
        $rule = self::ruleFor($loan, $on);

        if ($rule === null) {
            return ['rate_e4' => 0, 'rule' => null, 'certificate' => null];
        }

        if ($loan->tds_certificate_rate !== null) {
            return ['rate_e4' => LoanMath::rateE4((string) $loan->tds_certificate_rate), 'rule' => $rule, 'certificate' => $loan->tds_certificate_ref];
        }

        return ['rate_e4' => LoanMath::rateE4((string) $rule->rate), 'rule' => $rule, 'certificate' => null];
    }

    public static function tdsOn(int $grossInterestMinor, int $rateE4): int
    {
        return $grossInterestMinor <= 0 || $rateE4 <= 0 ? 0 : LoanMath::percentOf($grossInterestMinor, $rateE4);
    }
}
