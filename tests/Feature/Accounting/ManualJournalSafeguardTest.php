<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Enums\JournalType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\SubsystemAccounts;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Hrm\database\seeders\PayrollAccountMappingSeeder;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The manual journal safeguard.
 *
 * ⚠⚠ THE PRINCIPLE: every financial movement originates from a business event,
 * and users never create accounting entries for Payroll, Inventory, Purchasing
 * or Fixed Assets by hand. Until this slice that was a rule everybody agreed
 * with and nothing checked - and the way such a rule dies is one well-meaning
 * correction to a wages figure, after which that account can never be
 * conclusively reconciled again.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-03 10:00:00');

    $this->business = Business::create(['name' => 'Guard Co', 'slug' => 'guard-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['accounting.view', 'accounting.journal.post'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $role = Role::firstOrCreate(['name' => 'Founder', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $role->givePermissionTo(['accounting.view', 'accounting.journal.post']);

    $this->founder = User::factory()->create();
    $this->founder->businesses()->attach($this->business->id);
    $this->founder->assignRole($role);

    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(PayrollAccountMappingSeeder::class);
    $this->seed(InventoryAccountMappingSeeder::class);
    TenantScope::enter($this->business->id);

    $this->stock = Account::bySlug('stock_on_hand');
    $this->wages = Account::bySlug('salaries_expense');
    $this->capital = Account::bySlug('owners_capital');
    $this->bank = Account::bySlug('cash_at_bank');
    $this->rounding = Account::bySlug('rounding_difference');

    $this->postByHand = fn (Account $debit, Account $credit, string $type = 'manual') => $this
        ->actingAs($this->founder)
        ->post(route('accounting.journals.store'), [
            'journal_date' => '2026-08-03',
            'memo' => 'By hand',
            'journal_type' => $type,
            'lines' => [
                ['account_id' => $debit->id, 'debit' => '5000', 'credit' => '', 'memo' => ''],
                ['account_id' => $credit->id, 'debit' => '', 'credit' => '5000', 'memo' => ''],
            ],
        ]);
});

it('refuses a manual journal that touches an account a module maintains', function () {
    ($this->postByHand)($this->stock, $this->capital)
        ->assertSessionHasErrors('lines.0.account_id');

    expect(Journal::count())->toBe(0);
});

it('refuses it on the credit side too', function () {
    ($this->postByHand)($this->capital, $this->wages)
        ->assertSessionHasErrors('lines.1.account_id');

    expect(Journal::count())->toBe(0);
});

it('lets an opening balance set exactly those accounts', function () {
    // ⚠⚠ THE EXEMPTION THAT MUST EXIST. The opening position of a business IS a
    // hand-entered figure for Stock on Hand and Accounts Payable - there is no
    // subsystem history to derive it from, because the history predates the
    // system entirely.
    ($this->postByHand)($this->stock, Account::bySlug('opening_balance_equity'), 'opening_balance')
        ->assertRedirect();

    expect(Journal::count())->toBe(1)
        ->and(Journal::first()->journal_type)->toBe(JournalType::OpeningBalance);
});

it('still allows the accounts nobody owns', function () {
    // Capital, drawings, bank and the ledger's own rounding account stay
    // available: they are what a manual journal is FOR.
    ($this->postByHand)($this->bank, $this->capital)->assertRedirect();
    ($this->postByHand)($this->rounding, $this->capital)->assertRedirect();

    expect(Journal::count())->toBe(2);
});

it('does not stop a subsystem posting to the same accounts', function () {
    // ⚠⚠ ENFORCED ON THE MANUAL ENTRY PATH, NOT IN THE ENGINE. Payroll and
    // Inventory post to these accounts constantly and legitimately; the ledger
    // must stay blind to what kind of journal it is handling, and a structural
    // test keeps it that way.
    $journal = app(PostJournal::class)->handle(new JournalDraft(
        date: Carbon::parse('2026-08-03'),
        lines: [
            JournalLineDraft::debit($this->stock->id, Money::ofTaka('5000')),
            JournalLineDraft::credit($this->wages->id, Money::ofTaka('5000')),
        ],
    ), $this->founder);

    expect($journal->exists)->toBeTrue();
});

