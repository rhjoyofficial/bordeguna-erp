<?php

use App\Models\User;
use App\Modules\Accounting\Actions\RecordMoneyTransfer;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\MoneyTransfer;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Money moved between the business's own accounts.
 *
 * ⚠⚠ The property that matters most is the FEE DIRECTION: the source loses
 * amount + fee, and the destination receives amount. Getting that backwards
 * balances the journal perfectly and is invisible until somebody counts the
 * till, which is exactly the class of error this document exists to prevent.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-27 10:00:00');

    $this->business = Business::create([
        'name' => 'Transfer Co',
        'slug' => 'transfer-co',
        'fiscal_year_start_month' => 7,
    ]);
    TenantScope::enter($this->business->id);

    foreach (['accounting.view', 'accounting.journal.post'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $poster = Role::firstOrCreate(['name' => 'Poster', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $poster->givePermissionTo(['accounting.view', 'accounting.journal.post']);

    $reader = Role::firstOrCreate(['name' => 'Reader', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $reader->givePermissionTo('accounting.view');

    $this->seed(ChartOfAccountsSeeder::class);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);
    $this->user->assignRole($poster);

    $this->readOnly = User::factory()->create();
    $this->readOnly->businesses()->attach($this->business->id);
    $this->readOnly->assignRole($reader);

    $this->bankAccount = Account::bySlug('cash_at_bank');
    $this->tillAccount = Account::bySlug('cash_in_hand');
    $this->charges = Account::where('code', '6290')->firstOrFail();

    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'BRAC Bank',
        'type' => 'bank', 'account_id' => $this->bankAccount->id,
    ]);
    $this->till = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Cash in Hand',
        'type' => 'cash', 'account_id' => $this->tillAccount->id,
    ]);

    $this->transfer = fn (array $overrides = []) => app(RecordMoneyTransfer::class)->handle(
        from: $overrides['from'] ?? $this->bank,
        to: $overrides['to'] ?? $this->till,
        amount: $overrides['amount'] ?? '1000',
        transferredOn: $overrides['on'] ?? '2026-08-27',
        createdBy: $this->user,
        fee: $overrides['fee'] ?? '0',
        feeAccount: $overrides['feeAccount'] ?? null,
        reference: $overrides['reference'] ?? null,
    );
});

afterEach(fn () => Carbon::setTestNow());

// ─── The ledger ─────────────────────────────────────────────────────────────

it('moves money without earning or spending anything', function () {
    $transfer = ($this->transfer)();

    $journal = Journal::latest('id')->firstOrFail();
    $lines = $journal->lines()->get();

    expect($lines)->toHaveCount(2);
    // ⚠ `amount_minor` is signed whole paisa: positive debits, negative credits.
    expect((int) $journal->lines()->sum('amount_minor'))->toBe(0);

    expect((int) $lines->firstWhere('account_id', $this->tillAccount->id)->amount_minor)->toBe(100_000)
        ->and((int) $lines->firstWhere('account_id', $this->bankAccount->id)->amount_minor)->toBe(-100_000);

    expect($transfer->fee)->toEqual('0.00');
});

/**
 * ⚠⚠ THE ONE THAT MATTERS. A bKash cash-out of 1,000 leaves 1,018.50 of the
 * balance: the agent hands over 1,000 and the charge comes out of the wallet.
 */
it('takes the fee from the source, not from what arrives', function () {
    $transfer = ($this->transfer)(['amount' => '1000', 'fee' => '18.50', 'feeAccount' => $this->charges]);

    $lines = Journal::latest('id')->firstOrFail()->lines()->get();

    // 1,000.00 arrives, 18.50 is the charge, 1,018.50 leaves the bank.
    expect((int) $lines->firstWhere('account_id', $this->tillAccount->id)->amount_minor)->toBe(100_000)
        ->and((int) $lines->firstWhere('account_id', $this->charges->id)->amount_minor)->toBe(1_850)
        ->and((int) $lines->firstWhere('account_id', $this->bankAccount->id)->amount_minor)->toBe(-101_850);

    expect($transfer->totalWithdrawn())->toBe('1018.50');
});

// ─── Refusals ───────────────────────────────────────────────────────────────

