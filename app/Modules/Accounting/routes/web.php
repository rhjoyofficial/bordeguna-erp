<?php

use App\Modules\Accounting\Http\Controllers\AccountController;
use App\Modules\Accounting\Http\Controllers\JournalController;
use App\Modules\Accounting\Http\Controllers\MoneyAccountController;
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

        // ⚠ Reading the ledger is `accounting.view`; WRITING to it is
        // `accounting.journal.post` below. Musa can read a journal and cannot
        // post one, which is the same separation that already keeps him from
        // approving his own payroll run.
        Route::get('journals', [JournalController::class, 'index'])->name('journals.index');
        Route::get('journals/{journal}', [JournalController::class, 'show'])->name('journals.show');
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
    });
});
