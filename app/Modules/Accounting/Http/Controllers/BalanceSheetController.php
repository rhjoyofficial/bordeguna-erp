<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\BalanceSheet;
use App\Modules\Accounting\Support\FiscalYear;
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
 * The statement of financial position.
 *
 * ⚠ One date, not a range (C-FS-4). A balance sheet is a photograph, and
 * offering a period would invite the reader to believe it was a film.
 */
class BalanceSheetController extends Controller
{
    public function index(Request $request): Response
    {
        $business = Business::withoutGlobalScopes()->find(app(BusinessContext::class)->id());

        $asAt = ($request->filled('as_at')
            ? Carbon::parse($request->string('as_at')->toString())
            : Carbon::today())->endOfDay();

        // Without a business there is no fiscal year to split the result on, and
        // outside a tenant the ledger returns nothing anyway.
        if ($business === null) {
            return Inertia::render('accounting/balance-sheet/index', [
                'as_at' => $asAt->toDateString(),
                'fiscal_year' => null,
                'assets' => [], 'liabilities' => [], 'equity' => [],
                'totals' => null,
                'empty' => true,
                'ledger_empty' => true,
            ]);
        }

        $sheet = BalanceSheet::asAt($asAt, $business);

        return Inertia::render('accounting/balance-sheet/index', [
            'as_at' => $asAt->toDateString(),
            'fiscal_year' => FiscalYear::containing($asAt, $business)->label(),
            'fiscal_year_start' => $sheet->fiscalYearStart->toDateString(),
            'assets' => self::sections($sheet->assets),
            'liabilities' => self::sections($sheet->liabilities),
            'equity' => self::sections($sheet->equity),
            'result' => [
                'brought_forward' => $sheet->presentedResultBroughtForward()->format(),
                'brought_forward_is_loss' => $sheet->presentedResultBroughtForward()->isCredit(),
                'for_the_year' => $sheet->presentedResultForTheYear()->format(),
                'for_the_year_is_loss' => $sheet->presentedResultForTheYear()->isCredit(),
                'total' => $sheet->unclosedResult()->format(),
            ],
            'totals' => [
                'assets' => $sheet->totalAssets()->format(),
                'liabilities' => $sheet->totalLiabilities()->format(),
                'equity_accounts' => $sheet->totalEquityAccounts()->format(),
                'equity' => $sheet->totalEquity()->format(),
                'liabilities_and_equity' => $sheet->totalLiabilitiesAndEquity()->format(),
                // ⚠⚠ Shown, never hidden. A balance sheet that silently does not
                // balance is worse than no balance sheet at all (C-FS-6).
                'difference' => $sheet->difference()->absolute()->format(),
                'balances' => $sheet->balances(),
            ],
            'empty' => $sheet->isEmpty(),
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
            'total' => $section->presentedTotal()->format(),
            'lines' => $section->lines->map(fn (StatementLine $line) => [
                'code' => $line->account->code,
                'name' => $line->account->name,
                'amount' => $line->presentedAmount()->format(),
                // Accumulated depreciation reads as a deduction, and saying so
                // costs one flag and saves a reader the arithmetic (C-FS-7).
                'is_contra' => $line->isContra(),
            ])->values()->all(),
        ])->values()->all();
    }
}