it('refuses to reverse another module journal from the ledger screen', function () {
    // A subsystem journal carries a source; a manual one does not. Reversing a
    // payroll accrual here would leave the run approved and its cost gone from
    // the books, with nothing in either system able to explain the difference.
    $subsystemJournal = app(PostJournal::class)->handle(new JournalDraft(
        date: Carbon::parse('2026-08-03'),
        lines: [
            JournalLineDraft::debit($this->stock->id, Money::ofTaka('5000')),
            JournalLineDraft::credit($this->wages->id, Money::ofTaka('5000')),
        ],
        source: $this->business,
    ), $this->founder);

    $this->actingAs($this->founder)
        ->post(route('accounting.journals.reverse', $subsystemJournal->id))
        ->assertSessionHasErrors('lines');

    expect(Journal::count())->toBe(1);
});

it('still reverses a hand-written journal', function () {
    ($this->postByHand)($this->bank, $this->capital);

    $this->actingAs($this->founder)
        ->post(route('accounting.journals.reverse', Journal::first()->id))
        ->assertRedirect();

    expect(Journal::count())->toBe(2);
});

it('reads ownership from the mappings rather than a flag', function () {
    // ⚠ Derived, so re-mapping a key moves the protection with it. A stored
    // `is_system_controlled` column would drift the moment somebody re-filed an
    // account.
    $owned = SubsystemAccounts::on(Carbon::today());

    expect($owned)->toHaveKey($this->stock->id)
        ->and($owned)->toHaveKey($this->wages->id)
        ->and($owned)->not->toHaveKey($this->capital->id)
        // The ledger's own rounding account is not a subsystem's.
        ->and($owned)->not->toHaveKey($this->rounding->id)
        // Bank accounts are reached through money_accounts, not a mapping, so
        // an opening bank balance stays enterable by hand.
        ->and($owned)->not->toHaveKey($this->bank->id);
});

it('protects an account for the date the journal is dated, not today', function () {
    // ⚠ Mappings are versioned by date and so is the protection: an account a
    // module owned in July is protected for a July-dated entry even after it has
    // been re-mapped.
    expect(SubsystemAccounts::on(Carbon::parse('2026-07-01')))->toHaveKey($this->stock->id)
        ->and(SubsystemAccounts::on(Carbon::parse('1999-01-01')))->toBeEmpty();
});

it('leaves a general expense head payroll only allocates to open, and keeps the wage head closed', function () {
    // TA/DA and reimbursements are mapped to Conveyance so travel money lands
    // where travel is reported. That must not make Conveyance unpayable by
    // voucher: every rickshaw fare the business pays belongs there too.
    $owned = SubsystemAccounts::on(Carbon::now());
    $id = fn (string $code) => Account::where('code', $code)->sole()->id;

    expect($owned)->not->toHaveKey($id('6011'));
    expect($owned)->toHaveKey($id('6131'));
    expect($owned)->toHaveKey($id('2130'));
    expect($owned)->toHaveKey($id('1170'));
});

it('sends overtime and bonus to their own accounts, a fine back to wages, and keeps the wage group payroll\'s', function () {
    // Accountant meeting 19 Sep 2026, D1 and D2.
    $on = Carbon::now();
    $code = fn (string $key, ?string $fallback = null) => AccountMapping::resolve($key, $on, $fallback)->code;

    expect($code('payroll.earning.overtime', 'payroll.earning.default'))->toBe('6133')
        ->and($code('payroll.earning.bonus', 'payroll.earning.default'))->toBe('6132')
        ->and($code('payroll.deduction.penalty', 'payroll.deduction.default'))->toBe('6131');

    // Overtime and Bonus are in the wage head's own group, so a voucher or a
    // manual journal still may not touch them; Conveyance remains shared.
    $owned = SubsystemAccounts::on($on);
    $id = fn (string $c) => Account::where('code', $c)->sole()->id;

    expect($owned)->toHaveKey($id('6132'))
        ->and($owned)->toHaveKey($id('6133'))
        ->and($owned)->not->toHaveKey($id('6011'));
});
