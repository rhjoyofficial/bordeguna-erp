<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\CashAndBankBook;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The cash and bank book: where the money is, per LEDGER account (C-CBB-1),
 * with figures read from the trial balance (C-CBB-2).
 *
 * ⚠ The properties worth testing are the grain (two bank accounts sharing one
 * ledger account collapse into ONE row), the C-CBB-3 visibility rule (a
 * deactivated box with a residual balance stays visible), and the C-CBB-4
 * banner (an unmapped account is named, not silently absent). The arithmetic is
 * the trial balance's, already tested where it lives.
 *
 * See CASH-AND-BANK-BOOK-ARCHITECTURE.md.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-16 10:00:00');

    $this->business = Business::create([
        'name' => 'Cashbook Co',
        'slug' => 'cashbook-co',
        'fiscal_year_start_month' => 7,
    ]);
    TenantScope::enter($this->business->id);

    foreach (['accounting.view', 'accounting.journal.post'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $reader = Role::firstOrCreate(['name' => 'Reader', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $reader->givePermissionTo('accounting.view');

    $outsider = Role::firstOrCreate(['name' => 'Outsider', 'business_id' => $this->business->id, 'guard_name' => 'web']);

    $this->seed(ChartOfAccountsSeeder::class);

    $this->reader = User::factory()->create();
    $this->reader->businesses()->attach($this->business->id);
    $this->reader->assignRole($reader);

    $this->outsider = User::factory()->create();
    $this->outsider->businesses()->attach($this->business->id);
    $this->outsider->assignRole($outsider);

    $this->bank = Account::bySlug('cash_at_bank');
    $this->till = Account::bySlug('cash_in_hand');
    $this->capital = Account::bySlug('owners_capital');

    $this->post = function (int $accountId, string $amount, string $date, bool $in = true) {
        app(PostJournal::class)->handle(new JournalDraft(
            date: Carbon::parse($date),
            lines: $in
                ? [
                    JournalLineDraft::debit($accountId, Money::ofTaka($amount)),
                    JournalLineDraft::credit($this->capital->id, Money::ofTaka($amount)),
                ]
                : [
                    JournalLineDraft::credit($accountId, Money::ofTaka($amount)),
                    JournalLineDraft::debit($this->capital->id, Money::ofTaka($amount)),
                ],
        ), $this->reader);
    };

    $this->book = fn (string $from = '2026-07-01', string $to = '2026-08-31') => CashAndBankBook::between(
        Carbon::parse($from)->startOfDay(),
        Carbon::parse($to)->endOfDay(),
    );
});

afterEach(fn () => Carbon::setTestNow());

// ─── C-CBB-1. THE GRAIN ─────────────────────────────────────────────────────

it('collapses two bank accounts sharing one ledger account into one row naming both', function () {
    MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'BRAC Current', 'type' => 'bank', 'account_id' => $this->bank->id]);
    MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'City Savings', 'type' => 'bank', 'account_id' => $this->bank->id]);

    ($this->post)($this->bank->id, '1000', '2026-07-05');

    $book = ($this->book)();

    expect($book->rows)->toHaveCount(1)
        ->and($book->rows->first()->account->id)->toBe($this->bank->id)
        ->and($book->rows->first()->moneyAccounts->pluck('name')->all())->toBe(['BRAC Current', 'City Savings'])
        ->and($book->rows->first()->closing()->minor)->toBe(100000);
});

it('keeps separate ledger accounts as separate rows in chart order', function () {
    MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'Main Till', 'type' => 'cash', 'account_id' => $this->till->id]);
    MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'BRAC Current', 'type' => 'bank', 'account_id' => $this->bank->id]);

    ($this->post)($this->till->id, '500', '2026-07-05');
    ($this->post)($this->bank->id, '2000', '2026-07-06');

    $book = ($this->book)();

    // 1110 Cash in Hand before 1120 Cash at Bank: the chart's order, not creation order.
    expect($book->rows->map(fn ($row) => $row->account->code)->all())->toBe(['1110', '1120'])
        ->and($book->closing()->minor)->toBe(250000);
});

// ─── C-CBB-2. THE FIGURES ARE THE TRIAL BALANCE'S ───────────────────────────

it('splits opening from in-window movement exactly as the trial balance does', function () {
    MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'BRAC Current', 'type' => 'bank', 'account_id' => $this->bank->id]);

    ($this->post)($this->bank->id, '9000', '2026-06-20');          // before the window
    ($this->post)($this->bank->id, '1000', '2026-07-05');          // in
    ($this->post)($this->bank->id, '400', '2026-07-06', in: false); // out

    $row = ($this->book)()->rows->first();

    expect($row->opening->minor)->toBe(900000)
        ->and($row->debit->minor)->toBe(100000)
        ->and($row->credit->minor)->toBe(-40000)
        ->and($row->closing()->minor)->toBe(960000);
});

// ─── C-CBB-3. VISIBILITY ────────────────────────────────────────────────────

it('keeps a deactivated cash box visible while its ledger account still holds money', function () {
    MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'Old Till', 'type' => 'cash', 'account_id' => $this->till->id, 'is_active' => false]);

    ($this->post)($this->till->id, '700', '2026-07-05');

    expect(($this->book)()->rows)->toHaveCount(1);
});

it('hides a deactivated box whose ledger account never saw a posting', function () {
    MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'Abandoned Till', 'type' => 'cash', 'account_id' => $this->till->id, 'is_active' => false]);

    expect(($this->book)()->rows)->toHaveCount(0);
});

it('shows an active account even with nothing posted, because a zero balance is an answer', function () {
    MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'New Till', 'type' => 'cash', 'account_id' => $this->till->id]);

    $row = ($this->book)()->rows->first();

    expect($row)->not->toBeNull()
        ->and($row->closing()->isZero())->toBeTrue();
});

// ─── C-CBB-4. THE UNMAPPED BANNER ───────────────────────────────────────────

it('names an unmapped money account instead of silently omitting it', function () {
    MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'Mystery bKash', 'type' => 'mfs']);

    $book = ($this->book)();

    expect($book->rows)->toHaveCount(0)
        ->and($book->unmapped->pluck('name')->all())->toBe(['Mystery bKash']);
});

// ─── THE SCREEN ─────────────────────────────────────────────────────────────

it('shows the book with formatted figures and the unmapped banner', function () {
    MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'BRAC Current', 'type' => 'bank', 'account_id' => $this->bank->id]);
    MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'Mystery bKash', 'type' => 'mfs']);

    ($this->post)($this->bank->id, '9000', '2026-06-20');
    ($this->post)($this->bank->id, '1000', '2026-07-05');

    $this->actingAs($this->reader)
        ->get('/accounting/cash-and-bank?from=2026-07-01&to=2026-08-31')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('accounting/cash-and-bank/index')
            ->where('rows.0.code', '1120')
            ->where('rows.0.opening', '9,000.00')
            ->where('rows.0.in', '1,000.00')
            ->where('rows.0.out', null)
            ->where('rows.0.closing', '10,000.00')
            ->where('rows.0.closing_side', 'debit')
            ->where('totals.closing', '10,000.00')
            ->where('unmapped.0', 'Mystery bKash')
            ->where('noMoneyAccounts', false)
        );
});

it('keeps the book away from a user who cannot view accounting', function () {
    $this->actingAs($this->outsider)->get('/accounting/cash-and-bank')->assertForbidden();
});

it('tells an empty business to set up its money accounts first', function () {
    $this->actingAs($this->reader)
        ->get('/accounting/cash-and-bank')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('rows', [])
            ->where('noMoneyAccounts', true)
        );
});
