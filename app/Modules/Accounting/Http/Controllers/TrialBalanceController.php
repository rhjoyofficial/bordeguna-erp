<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Accounting\Support\TrialBalance;
use App\Modules\Accounting\Support\TrialBalanceRow;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The report that says whether the books add up.
 *
 * ⚠ It runs the same `TrialBalance` the integrity command checks, so level 4 of
 * `accounting:verify-ledger` is genuinely checking what a user is SHOWN rather
 * than a second sum that happens to agree.
 */
class TrialBalanceController extends Controller
{
    public function index(Request $request): Response
    {
        $business = Business::withoutGlobalScopes()->find(app(BusinessContext::class)->id());

        [$from, $to] = $this->window($request, $business);

        $trialBalance = TrialBalance::between($from, $to);

        return Inertia::render('accounting/trial-balance/index', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'rows' => $trialBalance->rows
                ->filter(fn (TrialBalanceRow $row) => $row->isWorthShowing())
                ->values()
                ->map(fn (TrialBalanceRow $row) => [
                    'code' => $row->account->code,
                    'name' => $row->account->name,
                    'type_label' => $row->account->type->label(),
                    // ⚠ Every figure formatted explicitly. `19200.00000000` has
                    // reached a real user on this project twice, in two
                    // different modules, the second time because fixing an
                    // instance was mistaken for fixing a pattern.
                    'opening' => $row->opening->isZero() ? null : $row->opening->absolute()->format(),
                    'opening_side' => $row->opening->isCredit() ? 'credit' : 'debit',
                    'debit' => $row->debit->isZero() ? null : $row->debit->format(),
                    'credit' => $row->credit->isZero() ? null : $row->credit->absolute()->format(),
                    'closing' => $row->closing()->absolute()->format(),
                    'closing_side' => $row->closing()->isCredit() ? 'credit' : 'debit',
                ]),
            'totals' => [
                'debit' => $trialBalance->totalDebits()->format(),
                'credit' => $trialBalance->totalCredits()->format(),
                'difference' => $trialBalance->difference()->absolute()->format(),
                'balances' => $trialBalance->balances(),
            ],
            'empty' => Journal::count() === 0,
        ]);
    }

    /**
     * Defaults to the current fiscal year to date.
     *
     * ⚠ Not "this month" and not "everything". A trial balance is read against
     * the year it belongs to, and Bor De Guna's year runs 1 July to 30 June
     * (C-GL-4), so a July default would show four weeks of a twelve-month story.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(Request $request, ?Business $business): array
    {
        $today = Carbon::today();

        // ⚠ C-FS-8: the fiscal year rule now has one home. This method used to
        // carry its own copy, and the two financial statements needed the same
        // date.
        $yearStart = $business === null
            ? $today->copy()->startOfYear()
            : FiscalYear::containing($today, $business)->startsOn();

        $from = $request->filled('from') ? Carbon::parse($request->string('from')->toString()) : $yearStart;
        $to = $request->filled('to') ? Carbon::parse($request->string('to')->toString()) : $today;

        // A window that runs backwards returns nothing and looks like a bug in
        // the books rather than a typo in a date field.
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return [$from->startOfDay(), $to->endOfDay()];
    }
}
