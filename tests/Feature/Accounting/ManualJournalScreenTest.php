<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Enums\JournalType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 2.3 of Accounting: the manual journal screen, the bank and cash screen,
 * and the opening balance entry.
 *
 * ⚠⚠ Posting a manual journal is the highest-risk permission in the system
 * (C-GL-6): the one action that moves money between any two accounts with no
 * document behind it. Owner only, and in this phase being Owner-only IS the
 * control.
 *
 * ⚠ The screen validates SHAPE and never re-implements an accounting rule.
 * Balance, postable accounts, the period lock and the date are all decided by
 * PostJournal, and the tests below check that its refusals reach the user as a
 * message rather than an error page.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-03 10:00:00');

    $this->business = Business::create([
        'name' => 'Screen Co',
        'slug' => 'screen-co',
        'fiscal_year_start_month' => 7,
    ]);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['accounting.view', 'accounting.chart.manage', 'accounting.journal.post'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $ownerRole = Role::firstOrCreate(['name' => 'Founder', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $ownerRole->givePermissionTo(['accounting.view', 'accounting.chart.manage', 'accounting.journal.post']);

    $readerRole = Role::firstOrCreate(['name' => 'Coordinator', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $readerRole->givePermissionTo('accounting.view');

    $this->founder = User::factory()->create();
    $this->founder->businesses()->attach($this->business->id);
    $this->founder->assignRole($ownerRole);

    $this->coordinator = User::factory()->create();
    $this->coordinator->businesses()->attach($this->business->id);
    $this->coordinator->assignRole($readerRole);

    $this->seed(ChartOfAccountsSeeder::class);

    $this->bank = Account::bySlug('cash_at_bank');
    $this->capital = Account::bySlug('owners_capital');
    $this->opening = Account::bySlug('opening_balance_equity');
    $this->rent = Account::where('code', '6051')->first();

    $this->payload = fn (array $overrides = []) => array_replace([
        'journal_date' => '2026-08-03',
        'memo' => 'Capital introduced by the Founder',
        'journal_type' => 'manual',
        'lines' => [
            ['account_id' => $this->bank->id, 'debit' => '500000', 'credit' => '', 'memo' => ''],
            ['account_id' => $this->capital->id, 'debit' => '', 'credit' => '500000', 'memo' => ''],
        ],
    ], $overrides);
});

// ─── THE MILESTONE ──────────────────────────────────────────────────────────

it('lets the Founder post a capital introduction from the screen', function () {
    moneyHead('cash_at_bank'); // the bank a manual journal posts to has its Bank & cash account
    // ⚠⚠ THE THING THIS WHOLE PHASE IS FOR: Joy puts his own money into the
    // business, through a screen, and the ledger records both halves.
    $this->actingAs($this->founder)
        ->post(route('accounting.journals.store'), ($this->payload)())
        ->assertRedirect();

    $journal = Journal::first();

    expect($journal->journal_number)->toBe('JV-2026-27-0001')
        ->and($journal->journal_type)->toBe(JournalType::Manual)
        ->and($journal->balance()->isZero())->toBeTrue()
        ->and($journal->totalDebits()->format())->toBe('500,000.00')
        ->and($journal->lines)->toHaveCount(2);
});

it('records an opening balance as its own kind of entry', function () {
    moneyHead('cash_at_bank'); // the bank a manual journal posts to has its Bank & cash account
    // ⚠ The discriminator exists so the opening photograph can be told apart
    // from a year of trading. A report that cannot separate the two shows a year
    // that begins with a phantom sale.
    $this->actingAs($this->founder)
        ->post(route('accounting.journals.store'), ($this->payload)([
            'journal_type' => 'opening_balance',
            'journal_date' => '2026-06-30',
            'memo' => 'Opening balances at 1 July 2026',
            'lines' => [
                ['account_id' => $this->bank->id, 'debit' => '250000', 'credit' => '', 'memo' => 'BRAC current account'],
                ['account_id' => $this->opening->id, 'debit' => '', 'credit' => '250000', 'memo' => ''],
            ],
        ]))
        ->assertRedirect();

    $journal = Journal::first();

    expect($journal->journal_type)->toBe(JournalType::OpeningBalance)
        // Dated before the year it opens, so it lands in the previous fiscal
        // year's numbering. That is correct: the photograph is taken the day
        // before trading starts.
        ->and($journal->journal_number)->toBe('JV-2025-26-0001');

    expect(Journal::ofType(JournalType::OpeningBalance)->count())->toBe(1)
        ->and(Journal::ofType(JournalType::Manual)->count())->toBe(0);
});

// ─── Permissions ────────────────────────────────────────────────────────────

it('refuses to let anyone but the Founder post a journal', function () {
    // Reading the ledger and writing to it are different permissions on purpose.
    $this->actingAs($this->coordinator)
        ->post(route('accounting.journals.store'), ($this->payload)())
        ->assertForbidden();

    $this->actingAs($this->coordinator)
        ->get(route('accounting.journals.create'))
        ->assertForbidden();

    expect(Journal::count())->toBe(0);
});

it('lets someone who may read the books see them without being able to write', function () {
    moneyHead('cash_at_bank'); // the bank a manual journal posts to has its Bank & cash account
    $this->actingAs($this->founder)->post(route('accounting.journals.store'), ($this->payload)());

    $this->actingAs($this->coordinator)
        ->get(route('accounting.journals.index'))
        ->assertOk();

    $this->actingAs($this->coordinator)
        ->get(route('accounting.journals.show', Journal::first()->id))
        ->assertOk();
});

// ─── The engine's refusals, reaching the user ───────────────────────────────

it('shows the user why an unbalanced journal was refused', function () {
    // ⚠ The screen does not check the balance itself. PostJournal decides, and
    // this asserts its refusal arrives as a message rather than a 500 page.
    $this->actingAs($this->founder)
        ->post(route('accounting.journals.store'), ($this->payload)([
            'lines' => [
                ['account_id' => $this->bank->id, 'debit' => '500', 'credit' => '', 'memo' => ''],
                ['account_id' => $this->capital->id, 'debit' => '', 'credit' => '499.99', 'memo' => ''],
            ],
        ]))
        ->assertSessionHasErrors('lines');

    expect(Journal::count())->toBe(0);
});

it('shows the user why a closed month was refused', function () {
    AccountingPeriod::closeThrough(Carbon::parse('2026-07-31'), $this->founder);

    $this->actingAs($this->founder)
        ->post(route('accounting.journals.store'), ($this->payload)(['journal_date' => '2026-07-15']))
        ->assertSessionHasErrors('lines');

    expect(Journal::count())->toBe(0);
});

it('shows the user why a heading was refused', function () {
    $group = Account::where('code', '6000')->first();

    $this->actingAs($this->founder)
        ->post(route('accounting.journals.store'), ($this->payload)([
            'lines' => [
                ['account_id' => $group->id, 'debit' => '500', 'credit' => '', 'memo' => ''],
                ['account_id' => $this->capital->id, 'debit' => '', 'credit' => '500', 'memo' => ''],
            ],
        ]))
        ->assertSessionHasErrors('lines');
});

// ─── What the screen itself is responsible for ──────────────────────────────

it('refuses a line carrying both a debit and a credit', function () {
    // ⚠ A signed single amount cannot express "500 debit AND 200 credit", so it
    // has to be refused at the door. Netting it to 300 would store a number
    // nobody typed.
    $this->actingAs($this->founder)
        ->post(route('accounting.journals.store'), ($this->payload)([
            'lines' => [
                ['account_id' => $this->bank->id, 'debit' => '500', 'credit' => '200', 'memo' => ''],
                ['account_id' => $this->capital->id, 'debit' => '', 'credit' => '300', 'memo' => ''],
            ],
        ]))
        ->assertSessionHasErrors('lines.0.debit');
});

it('refuses a line with no amount at all', function () {
    $this->actingAs($this->founder)
        ->post(route('accounting.journals.store'), ($this->payload)([
            'lines' => [
                ['account_id' => $this->bank->id, 'debit' => '', 'credit' => '', 'memo' => ''],
                ['account_id' => $this->capital->id, 'debit' => '', 'credit' => '500', 'memo' => ''],
            ],
        ]))
        ->assertSessionHasErrors('lines.0.debit');
});

it('refuses an amount that is not money', function () {
    $this->actingAs($this->founder)
        ->post(route('accounting.journals.store'), ($this->payload)([
            'lines' => [
                ['account_id' => $this->bank->id, 'debit' => 'five hundred', 'credit' => '', 'memo' => ''],
                ['account_id' => $this->capital->id, 'debit' => '', 'credit' => '500', 'memo' => ''],
            ],
        ]))
        ->assertSessionHasErrors('lines.0.debit');
});

it('refuses a journal with only one line', function () {
    $this->actingAs($this->founder)
        ->post(route('accounting.journals.store'), ($this->payload)([
            'lines' => [
                ['account_id' => $this->bank->id, 'debit' => '500', 'credit' => '', 'memo' => ''],
            ],
        ]))
        ->assertSessionHasErrors('lines');
});

it('takes an amount a human typed with commas', function () {
    moneyHead('cash_at_bank'); // the bank a manual journal posts to has its Bank & cash account
    $this->actingAs($this->founder)
        ->post(route('accounting.journals.store'), ($this->payload)([
            'lines' => [
                ['account_id' => $this->bank->id, 'debit' => '1,25,000.50', 'credit' => '', 'memo' => ''],
                ['account_id' => $this->capital->id, 'debit' => '', 'credit' => '125000.50', 'memo' => ''],
            ],
        ]))
        ->assertRedirect();

    expect(Journal::first()->lines[0]->amount_minor)->toBe(12500050);
});

// ─── Reversing from the screen ──────────────────────────────────────────────

it('reverses a journal from its own page', function () {
    moneyHead('cash_at_bank'); // the bank a manual journal posts to has its Bank & cash account
    $this->actingAs($this->founder)->post(route('accounting.journals.store'), ($this->payload)());
    $original = Journal::first();

    $this->actingAs($this->founder)
        ->post(route('accounting.journals.reverse', $original->id))
        ->assertRedirect();

    expect(Journal::count())->toBe(2)
        ->and($original->fresh()->hasBeenReversed())->toBeTrue();
});

it('refuses to let a reader reverse anything', function () {
    moneyHead('cash_at_bank'); // the bank a manual journal posts to has its Bank & cash account
    $this->actingAs($this->founder)->post(route('accounting.journals.store'), ($this->payload)());

    $this->actingAs($this->coordinator)
        ->post(route('accounting.journals.reverse', Journal::first()->id))
        ->assertForbidden();
});

it('shows the user why a second reversal was refused', function () {
    moneyHead('cash_at_bank'); // the bank a manual journal posts to has its Bank & cash account
    $this->actingAs($this->founder)->post(route('accounting.journals.store'), ($this->payload)());
    $original = Journal::first();

    $this->actingAs($this->founder)->post(route('accounting.journals.reverse', $original->id));

    $this->actingAs($this->founder)
        ->post(route('accounting.journals.reverse', $original->id))
        ->assertSessionHasErrors('lines');

    expect(Journal::count())->toBe(2);
});

it('keeps one business journals out of another', function () {
    moneyHead('cash_at_bank'); // the bank a manual journal posts to has its Bank & cash account
    $this->actingAs($this->founder)->post(route('accounting.journals.store'), ($this->payload)());
    $theirs = Journal::first();

    $other = Business::create(['name' => 'Elsewhere', 'slug' => 'elsewhere', 'fiscal_year_start_month' => 7]);
    $outsider = User::factory()->create();
    $outsider->businesses()->attach($other->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($other->id);
    $role = Role::firstOrCreate(['name' => 'Founder', 'business_id' => $other->id, 'guard_name' => 'web']);
    $role->givePermissionTo(['accounting.view', 'accounting.journal.post']);
    $outsider->assignRole($role);

    // ⚠ Route-model binding resolves through the business scope, so another
    // tenant's journal is not "forbidden", it does not exist.
    $this->actingAs($outsider)
        ->get(route('accounting.journals.show', $theirs->id))
        ->assertNotFound();
});

// ─── Bank and cash accounts ─────────────────────────────────────────────────

it('lets the Founder add the bank account an opening balance needs', function () {
    // ⚠⚠ money_accounts has existed since Phase 1.1 with NO SCREEN, so
    // production carried zero rows and there was no way to add one. An opening
    // bank balance cannot be posted until the account exists.
    $this->actingAs($this->founder)
        ->post(route('accounting.money-accounts.store'), [
            'name' => 'BRAC Bank - Current',
            'type' => MoneyAccountType::Bank->value,
            'account_number' => '1501202536477001',
            'bank_name' => 'BRAC Bank PLC',
            'branch' => 'Shaymoli',
            'account_id' => $this->bank->id,
            'is_active' => true,
        ])
        ->assertRedirect();

    $account = MoneyAccount::first();

    expect($account->name)->toBe('BRAC Bank - Current')
        ->and($account->account_id)->toBe($this->bank->id)
        ->and($account->is_active)->toBeTrue();
});

it('gives a new cash box a ledger head of its own the moment it is saved', function () {
    $this->actingAs($this->founder)
        ->post(route('accounting.money-accounts.store'), [
            'name' => 'Office cash box',
            'type' => MoneyAccountType::Cash->value,
            'is_active' => true,
        ])
        ->assertRedirect();

    // ⚠ 21 Sep 2026: no longer left undecided. Every cash box, bank account and
    // wallet reports under a head of its own, made here, so its balance is its
    // own line on every report. The first one takes over the seeded placeholder.
    $head = Account::find(MoneyAccount::where('name', 'Office cash box')->sole()->account_id);

    expect($head->code)->toBe('11101')
        ->and($head->name)->toBe("Office cash box - {$this->business->name}")
        ->and($head->parent->system_slug)->toBe('cash_accounts');
});

it('deactivates a money account rather than offering to delete it', function () {
    $this->actingAs($this->founder)->post(route('accounting.money-accounts.store'), [
        'name' => 'Old wallet', 'type' => MoneyAccountType::Mfs->value, 'is_active' => true,
    ]);

    $account = MoneyAccount::first();

    $this->actingAs($this->founder)
        ->put(route('accounting.money-accounts.update', $account->id), [
            'name' => 'Old wallet', 'type' => MoneyAccountType::Mfs->value, 'is_active' => false,
        ])
        ->assertRedirect();

    expect($account->fresh()->is_active)->toBeFalse()
        ->and(MoneyAccount::count())->toBe(1);

    // ⚠ There is deliberately no delete route: a payment made in August must
    // still be nameable in December.
    expect(fn () => route('accounting.money-accounts.destroy', $account->id))
        ->toThrow(Exception::class);
});

it('files a courier account under Receivable from Couriers and nowhere else', function () {
    // ⚠ Cash a courier is holding filed under Cash at Bank would overstate the
    // bank on every balance sheet until the payout arrived.
    $this->actingAs($this->founder)
        ->post(route('accounting.money-accounts.store'), [
            'name' => 'Pathao', 'type' => MoneyAccountType::Courier->value,
            'account_id' => $this->bank->id, 'is_active' => true,
        ])
        ->assertSessionHasErrors('account_id');

    $this->actingAs($this->founder)
        ->post(route('accounting.money-accounts.store'), [
            'name' => 'Pathao', 'type' => MoneyAccountType::Courier->value,
            'account_id' => Account::bySlug('courier_receivable')->id, 'is_active' => true,
        ])
        ->assertSessionHasNoErrors();

    expect(MoneyAccount::query()->sole()->account_id)->toBe(Account::bySlug('courier_receivable')->id);
});

it('files a payment gateway account under Receivable from Payment Gateways and keeps it out of every picker', function () {
    // SSLCommerz slice P2a: money a gateway took and has not yet settled is owed
    // by the gateway, never cash at bank.
    $this->actingAs($this->founder)
        ->post(route('accounting.money-accounts.store'), [
            'name' => 'SSLCommerz', 'type' => MoneyAccountType::Gateway->value,
            'account_id' => $this->bank->id, 'is_active' => true,
        ])
        ->assertSessionHasErrors(['account_id' => 'A payment gateway account reports under 1146 Receivable from Payment Gateways.']);

    $this->actingAs($this->founder)
        ->post(route('accounting.money-accounts.store'), [
            'name' => 'SSLCommerz', 'type' => MoneyAccountType::Gateway->value,
            'account_id' => Account::bySlug('gateway_receivable')->id, 'is_active' => true,
        ])
        ->assertSessionHasNoErrors();

    $gateway = MoneyAccount::query()->sole();

    expect($gateway->account_id)->toBe(Account::bySlug('gateway_receivable')->id)
        ->and($gateway->isGateway())->toBeTrue()
        ->and(MoneyAccount::query()->handled()->count())->toBe(0);
});

it('refuses to let a reader change where the money is', function () {
    $this->actingAs($this->coordinator)
        ->post(route('accounting.money-accounts.store'), [
            'name' => 'Sneaky', 'type' => MoneyAccountType::Cash->value, 'is_active' => true,
        ])
        ->assertForbidden();
});

it('offers only asset accounts for a bank account to report under', function () {
    $response = $this->actingAs($this->founder)->get(route('accounting.money-accounts.index'));

    $options = collect($response->viewData('page')['props']['ledgerAccounts']);

    // A cash box reporting under Sales Revenue is a typo nobody would ever catch
    // by reading a balance sheet.
    expect($options)->not->toBeEmpty()
        ->and($options->pluck('label')->filter(fn ($label) => str_starts_with($label, '4')))->toBeEmpty()
        ->and($options->pluck('label')->contains(fn ($label) => str_starts_with($label, '1120')))->toBeTrue();
});

// ── Finding 2: an unmapped money account must be impossible to miss ─────────

/**
 * ⚠⚠ FOUND BY THE END-TO-END CYCLE, 9 August. A money account with a null
 * `account_id` is accepted at setup and fails only when somebody tries to PAY A
 * SUPPLIER - three steps and possibly months later - with MappingNotFound.
 *
 * ⚠ The obvious fix, making it required, was NEARLY APPLIED and would have
 * broken a decision this codebase made on purpose and tested above: a cash box
 * may exist before anybody has decided what it reports under, because refusing
 * to record a real account would lose the fact that it exists. So the gap is
 * closed by SURFACING, not refusing.
 */
it('tells the screen which money accounts have no ledger account', function () {
    // ⚠ The screen can no longer make one; only a row older than 21 Sep 2026 can
    // still be unmapped, so it is planted directly.
    MoneyAccount::create(['name' => 'Unmapped cash box', 'type' => MoneyAccountType::Cash->value, 'is_active' => true]);
    $this->actingAs($this->founder)->post(route('accounting.money-accounts.store'), [
        'name' => 'Mapped bank', 'type' => MoneyAccountType::Bank->value, 'is_active' => true,
    ]);

    $response = $this->actingAs($this->founder)->get(route('accounting.money-accounts.index'))->assertOk();

    // ⚠ Deliberately NOT asserting on array positions. The index orders by type
    // then name, and a test that encodes that ordering passes for a reason that
    // has nothing to do with what it claims to check - this project has already
    // had a whole suite pass because fixture order happened to match.
    $accounts = collect($response->viewData('page')['props']['accounts']);

    expect($response->viewData('page')['props']['unmappedCount'])->toBe(1)
        ->and($accounts->firstWhere('name', 'Unmapped cash box')['is_unmapped'])->toBeTrue()
        ->and($accounts->firstWhere('name', 'Mapped bank')['is_unmapped'])->toBeFalse();
});

it('never lets a request choose the ledger of a cash, bank or wallet account', function () {
    // ⚠ Whatever the request says is ignored: Stock on Hand here would have
    // filed a wallet's money as inventory.
    $this->actingAs($this->founder)->post(route('accounting.money-accounts.store'), [
        'name' => 'bKash merchant', 'type' => MoneyAccountType::Mfs->value, 'is_active' => true,
        'account_id' => Account::bySlug('stock_on_hand')->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $head = Account::find(MoneyAccount::where('name', 'bKash merchant')->sole()->account_id);

    expect($head->parent->system_slug)->toBe('mfs_accounts')
        ->and($head->system_slug)->toBe('mobile_financial_services');
});

it('reports zero unmapped accounts once every one is mapped', function () {
    $this->actingAs($this->founder)->post(route('accounting.money-accounts.store'), [
        'name' => 'Mapped bank', 'type' => MoneyAccountType::Bank->value,
        'account_id' => $this->bank->id, 'is_active' => true,
    ]);

    $this->actingAs($this->founder)->get(route('accounting.money-accounts.index'))
        ->assertInertia(fn ($page) => $page->where('unmappedCount', 0));
});
