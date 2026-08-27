<?php

use App\Modules\Accounting\Http\Controllers\AccountController;
use App\Modules\Accounting\Http\Controllers\AgeingController;
use App\Modules\Accounting\Http\Controllers\BalanceSheetController;
use App\Modules\Accounting\Http\Controllers\CashAndBankBookController;
use App\Modules\Accounting\Http\Controllers\CashFlowController;
use App\Modules\Accounting\Http\Controllers\CostOfGoodsSoldController;
use App\Modules\Accounting\Http\Controllers\CustomerStatementController;
use App\Modules\Accounting\Http\Controllers\GeneralLedgerController;
use App\Modules\Accounting\Http\Controllers\IncomeStatementController;
use App\Modules\Accounting\Http\Controllers\InventoryValuationController;
use App\Modules\Accounting\Http\Controllers\JournalController;
use App\Modules\Accounting\Http\Controllers\MoneyAccountController;
use App\Modules\Accounting\Http\Controllers\MoneyTransferController;
use App\Modules\Accounting\Http\Controllers\ReconciliationController;
use App\Modules\Accounting\Http\Controllers\TrialBalanceController;
use Illuminate\Support\Facades\Route;

/**
 * ⚠ The `business` middleware still applies even though the chart itself is not
 * business-scoped. A user with no business has no role, and therefore no
 * permission - and the ledger screens that arrive in later slices are all
 * business-scoped, so the accounting section must resolve a tenant the same way
 * every other section does.
 */
Route::middleware(['web', 'auth', 'business'])->prefix('accounting')->name('accounting.')->group(function () {
    Route::middleware('can:accounting.view')->group(function () {
        Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
        Route::get('money-accounts', [MoneyAccountController::class, 'index'])->name('money-accounts.index');
        // ⚠ The third primary statement. A RANGE, not a date - unlike the
        // balance sheet this is a film, not a photograph.
        Route::get('cash-flow', [CashFlowController::class, 'index'])->name('cash-flow');

        // ⚠ Reading the ledger is `accounting.view`; WRITING to it is
        // `accounting.journal.post` below. Musa can read a journal and cannot
        // post one, which is the same separation that already keeps him from
        // approving his own payroll run.
        Route::get('journals', [JournalController::class, 'index'])->name('journals.index');
        Route::get('journals/{journal}', [JournalController::class, 'show'])->name('journals.show');

        Route::get('trial-balance', [TrialBalanceController::class, 'index'])->name('trial-balance');

        // The drill-down the trial balance and both statements point at: one
        // account's postings, in order, with a running balance.
        Route::get('general-ledger', [GeneralLedgerController::class, 'index'])->name('general-ledger');

        // ⚠ Same permission as the ledger they summarise, which is what keeps
        // this slice free of any seeder change. C-GLA-A asks whether sales and
        // collections staff should reach the receivables ageing without seeing
        // the whole ledger; the answer costs a permission and a role
        // assignment, and nothing in the code depends on it.
        Route::get('receivables-ageing', [AgeingController::class, 'receivables'])->name('receivables-ageing');
        Route::get('payables-ageing', [AgeingController::class, 'payables'])->name('payables-ageing');

        // Where the money is and how it moved. Same `accounting.view` as the
        // ledger its figures come from (C-CBB-2), so no seeder change.
        Route::get('cash-and-bank', [CashAndBankBookController::class, 'index'])->name('cash-and-bank');

        // One customer's account, told in documents and checked by the ledger
        // (C-CST-1). C-GLA-A now covers this screen too.
        Route::get('customer-statement', [CustomerStatementController::class, 'index'])->name('customer-statement');

        // accounting:reconcile as a screen: the same engine, the same strings
        // (C-RSC-1), for the business the user is standing in.
        Route::get('reconciliation', [ReconciliationController::class, 'index'])->name('reconciliation');

        // ⚠ Same `accounting.view` permission as every other report here, which
        // is what keeps this slice free of any seeder change. Both tie out to a
        // control account on screen, so whoever may read the ledger may read
        // these - and nobody else can see stock value or margin.
        Route::get('inventory-valuation', [InventoryValuationController::class, 'index'])->name('inventory-valuation');
        Route::get('cost-of-goods-sold', [CostOfGoodsSoldController::class, 'index'])->name('cost-of-goods-sold');

        // The two statements a business actually runs on. Reading them is the
        // same permission as reading the ledger they summarise: a figure is no
        // more sensitive for being totalled.
        Route::get('income-statement', [IncomeStatementController::class, 'index'])->name('income-statement');
        Route::get('balance-sheet', [BalanceSheetController::class, 'index'])->name('balance-sheet');
    });

    Route::middleware('can:accounting.chart.manage')->group(function () {
        Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store');
        Route::put('accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
        Route::delete('accounts/{account}', [AccountController::class, 'destroy'])->name('accounts.destroy');

        // ⚠ No delete route. A money account is deactivated, never removed:
        // a payment made in August must still be nameable in December.
        Route::post('money-accounts', [MoneyAccountController::class, 'store'])->name('money-accounts.store');
        Route::put('money-accounts/{moneyAccount}', [MoneyAccountController::class, 'update'])->name('money-accounts.update');
    });

    // ⚠⚠ The highest-risk permission in the system (C-GL-6): the one action that
    // can move money between any two accounts with no document behind it.
    // Owner only, and being Owner-only IS the control in this phase.
    Route::middleware('can:accounting.journal.post')->group(function () {
        Route::get('journals/new/manual', [JournalController::class, 'create'])->name('journals.create');
        Route::post('journals', [JournalController::class, 'store'])->name('journals.store');
        Route::post('journals/{journal}/reverse', [JournalController::class, 'reverse'])->name('journals.reverse');

        // ⚠ A transfer posts a journal that moves money, so it belongs to the
        // same permission rather than to a new one. The document exists so the
        // move is NOT a bare journal: it names both accounts, carries the fee,
        // and is numbered - which a hand-written journal never is.
        Route::get('money-transfers', [MoneyTransferController::class, 'index'])->name('money-transfers.index');
        Route::post('money-transfers', [MoneyTransferController::class, 'store'])->name('money-transfers.store');
    });
});
