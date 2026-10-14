<?php

namespace App\Modules\Accounting\Support\Loans;

use App\Modules\Accounting\Enums\CounterpartyKind;
use App\Modules\Accounting\Enums\LoanDirection;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Counterparty;
use Illuminate\Support\Carbon;

/**
 * Which ledger heads a loan's lines sit on (loans plan v2, phase 2), chosen by
 * direction, term and who the counterparty is. Found by SLUG, never by code.
 *
 *   Given, due within 12 months   11751 Loans to Other Parties, or
 *                                 11752 Loans to Related Parties when related
 *   Given, longer                 1270 Long-term Loans Given (current part 11754)
 *   Taken, due within 12 months   2126 Short-term Loans Payable
 *   Taken, longer, bank or NBFI   2210 Bank Loans (current part 2128)
 *   Taken, longer, anyone else    2230 Loans from Other Parties (current part 2128),
 *                                 or 2220 Loans from Owners for a documented owner
 *                                 loan (a warning, not a block: money the owner puts
 *                                 in is normally Owner's Capital 3100)
 *
 *   Interest accrued              11753 Interest Receivable / 2127 Interest Payable
 *   Interest in profit and loss   4920 Interest Income / 6478 Interest Expense
 */
final class LoanHeads
{
    public const OWNER_LOANS = 'owner_loans';

    public const OWNER_WARNING = "Loans from Owners is only for a documented owner loan agreed with the accountant. Money the owner puts into the business is Owner's Capital (3100), not a loan.";

    /** principal slug => the slug that holds the part due within 12 months */
    private const CURRENT_PORTION = [
        'long_term_loans_given' => 'current_portion_of_loans_given',
        'bank_loans' => 'current_portion_of_long_term_loans',
        'owner_loans' => 'current_portion_of_long_term_loans',
        'loans_from_other_parties' => 'current_portion_of_long_term_loans',
    ];

    /** A loan running past 12 months from its start is long-term. */
    public static function isLongTerm(Carbon $start, Carbon $maturity): bool
    {
        return $maturity->greaterThan($start->copy()->addMonthsNoOverflow(12));
    }

    /**
     * The principal heads this loan may use, the proposed one first.
     *
     * @return list<string>
     */
    public static function allowedPrincipalSlugs(LoanDirection $direction, Counterparty $counterparty, Carbon $start, Carbon $maturity): array
    {
        $long = self::isLongTerm($start, $maturity);

        if ($direction === LoanDirection::Given) {
            if ($long) {
                return ['long_term_loans_given'];
            }

            return $counterparty->related_party ? ['loans_to_related_parties'] : ['loans_to_other_parties'];
        }

        if (! $long) {
            return ['short_term_loans_payable'];
        }

        return in_array($counterparty->kind, [CounterpartyKind::Bank, CounterpartyKind::Nbfi], true)
            ? ['bank_loans']
            : ['loans_from_other_parties', self::OWNER_LOANS];
    }

    /** Why this head cannot hold this loan, or null when it can. */
    public static function refusal(LoanDirection $direction, Counterparty $counterparty, string $slug, Carbon $start, Carbon $maturity): ?string
    {
        $allowed = self::allowedPrincipalSlugs($direction, $counterparty, $start, $maturity);

        if (in_array($slug, $allowed, true)) {
            return null;
        }

        $names = implode(' or ', array_map(fn (string $s) => Account::bySlug($s)?->label() ?? $s, $allowed));
        $term = self::isLongTerm($start, $maturity) ? 'runs longer than 12 months' : 'is due within 12 months';

        return "This loan {$term}, so its principal goes on {$names}.";
    }

    public static function currentPortionSlug(string $principalSlug): ?string
    {
        return self::CURRENT_PORTION[$principalSlug] ?? null;
    }

    public static function accrualSlug(LoanDirection $direction): string
    {
        return $direction === LoanDirection::Given ? 'interest_receivable' : 'interest_payable';
    }

    public static function profitAndLossSlug(LoanDirection $direction): string
    {
        return $direction === LoanDirection::Given ? 'interest_income' : 'interest_expense';
    }

    /**
     * Every head a loan's own lines may sit on, by slug: principal (all
     * candidates), current portions and accrued interest.
     *
     * @return list<string>
     */
    public static function allLoanSlugs(): array
    {
        return [
            'loans_to_other_parties', 'loans_to_related_parties', 'long_term_loans_given', 'current_portion_of_loans_given',
            'interest_receivable', 'short_term_loans_payable', 'bank_loans', 'owner_loans', 'loans_from_other_parties',
            'current_portion_of_long_term_loans', 'interest_payable',
        ];
    }

    /** @return array<int, string> account id => slug for every loan head the chart has */
    public static function idsBySlug(): array
    {
        return Account::query()->whereIn('system_slug', self::allLoanSlugs())->pluck('system_slug', 'id')->all();
    }
}
