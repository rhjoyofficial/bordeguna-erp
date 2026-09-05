<?php

use App\Models\User;
use App\Modules\Accounting\Actions\RecordIncomeVoucher;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\IncomeVoucher;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\IncomeStatement;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Money that came in and was not a sale.
 *
 * ⚠⚠ THE HOLE THIS FILLS: `4900 Other Income` was postable from the day the
 * chart was seeded and NOT ONE SCREEN COULD WRITE TO IT. Scrap, bank interest,
 * rent received and supplier refunds had to be entered as hand-written
 * journals. The expense side had a proper document from August; this is its
 * mirror, and these tests hold it to the same rules.
 *
 * ⚠ The rule this document has that the expense side does not is the one worth
 * guarding hardest: it must never become a back door into sales revenue.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-06 10:00:00');

    $this->business = Business::create([
        'name' => 'Income Co',
        'slug' => 'income-co',
        'fiscal_year_start_month' => 7,
    ]);
    TenantScope::enter($this->business->id);

    foreach (['accounting.view', 'accounting.income.record', 'accounting.journal.post'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $owner = Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $owner->givePermissionTo(['accounting.view', 'accounting.income.record']);

    $reader = Role::firstOrCreate(['name' => 'Reader', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $reader->givePermissionTo('accounting.view');

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole($owner);

    $this->reader = User::factory()->create();
    $this->reader->businesses()->attach($this->business->id);
    $this->reader->assignRole($reader);

    $this->seed(ChartOfAccountsSeeder::class);

    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'BRAC Bank',
        'type' => 'bank',
        'is_active' => true,
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);

    $this->scrap = Account::where('code', '4910')->sole();

    $this->record = fn (array $overrides = []) => app(RecordIncomeVoucher::class)->handle(
        moneyAccount: $overrides['moneyAccount'] ?? $this->bank,
        receivedOn: $overrides['receivedOn'] ?? '2026-09-06',
        lines: $overrides['lines'] ?? [[
            'account' => $this->scrap,
            'amount' => '1500',
            'description' => 'Empty drums sold',
        ]],
        createdBy: $this->owner,
        receivedFrom: $overrides['receivedFrom'] ?? 'Adabor scrap dealer',
        reference: $overrides['reference'] ?? null,
    );

    $this->balanceOn = fn (string $code) => (int) DB::table('journal_lines')
        ->where('account_id', Account::where('code', $code)->sole()->id)
        ->sum('amount_minor');
});

afterEach(fn () => Carbon::setTestNow());

// ─── THE POSTING ────────────────────────────────────────────────────────────

it('debits the money account and credits the income head', function () {
    $voucher = ($this->record)();

    expect($voucher->total)->toBe('1500.0000')
        ->and($voucher->payer())->toBe('Adabor scrap dealer')
        // Money in is a DEBIT to the asset, income is a CREDIT and therefore
        // negative in the ledger's signed convention.
        ->and(($this->balanceOn)('1120'))->toBe(150000)
        ->and(($this->balanceOn)('4910'))->toBe(-150000);

    $journal = Journal::query()->latest('id')->first();

    expect($journal->memo)->toContain($voucher->voucher_number)
        ->and(DB::table('journal_lines')->where('journal_id', $journal->id)->sum('amount_minor'))->toBe(0);
});

it('splits one receipt across several heads', function () {
    $voucher = ($this->record)(['lines' => [
        ['account' => $this->scrap, 'amount' => '1500', 'description' => 'Drums'],
        ['account' => Account::where('code', '4920')->sole(), 'amount' => '250', 'description' => 'Quarterly interest'],
    ]]);

    expect($voucher->total)->toBe('1750.0000')
        ->and($voucher->lines)->toHaveCount(2)
        ->and(($this->balanceOn)('1120'))->toBe(175000)
        ->and(($this->balanceOn)('4910'))->toBe(-150000)
        ->and(($this->balanceOn)('4920'))->toBe(-25000);
});

it('numbers vouchers per fiscal year', function () {
    expect(($this->record)()->voucher_number)->toBe('INC-2026-27-0001')
        ->and(($this->record)()->voucher_number)->toBe('INC-2026-27-0002');
});

// ─── WHAT IT REFUSES ────────────────────────────────────────────────────────

it('refuses sales revenue outright, because a sale has no business arriving here', function () {
    // ⚠⚠ THE BOUNDARY OF THE WHOLE FEATURE. A sale recorded here would carry no
    // customer on the receivable, no stock movement and no cost of goods, so
    // the gross margin would be a fiction and the sales module would disagree
    // with the ledger about what was sold.
    expect(fn () => ($this->record)(['lines' => [
        ['account' => Account::bySlug('sales_revenue'), 'amount' => '5000'],
    ]]))->toThrow(ValidationException::class);

    expect(IncomeVoucher::count())->toBe(0)
        ->and(Journal::count())->toBe(0);
});

it('refuses the contra pair too, because a return belongs to the sale it reverses', function () {
    foreach (['sales_returns', 'discounts_allowed'] as $slug) {
        expect(fn () => ($this->record)(['lines' => [
            ['account' => Account::bySlug($slug), 'amount' => '100'],
        ]]))->toThrow(ValidationException::class);
    }

    expect(IncomeVoucher::count())->toBe(0);
});

it('refuses an account that is not income at all', function () {
    expect(fn () => ($this->record)(['lines' => [
        ['account' => Account::bySlug('accounts_payable'), 'amount' => '100'],
    ]]))->toThrow(ValidationException::class);
});

it('refuses a heading, which is what 4900 became', function () {
    expect(fn () => ($this->record)(['lines' => [
        ['account' => Account::where('code', '4900')->sole(), 'amount' => '100'],
    ]]))->toThrow(ValidationException::class);
});

it('refuses a date in the future', function () {
    expect(fn () => ($this->record)(['receivedOn' => '2026-09-30']))->toThrow(ValidationException::class);
});

it('refuses a closed period', function () {
    // The period lock is a "closed through" date, not a year and month pair.
    AccountingPeriod::closeThrough(Carbon::parse('2026-09-30'), $this->owner);

    expect(fn () => ($this->record)())->toThrow(PeriodClosedException::class);

    expect(IncomeVoucher::count())->toBe(0);
});

it('refuses a voucher with no line worth recording', function () {
    expect(fn () => ($this->record)(['lines' => [
        ['account' => $this->scrap, 'amount' => '0'],
    ]]))->toThrow(ValidationException::class);
});

// ─── THE SCREEN ─────────────────────────────────────────────────────────────

it('shows the screen to somebody who can read the books', function () {
    ($this->record)();

    $this->actingAs($this->reader)->get('/accounting/other-income')
        ->assertOk()
        ->assertSee('INC-2026-27-0001');
});

it('lets the owner record income through the form', function () {
    $this->actingAs($this->owner)->post('/accounting/other-income', [
        'money_account_id' => $this->bank->id,
        'received_on' => '2026-09-06',
        'received_from' => 'Adabor scrap dealer',
        'reference' => 'SCRAP-01',
        'lines' => [
            ['account_id' => $this->scrap->id, 'amount' => '1500', 'description' => 'Empty drums sold'],
        ],
    ])->assertRedirect('/accounting/other-income')->assertSessionHasNoErrors();

    expect(IncomeVoucher::count())->toBe(1)
        ->and(($this->balanceOn)('4910'))->toBe(-150000);
});

it('will not let a reader record income, only read it', function () {
    $this->actingAs($this->reader)->post('/accounting/other-income', [
        'money_account_id' => $this->bank->id,
        'received_on' => '2026-09-06',
        'lines' => [['account_id' => $this->scrap->id, 'amount' => '1500']],
    ])->assertForbidden();

    expect(IncomeVoucher::count())->toBe(0);
});

it('offers every income head except the sales ones', function () {
    $response = $this->actingAs($this->owner)->get('/accounting/other-income');

    $offered = collect($response->viewData('page')['props']['incomeAccounts'])->pluck('label');

    // ⚠⚠ The regression this guards: `system_slug NOT IN (...)` is NULL for a
    // row whose slug is NULL, so a bare whereNotIn hid EVERY head that has no
    // slug - which is all five of them.
    expect($offered->filter(fn ($label) => str_contains($label, '4910'))->count())->toBe(1)
        ->and($offered->filter(fn ($label) => str_contains($label, '4990'))->count())->toBe(1)
        ->and($offered->filter(fn ($label) => str_contains($label, '4100'))->all())->toBe([])
        ->and($offered->filter(fn ($label) => str_contains($label, '4200'))->all())->toBe([])
        ->and($offered->filter(fn ($label) => str_contains($label, '4210'))->all())->toBe([])
        // And never the heading itself.
        ->and($offered->filter(fn ($label) => str_contains($label, '4900'))->all())->toBe([]);
});

it('prints the voucher twice on one sheet', function () {
    $voucher = ($this->record)();

    $this->actingAs($this->reader)->get("/accounting/other-income/{$voucher->id}/print")
        ->assertOk()
        ->assertSee('INC-2026-27-0001');
});

// ─── WHAT IT BUYS ───────────────────────────────────────────────────────────

it('puts other income on the profit and loss under its own heading', function () {
    ($this->record)();

    $statement = IncomeStatement::between(
        Carbon::parse('2026-07-01')->startOfDay(),
        Carbon::parse('2026-09-30')->endOfDay(),
    );

    $section = $statement->income->firstWhere('name', 'Other Income');

    expect($section)->not->toBeNull()
        ->and($section->lines->first()->account->name)->toBe('Scrap and Waste Sales')
        ->and($statement->totalIncome()->format())->toBe('1,500.00')
        // ⚠ No cost of sales, because nothing was sold. Scrap income raising
        // gross profit with no cost behind it is correct and is exactly why it
        // must not be booked as sales revenue.
        ->and($statement->totalCostOfSales()->format())->toBe('0.00');
});
