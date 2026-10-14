<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\BalanceSheet;
use App\Modules\Accounting\Support\IncomeStatement;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\TrialBalance;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The profit and loss and the balance sheet.
 *
 * ⚠⚠ THE PROPERTY THAT MATTERS: a balance sheet that balances, from a ledger
 * whose income and expense have never been closed into equity. If it needed a
 * special case to balance, the design would be wrong - see C-FS-5.
 *
 * Fiscal year here is July to June (C-GL-4), so 2026-08-03 sits in FY 2026-27
 * and anything dated before 2026-07-01 is "brought forward".
 *
 * See FINANCIAL-STATEMENTS-ARCHITECTURE.md.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-03 10:00:00');

    $this->business = Business::create([
        'name' => 'Statement Co',
        'slug' => 'statement-co',
        'fiscal_year_start_month' => 7,
    ]);
    TenantScope::enter($this->business->id);

    foreach (['accounting.view', 'accounting.journal.post'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $role = Role::firstOrCreate(['name' => 'Founder', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $role->givePermissionTo(['accounting.view', 'accounting.journal.post']);

    $this->founder = User::factory()->create();
    $this->founder->businesses()->attach($this->business->id);
    $this->founder->assignRole($role);

    $this->seed(ChartOfAccountsSeeder::class);

    $this->bank = moneyHead('cash_at_bank');
    $this->capital = Account::bySlug('owners_capital');
    $this->stock = Account::bySlug('stock_on_hand');
    $this->payable = Account::bySlug('accounts_payable');
    $this->revenue = Account::bySlug('sales_revenue');             // 4101 Product Sales, under the 4100 heading
    $this->returns = Account::where('code', '4200')->first();      // contra-income
    $this->cogs = Account::where('code', '5100')->first();         // Cost of Sales group
    $this->rent = Account::where('code', '6051')->first();         // Operating Expenses group
    $this->salaries = Account::where('code', '6131')->first();
    $this->depreciation = Account::where('code', '1290')->first(); // contra-asset

    $this->post = function (int $debit, int $credit, string $amount, string $date = '2026-08-03') {
        return app(PostJournal::class)->handle(new JournalDraft(
            date: Carbon::parse($date),
            lines: [
                JournalLineDraft::debit($debit, Money::ofTaka($amount)),
                JournalLineDraft::credit($credit, Money::ofTaka($amount)),
            ],
        ), $this->founder);
    };

    $this->pl = fn (string $from = '2026-07-01', string $to = '2026-08-31') => IncomeStatement::between(
        Carbon::parse($from)->startOfDay(),
        Carbon::parse($to)->endOfDay(),
    );

    $this->bs = fn (string $asAt = '2026-08-31') => BalanceSheet::asAt(
        Carbon::parse($asAt)->endOfDay(),
        $this->business,
    );
});

afterEach(fn () => Carbon::setTestNow());

// ─── THE PROFIT AND LOSS ────────────────────────────────────────────────────

it('computes gross profit from the chart s own cost-of-sales group', function () {
    ($this->post)($this->bank->id, $this->revenue->id, '100000');
    ($this->post)($this->cogs->id, $this->stock->id, '60000');
    ($this->post)($this->rent->id, $this->bank->id, '15000');

    $statement = ($this->pl)();

    // 100,000 income - 60,000 cost of sales = 40,000 gross.
    expect($statement->totalIncome()->format())->toBe('100,000.00')
        ->and($statement->totalCostOfSales()->format())->toBe('60,000.00')
        ->and($statement->grossProfit()->format())->toBe('40,000.00')
        // 40,000 gross - 15,000 operating = 25,000 net.
        ->and($statement->totalOperatingExpenses()->format())->toBe('15,000.00')
        ->and($statement->netProfit()->format())->toBe('25,000.00')
        ->and($statement->grossMarginPercent())->toBe(40.0);
});

it('reduces income by a contra account without any special case', function () {
    ($this->post)($this->bank->id, $this->revenue->id, '100000');
    // A return: debit to an INCOME-typed account, which reduces income because
    // the ledger stores one signed amount (C-FS-2).
    ($this->post)($this->returns->id, $this->bank->id, '18000');

    $statement = ($this->pl)();

    expect($statement->totalIncome()->format())->toBe('82,000.00');

    $income = $statement->income->first();
    $contra = $income->lines->first(fn ($line) => $line->account->code === '4200');

    expect($contra->isContra())->toBeTrue()
        ->and($contra->presentedAmount()->format())->toBe('18,000.00');
});

// ⚠⚠ C-FS-1: the defect this test exists to prevent. Using `closing()` instead
// of the period movement would report June's trading under August's heading.
it('reports the period s movement, never the balance carried from before it', function () {
    ($this->post)($this->bank->id, $this->revenue->id, '500000', '2026-06-15'); // last fiscal year
    ($this->post)($this->bank->id, $this->revenue->id, '80000', '2026-08-03');  // this one

    $statement = ($this->pl)('2026-07-01', '2026-08-31');

    expect($statement->totalIncome()->format())->toBe('80,000.00');
});

it('names a loss a loss rather than a negative profit', function () {
    ($this->post)($this->bank->id, $this->revenue->id, '30000');
    ($this->post)($this->rent->id, $this->bank->id, '50000');

    $statement = ($this->pl)();

    expect($statement->netProfit()->isCredit())->toBeTrue()
        ->and($statement->netProfit()->absolute()->format())->toBe('20,000.00');
});

it('has no gross margin when there was no income, rather than a margin of zero', function () {
    ($this->post)($this->rent->id, $this->bank->id, '5000');

    expect(($this->pl)()->grossMarginPercent())->toBeNull();
});

// ─── THE BALANCE SHEET ──────────────────────────────────────────────────────

// ⚠⚠ THE MILESTONE. Assets = Liabilities + Equity, on a ledger where profit has
// never been closed into equity.
it('balances while the profit is still sitting unclosed in the income accounts', function () {
    ($this->post)($this->bank->id, $this->capital->id, '500000');   // capital in
    ($this->post)($this->stock->id, $this->payable->id, '200000');  // stock on credit
    ($this->post)($this->bank->id, $this->revenue->id, '100000');   // a sale
    ($this->post)($this->cogs->id, $this->stock->id, '60000');      // its cost
    ($this->post)($this->rent->id, $this->bank->id, '15000');       // an expense

    $sheet = ($this->bs)();

    // Assets: bank 500,000 + 100,000 - 15,000 = 585,000; stock 200,000 - 60,000 = 140,000.
    expect($sheet->totalAssets()->format())->toBe('725,000.00')
        ->and($sheet->totalLiabilities()->format())->toBe('200,000.00')
        ->and($sheet->totalEquityAccounts()->format())->toBe('500,000.00')
        // The unclosed result: 100,000 - 60,000 - 15,000 = 25,000.
        ->and($sheet->unclosedResult()->format())->toBe('25,000.00')
        ->and($sheet->totalEquity()->format())->toBe('525,000.00')
        ->and($sheet->totalLiabilitiesAndEquity()->format())->toBe('725,000.00');

    // ⚠ EXACTLY zero, in whole paisa.
    expect($sheet->balances())->toBeTrue()
        ->and($sheet->difference()->minor)->toBe(0);
});

it('balances when the business made a loss', function () {
    ($this->post)($this->bank->id, $this->capital->id, '100000');
    ($this->post)($this->rent->id, $this->bank->id, '30000');

    $sheet = ($this->bs)();

    expect($sheet->unclosedResult()->isCredit())->toBeTrue()
        ->and($sheet->unclosedResult()->absolute()->format())->toBe('30,000.00')
        ->and($sheet->totalEquity()->format())->toBe('70,000.00')
        ->and($sheet->totalAssets()->format())->toBe('70,000.00')
        ->and($sheet->balances())->toBeTrue();
});

it('splits the result into what came before this fiscal year and what came after', function () {
    ($this->post)($this->bank->id, $this->capital->id, '500000', '2026-06-01');
    ($this->post)($this->bank->id, $this->revenue->id, '40000', '2026-06-15');  // FY 2025-26
    ($this->post)($this->bank->id, $this->revenue->id, '70000', '2026-08-03');  // FY 2026-27

    $sheet = ($this->bs)('2026-08-31');

    expect($sheet->presentedResultBroughtForward()->format())->toBe('40,000.00')
        ->and($sheet->presentedResultForTheYear()->format())->toBe('70,000.00')
        ->and($sheet->unclosedResult()->format())->toBe('110,000.00')
        ->and($sheet->balances())->toBeTrue();
});

it('shows a contra asset as a deduction without special handling', function () {
    ($this->post)($this->bank->id, $this->capital->id, '500000');
    ($this->post)($this->rent->id, $this->depreciation->id, '9000');

    $sheet = ($this->bs)();

    $nonCurrent = $sheet->assets->first(fn ($section) => $section->code === '1200');
    $line = $nonCurrent->lines->first(fn ($line) => $line->account->code === '1290');

    expect($line->isContra())->toBeTrue()
        ->and($line->presentedAmount()->format())->toBe('9,000.00')
        // The section total is reduced by ordinary addition.
        ->and($nonCurrent->total()->absolute()->format())->toBe('9,000.00')
        ->and($sheet->balances())->toBeTrue();
});

// ⚠ Dates stay at or before the frozen "now": the ledger refuses a future-dated
// journal, and that guard is correct - it caught this test's first draft.
it('is a photograph, so a later posting does not appear on an earlier date', function () {
    ($this->post)($this->bank->id, $this->capital->id, '100000', '2026-07-05');
    ($this->post)($this->bank->id, $this->capital->id, '50000', '2026-08-02');

    expect(($this->bs)('2026-07-31')->totalAssets()->format())->toBe('100,000.00')
        ->and(($this->bs)('2026-08-03')->totalAssets()->format())->toBe('150,000.00');
});

// ─── THE TIE-BACK TO THE LEDGER ─────────────────────────────────────────────

// ⚠⚠ The property a report cannot prove about itself: that it agrees with the
// ledger it summarises. Both statements are built from TrialBalance's rows, and
// this asserts the partition is exhaustive - every closing balance lands either
// on the balance sheet or in the unclosed result, and nothing is counted twice.
it('accounts for every closing balance in the ledger, once', function () {
    ($this->post)($this->bank->id, $this->capital->id, '500000');
    ($this->post)($this->stock->id, $this->payable->id, '200000');
    ($this->post)($this->bank->id, $this->revenue->id, '100000');
    ($this->post)($this->returns->id, $this->bank->id, '5000');
    ($this->post)($this->cogs->id, $this->stock->id, '60000');
    ($this->post)($this->salaries->id, $this->bank->id, '25000');

    $trialBalance = TrialBalance::between(
        Carbon::parse('2026-07-01')->startOfDay(),
        Carbon::parse('2026-08-31')->endOfDay(),
    );
    $sheet = ($this->bs)('2026-08-31');
    $statement = ($this->pl)('2026-07-01', '2026-08-31');

    // The ledger itself balances.
    expect($trialBalance->balances())->toBeTrue();

    // The balance sheet balances.
    expect($sheet->balances())->toBeTrue();

    // ⚠ And the two statements agree with each other: the whole of this fiscal
    // year's profit and loss IS the balance sheet's result for the year, because
    // the ledger began inside this fiscal year.
    expect($statement->netProfit()->format())->toBe($sheet->presentedResultForTheYear()->format())
        ->and($sheet->presentedResultBroughtForward()->minor)->toBe(0);

    // 100,000 - 5,000 returns - 60,000 cost - 25,000 salaries = 10,000.
    expect($statement->netProfit()->format())->toBe('10,000.00');
});

it('reports nothing rather than everything when the ledger is empty', function () {
    expect(($this->pl)()->isEmpty())->toBeTrue()
        ->and(($this->bs)()->isEmpty())->toBeTrue()
        ->and(($this->bs)()->balances())->toBeTrue();
});
