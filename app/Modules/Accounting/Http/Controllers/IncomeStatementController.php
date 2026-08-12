<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Accounting\Support\IncomeStatement;
use App\Modules\Accounting\Support\StatementLine;
use App\Modules\Accounting\Support\StatementSection;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The profit and loss.
 *
 * ⚠ Defaults to the fiscal year to date, the same window the trial balance
 * defaults to, so the two reports opened side by side describe the same period.
 * A month default would answer a different question from the one next to it.
 */
class IncomeStatementController extends Controller
{
    public function index(Request $request): Response
    {
        $business = Business::withoutGlobalScopes()->find(app(BusinessContext::class)->id());

        [$from, $to] = $this->window($request, $business);

        $statement = IncomeStatement::between($from, $to);

        return Inertia::render('accounting/income-statement/index', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'fiscal_year' => $business === null ? null : FiscalYear::containing($to, $business)->label(),
            'income' => self::sections($statement->income),
            'cost_of_sales' => self::sections($statement->costOfSales),
            'operating_expenses' => self::sections($statement->operatingExpenses),
            'totals' => [
                'income' => $statement->totalIncome()->format(),
                'cost_of_sales' => $statement->totalCostOfSales()->format(),
                'gross_profit' => $statement->grossProfit()->format(),
                'operating_expenses' => $statement->totalOperatingExpenses()->format(),
                'net_profit' => $statement->netProfit()->format(),
                // ⚠ A loss is not a negative profit with a minus glued on; the
                // screen says "Net loss" and shows the figure positive, so the
                // reader is never asked to interpret a sign.
                'is_loss' => $statement->netProfit()->isCredit(),
                'net_profit_absolute' => $statement->netProfit()->absolute()->format(),
                'gross_margin_percent' => $statement->grossMarginPercent(),
            ],
            'empty' => $statement->isEmpty(),
            'ledger_empty' => Journal::count() === 0,
        ]);
    }

    /**
     * @param  Collection<int, StatementSection>  $sections
     * @return array<int, array<string, mixed>>
     */
    private static function sections(Collection $sections): array
    {
        return $sections->map(fn (StatementSection $section) => [
            'code' => $section->code,
            'name' => $section->name,
            // ⚠ Every figure formatted explicitly. `19200.00000000` has reached
            // a real user on this project twice.
            'total' => $section->presentedTotal()->format(),
            'lines' => $section->lines->map(fn (StatementLine $line) => [
                'code' => $line->account->code,
                'name' => $line->account->name,
                'amount' => $line->presentedAmount()->format(),
                'is_contra' => $line->isContra(),
            ])->values()->all(),
        ])->values()->all();
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(Request $request, ?Business $business): array
    {
        $today = Carbon::today();

        $yearStart = $business === null
            ? $today->copy()->startOfYear()
            : FiscalYear::containing($today, $business)->startsOn();

        $from = $request->filled('from') ? Carbon::parse($request->string('from')->toString()) : $yearStart;
        $to = $request->filled('to') ? Carbon::parse($request->string('to')->toString()) : $today;

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return [$from->startOfDay(), $to->endOfDay()];
    }
}
