<?php

namespace App\Support\Attention\Sources;

use App\Models\User;
use App\Modules\Accounting\Enums\LoanDirection;
use App\Modules\Accounting\Enums\LoanStatus;
use App\Modules\Accounting\Models\LoanContract;
use App\Modules\Accounting\Support\Loans\LoanPosition;
use App\Support\Attention\AttentionItem;
use Illuminate\Support\Carbon;

/**
 * The Loan Register's dates (loans plan v2, phase 2): installments past due,
 * installments falling due in the next DUE_DAYS days, and loans maturing in
 * the next MATURITY_DAYS days. Computed from each loan's live schedule, so a
 * repayment recorded a minute ago takes its line off.
 */
class LoanDuesSource extends Source
{
    public const DUE_DAYS = 7;

    public const MATURITY_DAYS = 30;

    public function key(): string
    {
        return 'loans_due';
    }

    public function area(): string
    {
        return 'money';
    }

    protected function permissions(): array
    {
        return ['accounting.loans.view'];
    }

    public function items(User $user, int $businessId): array
    {
        $today = Carbon::today();
        $soon = $today->copy()->addDays(self::DUE_DAYS);
        $maturing = $today->copy()->addDays(self::MATURITY_DAYS);

        $overdue = ['count' => 0, 'minor' => 0, 'since' => null, 'loans' => []];
        $due = ['count' => 0, 'minor' => 0, 'first' => null];
        $maturity = ['count' => 0, 'first' => null];

        $loans = LoanContract::query()->with('counterparty')
            ->where('business_id', $businessId)
            ->where('status', LoanStatus::Active->value)
            ->get();

        foreach ($loans as $loan) {
            foreach (LoanPosition::of($loan)->currentRows() as $row) {
                $unpaid = $row['principal_unpaid'] + $row['interest_unpaid'];

                if ($unpaid <= 0) {
                    continue;
                }

                if ($row['due_date']->lessThan($today)) {
                    $overdue['count']++;
                    $overdue['minor'] += $unpaid;
                    $overdue['loans'][$loan->id] = $loan->loan_number;
                    $overdue['since'] = $overdue['since'] === null || $row['due_date']->lessThan($overdue['since']) ? $row['due_date'] : $overdue['since'];
                } elseif ($row['due_date']->lessThanOrEqualTo($soon)) {
                    $due['count']++;
                    $due['minor'] += $unpaid;
                    $due['first'] = $due['first'] === null || $row['due_date']->lessThan($due['first']) ? $row['due_date'] : $due['first'];
                }
            }

            if ($loan->maturity_date->betweenIncluded($today, $maturing)) {
                $maturity['count']++;
                $maturity['first'] = $maturity['first'] === null || $loan->maturity_date->lessThan($maturity['first']) ? $loan->maturity_date : $maturity['first'];
            }
        }

        $items = [];

        if ($overdue['count'] > 0) {
            $items[] = $this->item(
                'overdue',
                AttentionItem::HIGH,
                'loan_installment_overdue',
                '{count} loan installments are past due (Tk {amount}) on {loans}.',
                ['count' => $overdue['count'], 'amount' => self::money($overdue['minor'] / 100), 'loans' => implode(', ', array_values($overdue['loans']))],
                '/accounting/loans/reports?report=overdue',
                ['since' => $overdue['since'], 'ownerRole' => 'Finance', 'count' => $overdue['count']],
            );
        }

        if ($due['count'] > 0) {
            $items[] = $this->item(
                'due_soon',
                $due['first']->lessThanOrEqualTo($today->copy()->addDay()) ? AttentionItem::HIGH : AttentionItem::NORMAL,
                'loan_installment_due',
                '{count} loan installments (Tk {amount}) fall due in the next {days} days, the first on {date}.',
                ['count' => $due['count'], 'amount' => self::money($due['minor'] / 100), 'days' => self::DUE_DAYS, 'date' => $due['first']->format('j M Y')],
                '/accounting/loans',
                ['ownerRole' => 'Finance', 'count' => $due['count']],
            );
        }

        if ($maturity['count'] > 0) {
            $items[] = $this->item(
                'maturing',
                AttentionItem::NORMAL,
                'loan_maturity_soon',
                '{count} loans mature in the next {days} days, the first on {date}.',
                ['count' => $maturity['count'], 'days' => self::MATURITY_DAYS, 'date' => $maturity['first']->format('j M Y')],
                '/accounting/loans/reports?report=maturity',
                ['ownerRole' => 'Finance', 'count' => $maturity['count']],
            );
        }

        return $items;
    }

    /** "Money in" on a loan given, "money out" on one taken: what the calendar says. */
    public static function directionLabel(LoanDirection $direction): string
    {
        return $direction === LoanDirection::Given ? 'Loan money in' : 'Loan money out';
    }
}