it('refuses a transfer to the account it came from', function () {
    expect(fn () => ($this->transfer)(['to' => $this->bank]))
        ->toThrow(ValidationException::class, 'Money cannot be transferred to the account it came from.');

    expect(MoneyTransfer::count())->toBe(0);
    expect(Journal::count())->toBe(0);
});

it('refuses a fee with no account to charge it to', function () {
    expect(fn () => ($this->transfer)(['fee' => '18.50']))
        ->toThrow(ValidationException::class, 'A transfer fee needs an expense account to charge it to.');

    expect(Journal::count())->toBe(0);
});

/**
 * ⚠ Without this the document is a free hand over the whole ledger - somebody
 * could charge a cash-out fee to Sales Revenue and the books would still
 * balance perfectly.
 */
it('refuses a fee charged to anything that is not an expense account', function () {
    expect(fn () => ($this->transfer)(['fee' => '18.50', 'feeAccount' => Account::bySlug('owners_capital')]))
        ->toThrow(ValidationException::class, 'is not an expense account');

    expect(Journal::count())->toBe(0);
});

it('refuses an amount of nothing', function () {
    expect(fn () => ($this->transfer)(['amount' => '0']))
        ->toThrow(ValidationException::class, 'A transfer needs an amount.');
});

it('refuses a transfer dated in the future', function () {
    expect(fn () => ($this->transfer)(['on' => '2026-09-30']))
        ->toThrow(ValidationException::class, 'A transfer cannot be dated in the future.');
});

it('refuses an account with no place in the chart', function () {
    $unmapped = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Unmapped Wallet', 'type' => 'mfs', 'account_id' => null,
    ]);

    expect(fn () => ($this->transfer)(['to' => $unmapped]))->toThrow(Exception::class);
    expect(Journal::count())->toBe(0);
});

// ─── Numbering ──────────────────────────────────────────────────────────────

it('numbers transfers in sequence within the fiscal year', function () {
    expect(($this->transfer)()->transfer_number)->toBe('TRF-2026-27-0001')
        ->and(($this->transfer)()->transfer_number)->toBe('TRF-2026-27-0002');
});

// ─── Reachability and permission ────────────────────────────────────────────

/**
 * ⚠⚠ The guard this project keeps needing. Six features have shipped with a
 * route, an action and tests, and no way for a person to reach them. The screen
 * props are what the form depends on, so they are what this holds.
 */
it('renders the transfer screen with the accounts it needs', function () {
    $this->actingAs($this->user)->get('/accounting/money-transfers')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('accounting/money-transfers/index')
            ->has('accounts', 2)
            ->has('fee_accounts')
            ->has('transfers.data')
        );
});

/**
 * ⚠ Only accounts the action will accept. Offering an unmapped one would be a
 * form that fails on submit for a reason the person clicking cannot see.
 */
it('offers only accounts that have a place in the chart', function () {
    MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Unmapped Wallet', 'type' => 'mfs', 'account_id' => null,
    ]);

    $this->actingAs($this->user)->get('/accounting/money-transfers')
        ->assertInertia(fn ($page) => $page->has('accounts', 2));
});

it('keeps someone who may only read the ledger out of moving money', function () {
    $this->actingAs($this->readOnly)->get('/accounting/money-transfers')->assertForbidden();

    $this->actingAs($this->readOnly)->post('/accounting/money-transfers', [
        'from_money_account_id' => $this->bank->id,
        'to_money_account_id' => $this->till->id,
        'amount' => '1000',
        'transferred_on' => '2026-08-27',
    ])->assertForbidden();

    expect(MoneyTransfer::count())->toBe(0);
});

it('records a transfer through the screen', function () {
    $this->actingAs($this->user)->post('/accounting/money-transfers', [
        'from_money_account_id' => $this->bank->id,
        'to_money_account_id' => $this->till->id,
        'amount' => '5000',
        'transferred_on' => '2026-08-27',
        'fee' => '0',
        'reference' => 'Cheque 44821',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $transfer = MoneyTransfer::firstOrFail();

    expect($transfer->amount)->toEqual('5000.00')
        ->and($transfer->reference)->toBe('Cheque 44821')
        ->and($transfer->from->name)->toBe('BRAC Bank')
        ->and($transfer->to->name)->toBe('Cash in Hand');
});
