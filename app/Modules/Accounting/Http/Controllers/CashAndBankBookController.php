<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\CashAndBankBook;
use App\Modules\Accounting\Support\CashAndBankRow;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * How much money the business has, where it sits, and how it moved.
 *
 * The balance sheet totals cash into one asset figure; the money-accounts
 * screen names the accounts but carries no balance on purpose. This is the
 * screen between them: one row per ledger account a money account reports
 * under, figures from the same `TrialBalance` the verifier checks (C-CBB-2),
 * drill-down into the existing General Ledger (C-CBB-5).
 */
class CashAndBankBookController extends Controller
{
    public function index(Request $request): Response
    {
        $business = Business::withoutGlobalScopes()->find(app(BusinessContext::class)->id());

        [$from, $to] = $this->window($request, $business);

        $book = CashAndBankBook::between($from, $to);

        return Inertia::render('accounting/cash-and-bank/index', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            // For the presets on the date bar; the server keeps deciding the default window.
            'fiscal_year_start_month' => $business?->fiscal_year_start_month,
            'rows' => $book->rows->map(fn (CashAndBankRow $row) => [
                'account_id' => $row->account->id,
                'code' => $row->account->code,
                'name' => $row->account->name,
                'money_accounts' => $row->moneyAccounts->map(fn (MoneyAccount $moneyAccount) => [
                    'label' => $moneyAccount->label(),
                    'type_label' => $moneyAccount->type->label(),
                    'is_active' => $moneyAccount->is_active,
                ])->values(),
                // ⚠ Every figure formatted explicitly. `19200.00000000` has
                // reached a real user on this project twice, in two different
                // modules.
                'opening' => $row->opening->isZero() ? null : $row->opening->absolute()->format(),
                'opening_side' => $row->opening->isCredit() ? 'credit' : 'debit',
                'in' => $row->debit->isZero() ? null : $row->debit->format(),
                'out' => $row->credit->isZero() ? null : $row->credit->absolute()->format(),
                'closing' => $row->closing()->absolute()->format(),
                'closing_side' => $row->closing()->isCredit() ? 'credit' : 'debit',
            ])->values(),
            'totals' => [
                'opening' => $book->opening()->absolute()->format(),
                'opening_side' => $book->opening()->isCredit() ? 'credit' : 'debit',
                'in' => $book->moneyIn()->format(),
                'out' => $book->moneyOut()->absolute()->format(),
                'closing' => $book->closing()->absolute()->format(),
                'closing_side' => $book->closing()->isCredit() ? 'credit' : 'debit',
            ],
            // ⚠ C-CBB-4: whose movements this report cannot see.
            'unmapped' => $book->unmapped->map(fn (MoneyAccount $moneyAccount) => $moneyAccount->label())->values(),
            // Two different kinds of empty, two different next steps: no money
            // accounts means set-up has not happened; an empty ledger means
            // nothing has been posted yet.
            'noMoneyAccounts' => MoneyAccount::count() === 0,
            'ledgerEmpty' => Journal::count() === 0,
        ]);
    }

    /**
     * Defaults to the fiscal year to date, the same window the trial balance
     * and the general ledger default to (C-CBB-7).
     *
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
