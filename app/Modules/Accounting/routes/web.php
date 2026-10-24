<?php

use App\Modules\Accounting\Http\Controllers\AccountController;
use App\Modules\Accounting\Http\Controllers\AccountingPeriodController;
use App\Modules\Accounting\Http\Controllers\AgeingController;
use App\Modules\Accounting\Http\Controllers\AmortizationController;
use App\Modules\Accounting\Http\Controllers\BalanceSheetController;
use App\Modules\Accounting\Http\Controllers\BankStatementController;
use App\Modules\Accounting\Http\Controllers\BudgetController;
use App\Modules\Accounting\Http\Controllers\CashAndBankBookController;
use App\Modules\Accounting\Http\Controllers\CashFlowController;
use App\Modules\Accounting\Http\Controllers\CashForecastController;
use App\Modules\Accounting\Http\Controllers\CashPositionController;
use App\Modules\Accounting\Http\Controllers\ChequeRegisterController;
use App\Modules\Accounting\Http\Controllers\CostOfGoodsSoldController;
use App\Modules\Accounting\Http\Controllers\CounterpartyController;
use App\Modules\Accounting\Http\Controllers\CustomerStatementController;
use App\Modules\Accounting\Http\Controllers\DayBookController;
use App\Modules\Accounting\Http\Controllers\DunningController;
use App\Modules\Accounting\Http\Controllers\EmployeeStatementController;
use App\Modules\Accounting\Http\Controllers\FinanceDashboardController;
use App\Modules\Accounting\Http\Controllers\FinancePolicyController;
use App\Modules\Accounting\Http\Controllers\GeneralLedgerController;
use App\Modules\Accounting\Http\Controllers\IncomeStatementController;
use App\Modules\Accounting\Http\Controllers\IncomeVoucherController;
use App\Modules\Accounting\Http\Controllers\InterBusinessTransferController;
use App\Modules\Accounting\Http\Controllers\InventoryValuationController;
use App\Modules\Accounting\Http\Controllers\JournalController;
use App\Modules\Accounting\Http\Controllers\LoanAccrualController;
use App\Modules\Accounting\Http\Controllers\LoanChangeController;
use App\Modules\Accounting\Http\Controllers\LoanController;
use App\Modules\Accounting\Http\Controllers\LoanMoneyController;
use App\Modules\Accounting\Http\Controllers\LoanReportController;
use App\Modules\Accounting\Http\Controllers\LoanTdsRuleController;
use App\Modules\Accounting\Http\Controllers\MergeController;
use App\Modules\Accounting\Http\Controllers\MoneyAccountController;
use App\Modules\Accounting\Http\Controllers\MoneyTransferController;
use App\Modules\Accounting\Http\Controllers\OpeningBalanceController;
use App\Modules\Accounting\Http\Controllers\OwnerWithdrawalController;
use App\Modules\Accounting\Http\Controllers\PartyAdjustmentController;
use App\Modules\Accounting\Http\Controllers\PartyLedgerController;
use App\Modules\Accounting\Http\Controllers\PartyLinkController;
use App\Modules\Accounting\Http\Controllers\PartyStatementController;
use App\Modules\Accounting\Http\Controllers\QuickAccountController;
use App\Modules\Accounting\Http\Controllers\ReconciliationController;
use App\Modules\Accounting\Http\Controllers\SetOffController;
use App\Modules\Accounting\Http\Controllers\SupplierLedgerController;
use App\Modules\Accounting\Http\Controllers\SupplierStatementController;
use App\Modules\Accounting\Http\Controllers\TrialBalanceController;
use App\Modules\Accounting\Http\Controllers\VatReturnController;
use App\Modules\Accounting\Http\Controllers\VatSettingsController;
use App\Modules\Accounting\Http\Controllers\VoucherController;
use App\Modules\Foundation\Http\Controllers\CorrectionsReportController;
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
        // ⚠ The overview, gated on the SAME permission as the statements it
        // summarises. A dashboard is not a softer thing than the report behind
        // it, and every figure on it comes from those report classes rather
        // than from arithmetic of its own.
        Route::get('overview', [FinanceDashboardController::class, 'index'])->name('overview');

        Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
        Route::get('money-accounts', [MoneyAccountController::class, 'index'])->name('money-accounts.index');
        // ⭐ B23 (25 Oct 2026; R77): one money account's lifetime in and out.
        Route::get('money-accounts/{moneyAccount}', [MoneyAccountController::class, 'show'])->whereNumber('moneyAccount')->name('money-accounts.show');
        Route::get('money-accounts/{moneyAccount}/lifetime-export', [MoneyAccountController::class, 'lifetimeExport'])->whereNumber('moneyAccount')->name('money-accounts.lifetime-export');
        // F34 (17 Oct 2026): Cash position at an Accounts address. The same
        // registry report as /hrm/reports/cash_position, which keeps working.
        Route::get('reports/cash-position', [CashPositionController::class, 'show'])->name('reports.cash-position');
        // ⚠ The third primary statement. A RANGE, not a date - unlike the
        // balance sheet this is a film, not a photograph.
        Route::get('cash-flow', [CashFlowController::class, 'index'])->name('cash-flow');

        // ⚠ Reading the ledger is `accounting.view`; WRITING to it is
        // `accounting.journal.post` below. Musa can read a journal and cannot
        // post one, which is the same separation that already keeps him from
        // approving his own payroll run.
        // Period close (Module 2, Slice 1) - the screen the lock engine
        // waited a month for. View for accounting eyes; acting re-checks
        // chart.manage in the controller.
        // Budgets (Module 2, Slice 2): the plan the actuals are judged against.
        // Bank statements (Module 2, Slice 3): the bank's truth beside the book.
        // Accruals & prepayments (Module 2, Slice 4).
        // Payment reminders (Module 2, Slice 5): a human's click, logged.
        // The cash forecast (Module 2, Slice 6): the weeks ahead, read-only.
        Route::get('cash-forecast', [CashForecastController::class, 'index'])->name('cash-forecast');
        Route::get('dunning', [DunningController::class, 'index'])->name('dunning');
        Route::post('dunning/{customer}/remind', [DunningController::class, 'remind'])->name('dunning.remind');
        Route::get('amortizations', [AmortizationController::class, 'index'])->name('amortizations');
        Route::post('amortizations', [AmortizationController::class, 'store'])->name('amortizations.store');
        Route::post('amortizations/release', [AmortizationController::class, 'release'])->name('amortizations.release');
        Route::post('amortizations/{schedule}/cancel', [AmortizationController::class, 'cancel'])->name('amortizations.cancel');
        Route::get('bank-statements', [BankStatementController::class, 'show'])->name('bank-statements');
        Route::post('bank-statements/import', [BankStatementController::class, 'import'])->name('bank-statements.import');
        Route::post('bank-statements/{line}/match', [BankStatementController::class, 'match'])->name('bank-statements.match');
        Route::post('bank-statements/{line}/unmatch', [BankStatementController::class, 'unmatch'])->name('bank-statements.unmatch');
        Route::post('bank-statements/{line}/ignore', [BankStatementController::class, 'ignore'])->name('bank-statements.ignore');
        // ⭐ Corrections wave 2 (24 Oct 2026): Remove an imported line or a whole
        // import, only while nothing in it is matched (Owner or accountant).
        Route::post('bank-statements/lines/{line}/remove', [BankStatementController::class, 'removeLine'])->whereNumber('line')->name('bank-statements.remove-line');
        Route::post('bank-statements/imports/{import}/remove', [BankStatementController::class, 'removeImport'])->whereNumber('import')->name('bank-statements.remove-import');
        Route::get('budgets', [BudgetController::class, 'show'])->name('budgets');
        Route::post('budgets', [BudgetController::class, 'save'])->name('budgets.save');
        Route::get('period-close', [AccountingPeriodController::class, 'show'])->name('period-close');
        Route::post('period-close', [AccountingPeriodController::class, 'close'])->name('period-close.close');
        Route::post('period-close/unlock', [AccountingPeriodController::class, 'unlock'])->name('period-close.unlock');
        // Every journal of every module, in posting order. Same reading
        // permission as the journals it lists.
        // ⭐ VAT return (VAT plan T5): the registers and the period's position.
        // Reading is for anyone who sees the books; filing is below, Owner only.
        Route::get('vat-return', [VatReturnController::class, 'index'])->name('vat-return.index');
        Route::get('vat-return/export', [VatReturnController::class, 'export'])->name('vat-return.export');
        Route::get('day-book', [DayBookController::class, 'index'])->name('day-book');
        Route::get('day-book/export', [DayBookController::class, 'export'])->name('day-book.export');
        Route::get('journals', [JournalController::class, 'index'])->name('journals.index');
        Route::get('journals/{journal}', [JournalController::class, 'show'])->name('journals.show');

        Route::get('trial-balance', [TrialBalanceController::class, 'index'])->name('trial-balance');

        // The drill-down the trial balance and both statements point at: one
        // account's postings, in order, with a running balance.
        Route::get('general-ledger', [GeneralLedgerController::class, 'index'])->name('general-ledger');

        // ⚠ Payables ageing stays with the ledger. The receivables ageing moved
        // to the operating-reports gate below on 26 Sep 2026 (C-GLA-A answered:
        // Joy, Q19).
        Route::get('payables-ageing', [AgeingController::class, 'payables'])->name('payables-ageing');
        // One supplier's account, told in documents and checked by the ledger.
        Route::get('supplier-statement', [SupplierStatementController::class, 'index'])->name('supplier-statement');
        // ⭐ 18 Oct 2026 (A1): one supplier across 2110, 2120 and 1160 (a view,
        // not an account), and the GRNI lines that name no supplier.
        Route::get('supplier-ledger', [SupplierLedgerController::class, 'index'])->name('supplier-ledger');
        Route::get('grni-untagged', [SupplierLedgerController::class, 'untaggedGrni'])->name('grni-untagged');
        // ⭐ 20 Oct 2026 (party ledgers): the customer's ledger view, and Tally's
        // Sundry Creditors / Debtors groups drilling to each party's ledger.
        Route::get('customer-ledger', [PartyLedgerController::class, 'customer'])->name('customer-ledger');
        Route::get('sundry-creditors', [PartyLedgerController::class, 'creditors'])->name('sundry-creditors');
        Route::get('sundry-debtors', [PartyLedgerController::class, 'debtors'])->name('sundry-debtors');

        // Where the money is and how it moved. Same `accounting.view` as the
        // ledger its figures come from (C-CBB-2), so no seeder change.
        Route::get('cash-and-bank', [CashAndBankBookController::class, 'index'])->name('cash-and-bank');

        // One employee's ledgers (26 Sep 2026): the accountant's "head per employee".
        Route::get('employee-statement', [EmployeeStatementController::class, 'index'])->name('employee-statement');

        // accounting:reconcile as a screen: the same engine, the same strings
        // (C-RSC-1), for the business the user is standing in.
        Route::get('reconciliation', [ReconciliationController::class, 'index'])->name('reconciliation');

        // ⚠ Cost of goods sold shows margin: it stays with the ledger. Stock
        // value moved to the operating-reports gate below (26 Sep 2026, Q19).
        Route::get('cost-of-goods-sold', [CostOfGoodsSoldController::class, 'index'])->name('cost-of-goods-sold');

        // The two statements a business actually runs on. Reading them is the
        // same permission as reading the ledger they summarise: a figure is no
        // more sensitive for being totalled.
        Route::get('income-statement', [IncomeStatementController::class, 'index'])->name('income-statement');
        Route::get('balance-sheet', [BalanceSheetController::class, 'index'])->name('balance-sheet');
    });

    // ⭐ 26 Sep 2026 (Joy, Q19): the three OPERATING reports. Who owes us and
    // for how long, what one customer's account says, and what the stock on
    // the shelf is worth. The Manager reads these and nothing else of the
    // ledger; everyone with `accounting.view` reads them too, because the
    // provider composes the gate. Payables ageing and cost of goods sold stay
    // above: the first is the accountant's, the second shows margin.
    Route::middleware('can:accounting.operations.view')->group(function () {
        Route::get('receivables-ageing', [AgeingController::class, 'receivables'])->name('receivables-ageing');
        // One customer's account, told in documents and checked by the ledger (C-CST-1).
        Route::get('customer-statement', [CustomerStatementController::class, 'index'])->name('customer-statement');
        // Ties out to the stock control account on screen.
        Route::get('inventory-valuation', [InventoryValuationController::class, 'index'])->name('inventory-valuation');
    });

    // ⭐ Counterparties (loans plan v2, 14 Oct 2026): the banks, lenders and
    // borrowers every loan line names. ROUTE, SCREEN, NAV LINK AND PERMISSION
    // in one change (the reachability rule). The Manager reads; Owner and
    // Finance keep the list, from this screen or from a voucher's picker.
    Route::middleware('can:accounting.counterparties.view')->group(function () {
        Route::get('counterparties', [CounterpartyController::class, 'index'])->name('counterparties.index');
        Route::get('counterparties/{counterparty}', [CounterpartyController::class, 'show'])->whereNumber('counterparty')->name('counterparties.show');
        Route::get('counterparties/{counterparty}/statement', [CounterpartyController::class, 'statement'])->whereNumber('counterparty')->name('counterparties.statement');
        Route::get('counterparties/{counterparty}/confirmation', [CounterpartyController::class, 'confirmation'])->whereNumber('counterparty')->name('counterparties.confirmation');
    });
    Route::middleware('can:accounting.counterparties.manage')->group(function () {
        Route::post('counterparties', [CounterpartyController::class, 'store'])->name('counterparties.store');
        Route::put('counterparties/{counterparty}', [CounterpartyController::class, 'update'])->whereNumber('counterparty')->name('counterparties.update');
        Route::post('counterparties/{counterparty}/deactivate', [CounterpartyController::class, 'deactivate'])->whereNumber('counterparty')->name('counterparties.deactivate');
        Route::post('counterparties/{counterparty}/activate', [CounterpartyController::class, 'activate'])->whereNumber('counterparty')->name('counterparties.activate');
    });

    // ⭐ The Loan Register (loans plan v2, phase 2, 14 Oct 2026). ROUTE,
    // SCREEN, NAV LINK, PERMISSION AND APPROVAL WORKFLOW ROW in one change.
    // Reading: Owner, Finance, Manager. Keeping it and moving its money: Owner
    // and Finance. Approving a loan, a restructure or a write-off: the Owner,
    // by the workflow stage (no permission gate on those routes).
    Route::middleware('can:accounting.loans.view')->group(function () {
        Route::get('loans', [LoanController::class, 'index'])->name('loans.index');
        Route::get('loans/reports', [LoanReportController::class, 'index'])->name('loans.reports');
        Route::get('loans/accruals', [LoanAccrualController::class, 'index'])->name('loans.accruals');
        Route::get('loans/tds-rules', [LoanTdsRuleController::class, 'index'])->name('loans.tds-rules');
        Route::get('loans/{loan}', [LoanController::class, 'show'])->whereNumber('loan')->name('loans.show');
        Route::get('loans/{loan}/statement', [LoanController::class, 'statement'])->whereNumber('loan')->name('loans.statement');
    });
    Route::middleware('can:accounting.loans.manage')->group(function () {
        Route::get('loans/new', [LoanController::class, 'create'])->name('loans.create');
        Route::post('loans', [LoanController::class, 'store'])->name('loans.store');
        Route::get('loans/{loan}/edit', [LoanController::class, 'edit'])->whereNumber('loan')->name('loans.edit');
        Route::put('loans/{loan}', [LoanController::class, 'update'])->whereNumber('loan')->name('loans.update');
        Route::post('loans/{loan}/submit', [LoanController::class, 'submit'])->whereNumber('loan')->name('loans.submit');
        Route::post('loans/{loan}/cancel', [LoanController::class, 'cancel'])->whereNumber('loan')->name('loans.cancel');
        Route::post('loans/{loan}/agreement', [LoanController::class, 'uploadAgreement'])->whereNumber('loan')->name('loans.agreement.store');
        Route::delete('loans/{loan}/agreement/{attachment}', [LoanController::class, 'deleteAgreement'])->whereNumber('loan')->name('loans.agreement.destroy');
        Route::post('loans/{loan}/advance', [LoanMoneyController::class, 'advance'])->whereNumber('loan')->name('loans.advance');
        Route::post('loans/{loan}/repay', [LoanMoneyController::class, 'repay'])->whereNumber('loan')->name('loans.repay');
        Route::post('loans/{loan}/accrue', [LoanMoneyController::class, 'accrue'])->whereNumber('loan')->name('loans.accrue');
        Route::post('loans/entries/{entry}/reverse', [LoanMoneyController::class, 'reverse'])->whereNumber('entry')->name('loans.entries.reverse');
        Route::post('loans/accruals', [LoanAccrualController::class, 'store'])->name('loans.accruals.store');
        Route::post('loans/{loan}/amendments', [LoanChangeController::class, 'requestAmendment'])->whereNumber('loan')->name('loans.amendments.store');
        Route::post('loans/{loan}/write-offs', [LoanChangeController::class, 'requestWriteOff'])->whereNumber('loan')->name('loans.write-offs.store');
    });
    Route::post('loans/{loan}/approve', [LoanController::class, 'approve'])->whereNumber('loan')->name('loans.approve');
    Route::post('loans/{loan}/send-back', [LoanController::class, 'sendBack'])->whereNumber('loan')->name('loans.send-back');
    Route::post('loans/amendments/{amendment}/approve', [LoanChangeController::class, 'approveAmendment'])->whereNumber('amendment')->name('loans.amendments.approve');
    Route::post('loans/amendments/{amendment}/reject', [LoanChangeController::class, 'rejectAmendment'])->whereNumber('amendment')->name('loans.amendments.reject');
    Route::post('loans/write-offs/{writeOff}/approve', [LoanChangeController::class, 'approveWriteOff'])->whereNumber('writeOff')->name('loans.write-offs.approve');
    Route::post('loans/write-offs/{writeOff}/reject', [LoanChangeController::class, 'rejectWriteOff'])->whereNumber('writeOff')->name('loans.write-offs.reject');
    // The withholding rule changes what every repayment withholds: the Owner's key.
    Route::middleware('can:accounting.policy.manage')->group(function () {
        Route::post('loans/tds-rules', [LoanTdsRuleController::class, 'store'])->name('loans.tds-rules.store');
        Route::put('loans/tds-rules/{rule}', [LoanTdsRuleController::class, 'update'])->whereNumber('rule')->name('loans.tds-rules.update');
    });

    // 26 Sep 2026: the chart key is split (Support\ChartPermissions). Creating
    // sits on head.create, and the controller asks for structure.manage when the
    // request is structural (a root heading, a re-parent, a re-type).
    Route::middleware('can:accounting.chart.head.create')->group(function () {
        Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store');
        // The code the add form proposes from the chosen parent (JSON).
        Route::get('accounts/next-code', [AccountController::class, 'nextCode'])->name('accounts.next-code');
        // A ledger head added from inside another form's picker (JSON).
        Route::get('accounts/quick/options', [QuickAccountController::class, 'options'])->name('accounts.quick.options');
        Route::post('accounts/quick', [QuickAccountController::class, 'store'])->name('accounts.quick.store');
    });
    Route::put('accounts/{account}', [AccountController::class, 'update'])->middleware('can:accounting.chart.head.update')->name('accounts.update');
    Route::delete('accounts/{account}', [AccountController::class, 'destroy'])->middleware('can:accounting.chart.head.delete')->name('accounts.destroy');

    // ⭐ VAT settings: the switch, the BIN, the rates (VAT plan T1), and filing
    // a return. It changes what every invoice charges, so it has its own key.
    Route::middleware('can:accounting.vat.manage')->group(function () {
        Route::post('vat-return', [VatReturnController::class, 'store'])->name('vat-return.store');
        Route::get('vat-settings', [VatSettingsController::class, 'index'])->name('vat-settings.index');
        Route::put('vat-settings', [VatSettingsController::class, 'update'])->name('vat-settings.update');
        Route::post('vat-settings/rates', [VatSettingsController::class, 'storeRate'])->name('vat-settings.rates.store');
    });

    // ⭐ Finance policy (29 Sep 2026): the limits, caps and thresholds the
    // books obey. The Owner's key; grantable in Settings > Roles.
    Route::middleware('can:accounting.policy.manage')->group(function () {
        Route::get('finance-policy', [FinancePolicyController::class, 'edit'])->name('finance-policy.edit');
        Route::put('finance-policy', [FinancePolicyController::class, 'update'])->name('finance-policy.update');
    });

    // ⚠ No delete route. A money account is deactivated, never removed:
    // a payment made in August must still be nameable in December.
    Route::middleware('can:accounting.money_account.manage')->group(function () {
        Route::post('money-accounts', [MoneyAccountController::class, 'store'])->name('money-accounts.store');
        Route::put('money-accounts/{moneyAccount}', [MoneyAccountController::class, 'update'])->name('money-accounts.update');
    });

    // ⚠⚠ ROUTE, SCREEN, NAV LINK AND PERMISSION IN THE SAME CHANGE. This
    // project has shipped ten engines that worked perfectly and could not be
    // reached from anywhere, and `4900 Other Income` was itself an account no
    // screen could write to for a month. The sidebar entry goes in beside this.
    //
    // ⚠ Reading sits behind `accounting.view` inside the controller; recording
    // has its own permission because receiving money is a different act from
    // reading the books, and Finance should be able to do it without being
    // handed the manual-journal key.
    Route::get('other-income', [IncomeVoucherController::class, 'index'])->name('other-income.index');
    Route::get('other-income/{voucher}/print', [IncomeVoucherController::class, 'print'])->name('other-income.print');

    Route::middleware('can:accounting.income.record')->group(function () {
        Route::post('other-income', [IncomeVoucherController::class, 'store'])->name('other-income.store');
        Route::post('other-income/{voucher}/evidence', [IncomeVoucherController::class, 'uploadEvidence'])->name('other-income.evidence.store');
        Route::delete('other-income/{voucher}/evidence/{attachment}', [IncomeVoucherController::class, 'deleteEvidence'])->name('other-income.evidence.destroy');
    });

    // ⭐ Edit, delete and correct, Phase 0 (17 Oct 2026). The Correct page is the
    // record form pre-filled; the correction itself goes through the generic
    // records.preview / records.act routes and CorrectionService.
    Route::middleware('can:accounting.correct')->group(function () {
        Route::get('other-income/{voucher}/correct', [IncomeVoucherController::class, 'correct'])->name('other-income.correct');
    });

    // The monthly Corrections report (owner's answer 3): Owner and Finance.
    // ⭐ Owner withdrawals (Phase 2, 17 Oct 2026): stock the proprietor takes
    // for personal use, a drawing and never a sale. Reading, writing a draft
    // and posting are two permissions; AUTHORISING has no route gate because
    // the action decides (a second person with owner_withdrawal.authorise, or
    // the proprietor himself with a note), and a reader may hold that right.
    Route::middleware('can:accounting.owner_withdrawal.view')->group(function () {
        Route::get('owner-withdrawals', [OwnerWithdrawalController::class, 'index'])->name('owner-withdrawals.index');
        Route::get('owner-withdrawals/estimate', [OwnerWithdrawalController::class, 'estimate'])->name('owner-withdrawals.estimate');
        Route::post('owner-withdrawals/{ownerWithdrawal}/authorise', [OwnerWithdrawalController::class, 'authorise'])
            ->whereNumber('ownerWithdrawal')->name('owner-withdrawals.authorise');
    });
    Route::middleware('can:accounting.owner_withdrawal.manage')->group(function () {
        Route::post('owner-withdrawals', [OwnerWithdrawalController::class, 'store'])->name('owner-withdrawals.store');
        Route::put('owner-withdrawals/{ownerWithdrawal}', [OwnerWithdrawalController::class, 'update'])
            ->whereNumber('ownerWithdrawal')->name('owner-withdrawals.update');
        Route::delete('owner-withdrawals/{ownerWithdrawal}', [OwnerWithdrawalController::class, 'destroy'])
            ->whereNumber('ownerWithdrawal')->name('owner-withdrawals.destroy');
        Route::post('owner-withdrawals/{ownerWithdrawal}/reopen', [OwnerWithdrawalController::class, 'reopen'])
            ->whereNumber('ownerWithdrawal')->name('owner-withdrawals.reopen');
        Route::post('owner-withdrawals/{ownerWithdrawal}/post', [OwnerWithdrawalController::class, 'post'])
            ->whereNumber('ownerWithdrawal')->name('owner-withdrawals.post');
    });

    Route::middleware('can:corrections.report.view')->group(function () {
        Route::get('corrections', [CorrectionsReportController::class, 'index'])->name('corrections.index');
    });

    // ⚠⚠ The highest-risk permission in the system (C-GL-6): the one action that
    // can move money between any two accounts with no document behind it.
    // Owner only, and being Owner-only IS the control in this phase.
    Route::middleware('can:accounting.journal.post')->group(function () {
        // ⭐ Opening receivables and payables, one row per open invoice or bill
        // at cutover (finance decision A4/A5, 29 Sep 2026).
        Route::get('opening-balances', [OpeningBalanceController::class, 'index'])->name('opening-balances.index');
        // Bad-debt write-offs, discounts and set-offs against named documents (29 Sep 2026).
        Route::get('adjustments', [PartyAdjustmentController::class, 'index'])->name('adjustments.index');
        // Money to another business of the group, booked in both (29 Sep 2026).
        Route::get('inter-business', [InterBusinessTransferController::class, 'index'])->name('inter-business.index');
        Route::post('inter-business', [InterBusinessTransferController::class, 'store'])->name('inter-business.store');
        Route::post('adjustments', [PartyAdjustmentController::class, 'store'])->name('adjustments.store');
        Route::delete('adjustments/{adjustment}', [PartyAdjustmentController::class, 'destroy'])->whereNumber('adjustment')->name('adjustments.destroy');
        Route::post('opening-balances', [OpeningBalanceController::class, 'store'])->name('opening-balances.store');
        // ⭐ B13 (24 Oct 2026): Reverse with a reason; the document is kept, marked.
        Route::post('opening-balances/{kind}/{id}/reverse', [OpeningBalanceController::class, 'reverse'])
            ->whereIn('kind', ['receivable', 'payable', 'advance'])->whereNumber('id')->name('opening-balances.reverse');

        Route::get('journals/new/manual', [JournalController::class, 'create'])->name('journals.create');
        Route::post('journals', [JournalController::class, 'store'])->name('journals.store');
        Route::post('journals/{journal}/reverse', [JournalController::class, 'reverse'])->name('journals.reverse');
        // ⭐ Corrections wave 2: a manual journal's Correct form (records.act does the work).
        Route::get('journals/{journal}/correct', [JournalController::class, 'correct'])->whereNumber('journal')->name('journals.correct');
        // ⭐ Corrections wave 2: Reverse the latest released month of an accrual or prepayment.
        Route::post('amortizations/{schedule}/reverse-month', [AmortizationController::class, 'reverseMonth'])->whereNumber('schedule')->name('amortizations.reverse-month');

        // ⚠ A transfer posts a journal that moves money, so it belongs to the
        // same permission rather than to a new one. The document exists so the
        // move is NOT a bare journal: it names both accounts, carries the fee,
        // and is numbered - which a hand-written journal never is.
        Route::get('money-transfers', [MoneyTransferController::class, 'index'])->name('money-transfers.index');
        Route::post('money-transfers', [MoneyTransferController::class, 'store'])->name('money-transfers.store');
    });

    // ── Payment, Receive, Contra and Journal vouchers ──────────────────────
    // Reading follows the books (`accounting.view`); drafting is `prepare`;
    // whatever writes to the ledger - post, reverse - is `post`.
    // ⚠ The `doc/...` routes are registered before the `{type}` wildcard.
    Route::middleware('can:accounting.view')->group(function () {
        Route::get('vouchers/doc/{voucher}', [VoucherController::class, 'show'])->whereNumber('voucher')->name('vouchers.show');
        Route::get('vouchers/doc/{voucher}/print', [VoucherController::class, 'print'])->whereNumber('voucher')->name('vouchers.print');
    });

    // ⭐ The second approval on a large payment (finance decision B13). No
    // permission gate here on purpose: the approval STAGE decides who may act
    // (canBeActionedBy), and the preparer never can.
    Route::post('vouchers/doc/{voucher}/approve', [VoucherController::class, 'approve'])->whereNumber('voucher')->name('vouchers.approve');
    Route::post('vouchers/doc/{voucher}/send-back', [VoucherController::class, 'sendBack'])->whereNumber('voucher')->name('vouchers.send-back');

    Route::middleware('can:accounting.voucher.prepare')->group(function () {
        // ⭐ Bill-wise details for a party's line (party ledgers, 20 Oct 2026),
        // registered before the `{type}` wildcard.
        Route::get('vouchers/party-items', [VoucherController::class, 'partyItems'])->name('vouchers.party-items');
        Route::get('vouchers/doc/{voucher}/edit', [VoucherController::class, 'edit'])->whereNumber('voucher')->name('vouchers.edit');
        Route::put('vouchers/doc/{voucher}', [VoucherController::class, 'update'])->whereNumber('voucher')->name('vouchers.update');
        Route::delete('vouchers/doc/{voucher}', [VoucherController::class, 'destroy'])->whereNumber('voucher')->name('vouchers.destroy');
        Route::post('vouchers/doc/{voucher}/duplicate', [VoucherController::class, 'duplicate'])->whereNumber('voucher')->name('vouchers.duplicate');
        Route::post('vouchers/doc/{voucher}/evidence', [VoucherController::class, 'uploadEvidence'])->whereNumber('voucher')->name('vouchers.evidence.store');
        Route::delete('vouchers/doc/{voucher}/evidence/{attachment}', [VoucherController::class, 'deleteEvidence'])->whereNumber('voucher')->name('vouchers.evidence.destroy');
        Route::get('vouchers/{type}/create', [VoucherController::class, 'create'])->name('vouchers.create');
        Route::post('vouchers/{type}', [VoucherController::class, 'store'])->name('vouchers.store');
    });

    Route::middleware('can:accounting.voucher.post')->group(function () {
        Route::post('vouchers/doc/{voucher}/post', [VoucherController::class, 'post'])->whereNumber('voucher')->name('vouchers.post');
        Route::post('vouchers/doc/{voucher}/reverse', [VoucherController::class, 'reverse'])->whereNumber('voucher')->name('vouchers.reverse');
    });

    // ⭐ Corrections wave 2 (24 Oct 2026): a posted voucher's Correct form (the
    // correction itself goes through records.preview / records.act) and Fix
    // details (the maker or an accountant; the controller and the action check).
    Route::get('vouchers/doc/{voucher}/correct', [VoucherController::class, 'correct'])->whereNumber('voucher')->middleware('can:accounting.correct')->name('vouchers.correct');
    Route::post('vouchers/doc/{voucher}/fix-details', [VoucherController::class, 'fixDetails'])->whereNumber('voucher')->middleware('can:accounting.view')->name('vouchers.fix-details');

    Route::get('vouchers/{type}', [VoucherController::class, 'index'])->middleware('can:accounting.view')->name('vouchers.index');

    // The cheque register (finance decision C5, 29 Sep 2026): reading follows
    // the books; clearing or bouncing a cheque writes to them, so it is `post`.
    Route::get('cheques', [ChequeRegisterController::class, 'index'])->middleware('can:accounting.view')->name('cheques.index');
    Route::post('cheques/{voucher}', [ChequeRegisterController::class, 'update'])->whereNumber('voucher')->middleware('can:accounting.voucher.post')->name('cheques.update');
    // ⭐ Corrections wave 2 (24 Oct 2026): Undo status, the Owner only (checked by the action).
    Route::post('cheques/{voucher}/undo', [ChequeRegisterController::class, 'undo'])->whereNumber('voucher')->middleware('can:accounting.voucher.post')->name('cheques.undo');

    // ⭐ A supplier can also be our customer (decision D-F, 18 Oct 2026).
    // Reading the set-offs and the combined statement: whoever reads both
    // suppliers and customers. Raising a set-off: Owner and Finance.
    // Approving: the voucher workflow's stage, checked by the approval itself.
    Route::middleware(['can:purchasing.reports.view', 'can:sales.reports.view'])->group(function () {
        Route::get('set-offs', [SetOffController::class, 'index'])->name('set-offs.index');
        Route::get('set-offs/{setOff}', [SetOffController::class, 'show'])->whereNumber('setOff')->name('set-offs.show');
        Route::get('party-statement', [PartyStatementController::class, 'index'])->name('party-statement');
    });
    Route::post('set-offs', [SetOffController::class, 'store'])->middleware('can:accounting.set_off.manage')->name('set-offs.store');
    Route::post('set-offs/{setOff}/approve', [SetOffController::class, 'approve'])->whereNumber('setOff')->name('set-offs.approve');
    Route::post('set-offs/{setOff}/reject', [SetOffController::class, 'reject'])->whereNumber('setOff')->name('set-offs.reject');

    // ⭐ B13 (24 Oct 2026; FINAL-PLAN section 7): the Owner's merges, dry run
    // first. The Owner gate is the action's own (Owners::is), at every step.
    Route::post('merge/{kind}/{id}', [MergeController::class, 'party'])
        ->whereIn('kind', ['customer', 'supplier'])->whereNumber('id')->name('merge.party');
    Route::post('accounts/{account}/retire', [MergeController::class, 'head'])->whereNumber('account')->name('accounts.retire');

    // Linking changes both lists, so it needs both manage permissions.
    Route::middleware(['can:purchasing.suppliers.manage', 'can:sales.customers.manage'])->group(function () {
        Route::post('parties/link', [PartyLinkController::class, 'link'])->name('parties.link');
        Route::post('parties/unlink/{customer}', [PartyLinkController::class, 'unlink'])->whereNumber('customer')->name('parties.unlink');
        Route::post('parties/customer-from-supplier/{supplier}', [PartyLinkController::class, 'customerFromSupplier'])->whereNumber('supplier')->name('parties.customer-from-supplier');
        Route::post('parties/supplier-from-customer/{customer}', [PartyLinkController::class, 'supplierFromCustomer'])->whereNumber('customer')->name('parties.supplier-from-customer');
    });
});
