<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Purchasing\Models\ExpenseVoucher;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Recording rent, fuel and utilities, through the real HTTP layer.
 *
 * ⚠⚠ WHY THIS FILE EXISTS. `RecordExpenseVoucher` was complete, correct and
 * covered by its own tests - and had **no route and no controller**. Stock could
 * be bought and sold, but nothing the business spent on running itself could be
 * entered anywhere, so most of the profit and loss account had no source. That
 * is the same failure as the supplier payment gap, and the engine tests were
 * green throughout both.
 *
 * ⚠ These go through HTTP on purpose and assert on the LEDGER. A 302 proves the
 * route exists; only the journal proves the money moved.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-09 10:00:00');

    $this->business = Business::create(['name' => 'Expense Co', 'slug' => 'expense-co', 'fiscal_year_start_month' => 7]);
    $this->other = Business::create(['name' => 'Rival Expense Co', 'slug' => 'rival-expense-co', 'fiscal_year_start_month' => 7]);

    TenantScope::enter($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new PurchasingPermissionsSeeder)->run();

    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->seed(ChartOfAccountsSeeder::class);
    TenantScope::enter($this->business->id);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    $this->asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $this->actingAs($user);
    };

    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'BRAC Bank - Current',
        'type' => MoneyAccountType::Bank,
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);

    $this->rent = Account::where('code', '6051')->sole();
    $this->fuel = Account::where('code', '6011')->sole();

    $this->balanceOn = fn (string $code) => (int) JournalLine::whereIn(
        'account_id', Account::where('code', $code)->pluck('id')
    )->sum('amount_minor');

    $this->payload = fn (array $overrides = []) => array_replace([
        'money_account_id' => $this->bank->id,
        'spent_on' => '2026-08-09',
        'paid_to' => 'Adabor landlord',
        'reference' => 'RCPT-4410',
        'lines' => [['account_id' => $this->rent->id, 'amount' => '25000.00', 'description' => 'August rent']],
    ], $overrides);
});

afterEach(fn () => Carbon::setTestNow());

/**
 * ⚠⚠ THE TEST THAT WOULD HAVE CAUGHT THE WHOLE GAP. Not "does the action work"
 * but "can a person reach it".
 */
it('records an expense through the screen and moves the money in the ledger', function () {
    $this->actingAs($this->owner)
        ->post('/purchasing/expenses', ($this->payload)())
        ->assertRedirect('/purchasing/expenses');

    $voucher = ExpenseVoucher::sole();

    expect($voucher->total)->toBe('25000.0000')
        ->and($voucher->payee())->toBe('Adabor landlord')
        ->and($voucher->lines)->toHaveCount(1);

    // Dr Rent 25,000 · Cr Cash at Bank 25,000.
    expect(($this->balanceOn)('6051'))->toBe(2500000)
        ->and((int) JournalLine::where('account_id', $this->bank->account_id)->sum('amount_minor'))->toBe(-2500000);

    Journal::all()->each(fn (Journal $journal) => expect($journal->balance()->isZero())->toBeTrue());
});

it('splits one payment across several expense accounts', function () {
    $this->actingAs($this->owner)
        ->post('/purchasing/expenses', ($this->payload)([
            'lines' => [
                ['account_id' => $this->rent->id, 'amount' => '25000.00', 'description' => 'August rent'],
                ['account_id' => $this->fuel->id, 'amount' => '1500.50', 'description' => 'Delivery van'],
            ],
        ]))
        ->assertRedirect();

    expect(($this->balanceOn)('6051'))->toBe(2500000)
        ->and(($this->balanceOn)('6011'))->toBe(150050)
        ->and(ExpenseVoucher::sole()->total)->toBe('26500.5000');

    // ⚠ The whole-books check, on the smallest possible document. A voucher
    // that debits two accounts and credits one is where an off-by-a-paisa split
    // would first show up.
    expect((int) JournalLine::sum('amount_minor'))->toBe(0);
});

it('names a supplier as the payee when the money went to one', function () {
    $supplier = Supplier::create([
        'business_id' => $this->business->id, 'name' => 'Ad-Deen Traders', 'code' => 'ADDEEN',
    ]);

    $this->actingAs($this->owner)
        ->post('/purchasing/expenses', ($this->payload)(['supplier_id' => $supplier->id, 'paid_to' => null]))
        ->assertRedirect();

    expect(ExpenseVoucher::sole()->payee())->toBe('Ad-Deen Traders');
});

// ── The engine's refusals must reach the user as sentences, not 500s ─────────

it('refuses a non-expense account and says which line is wrong', function () {
    $revenue = Account::where('code', '4100')->sole();

    $this->actingAs($this->owner)
        ->post('/purchasing/expenses', ($this->payload)([
            'lines' => [['account_id' => $revenue->id, 'amount' => '500.00']],
        ]))
        ->assertSessionHasErrors('lines.0.account');

    expect(ExpenseVoucher::count())->toBe(0)
        ->and(Journal::count())->toBe(0);
});

it('refuses a voucher dated in the future', function () {
    $this->actingAs($this->owner)
        ->post('/purchasing/expenses', ($this->payload)(['spent_on' => '2026-09-01']))
        ->assertSessionHasErrors('spent_on');

    expect(ExpenseVoucher::count())->toBe(0);
});

it('refuses a money account belonging to another business', function () {
    // ⚠⚠ A bare `exists:money_accounts,id` would accept this and the global
    // scope would then hide the row the voucher points at. The scoped rule is
    // the reason this fails at validation rather than at a null relation later.
    TenantScope::enter($this->other->id);
    $foreign = MoneyAccount::create([
        'business_id' => $this->other->id,
        'name' => 'Rival Cash',
        'type' => MoneyAccountType::Cash,
        'account_id' => Account::bySlug('cash_in_hand')->id,
    ]);
    TenantScope::enter($this->business->id);

    $this->actingAs($this->owner)
        ->post('/purchasing/expenses', ($this->payload)(['money_account_id' => $foreign->id]))
        ->assertSessionHasErrors('money_account_id');

    expect(ExpenseVoucher::count())->toBe(0);
});

