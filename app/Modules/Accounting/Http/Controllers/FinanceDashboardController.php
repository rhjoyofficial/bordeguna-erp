<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Enums\AgeingBucket;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\Ageing;
use App\Modules\Accounting\Support\IncomeStatement;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\MoneyAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon as LaravelCarbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The finance dashboard: money in, money out, what is owed, and where the cash
 * actually is.
 *
 * ⚠⚠ IT COMPUTES NOTHING OF ITS OWN. Every figure comes from the report class
 * that already produces it for the statement of the same name: IncomeStatement,
 * Ageing, and the money accounts. A dashboard with its own arithmetic is a
 * dashboard that eventually disagrees with the statements, and then nobody knows
 * which to believe. This is presentation over numbers that already exist.
 *
 * ⚠⚠ EVERYTHING HERE IS ZERO ON PRODUCTION TODAY, and that is correct rather
 * than broken: there are no journals and no invoices yet. Every panel renders an
 * honest empty state instead of an axis with nothing on it, because the first
 * impression of this screen is going to be an empty business.
 *
 * ⚠ Gated on `accounting.view`, the same permission as the statements it
 * summarises. A dashboard is not a softer thing than the report behind it.
 */
class FinanceDashboardController extends Controller
{
    /** How many months of history the trend panels show. */
    private const MONTHS = 6;

    public function index(Request $request): Response
    {
        $this->authorize('accounting.view');

        $today = CarbonImmutable::today();

        $months = [];

        for ($i = self::MONTHS - 1; $i >= 0; $i--) {
            $start = $today->subMonths($i)->startOfMonth();
            $end = $start->endOfMonth();

            // ⚠ The SAME class the income statement screen uses. If the two ever
            // disagree it will be because the statement changed, and this will
            // change with it.
            // ⚠ `toMutable()` returns Carbon\Carbon, and these signatures want
            // Illuminate\Support\Carbon. They are not the same class and PHP
            // will not coerce one to the other.
            $statement = IncomeStatement::between(LaravelCarbon::instance($start), LaravelCarbon::instance($end));

            $months[] = [
                'label' => $start->format('M'),
                'a' => $this->taka($statement->totalIncome()),
                'b' => $this->taka($statement->totalCostOfSales()->plus($statement->totalOperatingExpenses())),
                'profit' => $this->taka($statement->netProfit()),
            ];
        }

        $thisMonth = IncomeStatement::between(LaravelCarbon::instance($today->startOfMonth()), LaravelCarbon::instance($today->endOfMonth()));

        $receivables = Ageing::receivables(LaravelCarbon::instance($today));
        $payables = Ageing::payables(LaravelCarbon::instance($today));

        return Inertia::render('accounting/finance-dashboard', [
            'months' => $months,
            'profitTrend' => array_map(fn (array $m) => ['label' => $m['label'], 'value' => $m['profit']], $months),
            'thisMonth' => [
                'income' => $this->taka($thisMonth->totalIncome()),
                'costOfSales' => $this->taka($thisMonth->totalCostOfSales()),
                'grossProfit' => $this->taka($thisMonth->grossProfit()),
                'expenses' => $this->taka($thisMonth->totalOperatingExpenses()),
                'netProfit' => $this->taka($thisMonth->netProfit()),
                'grossMargin' => $thisMonth->grossMarginPercent(),
            ],
            // ⚠ Money accounts, not the ledger, because "where is the cash" is a
            // question about tills and wallets and this is the list somebody
            // recognises. The ledger view of the same money is the cash book.
            'moneyAccounts' => MoneyAccount::query()->where('is_active', true)->orderBy('name')
                ->get(['id', 'name', 'type'])
                ->map(fn (MoneyAccount $a) => ['name' => $a->name, 'type' => $a->type]),
            'receivables' => $this->buckets($receivables),
            'payables' => $this->buckets($payables),
            'owed' => [
                'to_us' => $this->taka($receivables->total()),
                'by_us' => $this->taka($payables->total()),
            ],
            // ⚠⚠ Said out loud on the screen. A dashboard of zeroes on a business
            // that has not started trading looks identical to a dashboard that is
            // broken, and somebody will report it as a bug.
            'hasPostings' => Journal::query()->exists(),
        ]);
    }

    /**
     * @return list<array{label:string, value:float}>
     */
    private function buckets(Ageing $ageing): array
    {
        return array_map(
            fn (AgeingBucket $bucket) => [
                'label' => $bucket->label(),
                'value' => $this->taka($ageing->bucket($bucket)),
            ],
            AgeingBucket::cases(),
        );
    }

    /**
     * ⚠⚠ Money is whole paisa in a signed integer (`amount_minor`); it is NEVER a
     * float in the ledger. This converts at the very edge, for a chart, and the
     * conversion lives in one place so no screen invents its own division.
     */
    private function taka(Money $money): float
    {
        return round($money->minor / 100, 2);
    }
}