// ⚠ Audit 13 Oct: the picker hides a courier's account; the server now refuses
// one a form sends anyway.
it('refuses an expense paid from a courier account', function () {
    $courier = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'Pathao',
        'type' => MoneyAccountType::Courier,
        'account_id' => Account::bySlug('courier_receivable')->id,
    ]);

    $this->actingAs($this->owner)
        ->post('/purchasing/expenses', ($this->payload)(['money_account_id' => $courier->id]))
        ->assertSessionHasErrors('money_account_id');

    expect(ExpenseVoucher::count())->toBe(0);
});

it('refuses a voucher with no lines', function () {
    $this->actingAs($this->owner)
        ->post('/purchasing/expenses', ($this->payload)(['lines' => []]))
        ->assertSessionHasErrors('lines');
});

// ── Permission boundaries ───────────────────────────────────────────────────

it('lets Owner and Manager record an expense', function (string $role) {
    ($this->asRole)($role)
        ->post('/purchasing/expenses', ($this->payload)())
        ->assertRedirect();

    expect(ExpenseVoucher::count())->toBe(1);
})->with(['Owner', 'Manager']);

it('refuses Staff, who may see the list but not spend the money', function () {
    // ⚠ An expense voucher pays money out with no bill behind it and no goods
    // to count, which makes it the least verifiable way money leaves the
    // business. Staff read it; Owner and Manager decide it.
    ($this->asRole)('Staff')
        ->post('/purchasing/expenses', ($this->payload)())
        ->assertForbidden();

    expect(ExpenseVoucher::count())->toBe(0);

    ($this->asRole)('Staff')->get('/purchasing/expenses')->assertOk();
});

// ── The screen itself ───────────────────────────────────────────────────────

it('offers only postable expense accounts and only mapped money accounts', function () {
    // ⚠⚠ An unmapped money account throws MappingNotFound out of the engine, so
    // listing it in the picker would be offering a guaranteed failure - which is
    // exactly how the sandbox cycle first hit this.
    $unmapped = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'Petty cash, not yet mapped',
        'type' => MoneyAccountType::Cash,
        'account_id' => null,
    ]);

    $this->actingAs($this->owner)
        ->get('/purchasing/expenses')
        ->assertInertia(function (AssertableInertia $page) use ($unmapped) {
            $accounts = collect($page->toArray()['props']['moneyAccounts']);
            expect($accounts->pluck('id'))->toContain($this->bank->id)
                ->and($accounts->pluck('id'))->not->toContain($unmapped->id);

            $expense = collect($page->toArray()['props']['expenseAccounts']);
            // The parent header 6000 is not postable and must not be offered.
            expect($expense->pluck('label')->implode('|'))->toContain('6051')
                ->and($expense->pluck('label')->implode('|'))->not->toContain('6000 Operating Expenses');

            expect($page->toArray()['props']['canRecord'])->toBeTrue();
        });
});

it('tells the screen when the viewer may not record, so the form is not offered', function () {
    ($this->asRole)('Staff')
        ->get('/purchasing/expenses')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canRecord', false));
});

it('sends the date as a plain calendar day, not a UTC timestamp', function () {
    $this->actingAs($this->owner)->post('/purchasing/expenses', ($this->payload)());

    // ⚠⚠ A `date` cast serialises to JSON in UTC and Dhaka is UTC+6, so midnight
    // local becomes 18:00 the PREVIOUS day - an expense dated the 9th shown as
    // the 8th, with a correct database throughout. Caught in a browser once
    // already on the payment screen.
    $this->actingAs($this->owner)
        ->get('/purchasing/expenses')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('vouchers.data.0.spent_on', '2026-08-09'));
});

/**
 * ⚠⚠ 6 Sep 2026. THE HEADER SEARCH HAD BEEN LINKING HERE WITH `?search=` SINCE
 * DOCUMENT NUMBERS WERE ADDED TO IT, AND THIS SCREEN HAD NO FILTER AT ALL. The
 * parameter was accepted and ignored, so every expense result landed on page
 * one of every expense ever recorded and read as a search that did not work.
 *
 * The list is the destination for expenses (there is no voucher page), so the
 * link is right and the missing filter was the defect.
 */
it('filters the list by voucher number, reference, payee or note', function () {
    $this->actingAs($this->owner)->post('/purchasing/expenses', ($this->payload)());
    $this->actingAs($this->owner)->post('/purchasing/expenses', ($this->payload)([
        'paid_to' => 'Nikolahost',
        'reference' => 'NIKOLA-2026-09',
        'lines' => [['account_id' => $this->fuel->id, 'amount' => '4900.00', 'description' => 'Hosting']],
    ]));

    // The reference the search box sends.
    $this->actingAs($this->owner)
        ->get('/purchasing/expenses?search=NIKOLA-2026-09')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.search', 'NIKOLA-2026-09')
            ->has('vouchers.data', 1)
            ->where('vouchers.data.0.reference', 'NIKOLA-2026-09'));

    // The payee, because that is what a person actually remembers.
    $this->actingAs($this->owner)
        ->get('/purchasing/expenses?search=landlord')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('vouchers.data', 1)
            ->where('vouchers.data.0.reference', 'RCPT-4410'));

    // And no filter still shows everything.
    $this->actingAs($this->owner)
        ->get('/purchasing/expenses')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('vouchers.data', 2));
});
