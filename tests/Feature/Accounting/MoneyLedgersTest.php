<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Exceptions\JournalException;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\BalanceSheet;
use App\Modules\Accounting\Support\CashFlowForecast;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\MoneyLedgers;
use App\Modules\Accounting\Support\VoucherAccounts;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * One ledger head per cash box, bank account and mobile wallet (21 Sep 2026).
 * See Support\MoneyLedgers and the migration 2026_09_21_120000.
 *
 * ⚠ The properties worth pinning: the upgrade of a production-shaped chart
 * (recoded, never converted; shared history never guessed), the invariant at
 * the posting engine (a money head is posted only through its own account, in
 * its own business), the screens that can no longer make or bend such a head,
 * and the balance sheet keeping the bank INSIDE Current Assets.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-21 10:00:00');

    $this->business = Business::create(['name' => 'Bor de Guna', 'slug' => 'bdg', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);

    $permissions = ['accounting.view', 'accounting.journal.post', 'accounting.chart.manage'];
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $role = Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $role->givePermissionTo($permissions);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole($role);

    $this->seed(ChartOfAccountsSeeder::class);

    $this->capital = Account::bySlug('owners_capital');

    $this->addAccount = function (string $name, MoneyAccountType $type): MoneyAccount {
        $this->actingAs($this->owner)
            ->post(route('accounting.money-accounts.store'), ['name' => $name, 'type' => $type->value, 'is_active' => true])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        return MoneyAccount::where('name', $name)->sole();
    };

    $this->post = fn (array $lines) => app(PostJournal::class)->handle(
        new JournalDraft(date: Carbon::parse('2026-09-21'), lines: $lines),
        $this->owner,
    );
});

afterEach(fn () => Carbon::setTestNow());

// ─── THE UPGRADE OF A PRODUCTION-SHAPED CHART ───────────────────────────────

/**
 * Puts the three money heads back to how production had them before 21 Sep
 * 2026 (postable 1110 / 1120 / 1130 with no groups) and ties Bor de Guna's five
 * real accounts to them the way production has them.
 */
function productionShapedMoneyChart(Business $business): array
{
    foreach (['cash_accounts', 'bank_accounts', 'mfs_accounts'] as $slug) {
        $group = DB::table('accounts')->where('system_slug', $slug)->first();
        DB::table('accounts')->where('parent_id', $group->id)->update(['parent_id' => $group->parent_id]);
        DB::table('accounts')->where('id', $group->id)->delete();
    }

    $currentAssets = DB::table('accounts')->where('code', '1100')->value('id');
    foreach ([['cash_in_hand', '1110', 'Cash'], ['cash_at_bank', '1120', 'Cash at Bank'], ['mobile_financial_services', '1130', 'Mobile Financial Services']] as [$slug, $code, $name]) {
        DB::table('accounts')->where('system_slug', $slug)->update(['code' => $code, 'name' => $name, 'parent_id' => $currentAssets]);
    }

    $id = fn (string $slug) => DB::table('accounts')->where('system_slug', $slug)->value('id');
    $make = fn (string $name, string $type, string $slug) => MoneyAccount::create([
        'business_id' => $business->id, 'name' => $name, 'type' => $type, 'account_id' => $id($slug),
    ]);

    return [
        'brac' => $make('BRAC Bank', 'bank', 'cash_at_bank'),
        'till' => $make('Cash in Hand (Uttar Adabor Till)', 'cash', 'cash_in_hand'),
        'bkash' => $make('bKash', 'mfs', 'mobile_financial_services'),
        'nagad' => $make('Nagad', 'mfs', 'mobile_financial_services'),
        'rocket' => $make('Rocket', 'mfs', 'mobile_financial_services'),
    ];
}

function moneyLedgersMigration(): object
{
    return require base_path('app/Modules/Accounting/database/migrations/2026_09_21_120000_every_money_account_gets_its_own_ledger.php');
}

it('recodes the old heads under new groups, keeps their lines, and gives every account a head of its own', function () {
    $accounts = productionShapedMoneyChart($this->business);
    $oldBank = Account::bySlug('cash_at_bank');

    // A payment voucher paid from bKash, posted before the change.
    ($this->post)([
        JournalLineDraft::debit(Account::where('code', '6051')->sole()->id, Money::ofTaka('240')),
        JournalLineDraft::credit(Account::bySlug('mobile_financial_services')->id, Money::ofTaka('240'), moneyAccount: $accounts['bkash']),
    ]);

    moneyLedgersMigration()->up();

    $group = Account::bySlug('bank_accounts');
    $bank = Account::find($oldBank->id);

    expect($group->code)->toBe('1120')
        ->and($group->is_postable)->toBeFalse()
        ->and($group->parent->code)->toBe('1100')
        // ⚠ Same row: every line posted to it still resolves and still reverses.
        ->and($bank->code)->toBe('11201')
        ->and($bank->name)->toBe('BRAC Bank - Bor de Guna')
        ->and($bank->parent_id)->toBe($group->id)
        ->and($bank->system_slug)->toBe('cash_at_bank')
        ->and(Account::bySlug('cash_in_hand')->name)->toBe('Cash in Hand (Uttar Adabor Till) - Bor de Guna');

    // bKash had the lines, so bKash keeps the old row; Nagad and Rocket get their own.
    $wallets = collect(['bkash', 'nagad', 'rocket'])->map(fn ($key) => Account::find($accounts[$key]->fresh()->account_id));
    expect($wallets->pluck('code')->all())->toBe(['11301', '11302', '11303'])
        ->and($wallets->pluck('name')->all())->toBe(['bKash - Bor de Guna', 'Nagad - Bor de Guna', 'Rocket - Bor de Guna'])
        ->and($wallets->pluck('parent_id')->unique()->all())->toBe([Account::bySlug('mfs_accounts')->id])
        ->and(JournalLine::where('account_id', $wallets[0]->id)->count())->toBe(1);

    // Written down, because a migration bypasses the model's own activity log.
    expect(DB::table('activity_log')->where('properties', 'like', '%One ledger head per cash box%')->count())->toBeGreaterThanOrEqual(8);
});

it('rolls back to the old shape, names and all, and applies again cleanly', function () {
    $accounts = productionShapedMoneyChart($this->business);
    $migration = moneyLedgersMigration();

    $migration->up();
    $migration->down();

    expect(Account::bySlug('cash_at_bank')->code)->toBe('1120')
        ->and(Account::bySlug('cash_in_hand')->name)->toBe('Cash')
        ->and(Account::bySlug('bank_accounts'))->toBeNull()
        ->and($accounts['nagad']->fresh()->account_id)->toBe(Account::bySlug('mobile_financial_services')->id);

    $migration->up();

    expect(Account::bySlug('cash_at_bank')->code)->toBe('11201')
        ->and(Account::where('code', '1120')->sole()->system_slug)->toBe('bank_accounts');
});

it('never guesses whose history a shared head holds', function () {
    $accounts = productionShapedMoneyChart($this->business);
    $wallet = Account::bySlug('mobile_financial_services');

    foreach (['bkash', 'nagad'] as $key) {
        ($this->post)([
            JournalLineDraft::debit($wallet->id, Money::ofTaka('100'), moneyAccount: $accounts[$key]),
            JournalLineDraft::credit($this->capital->id, Money::ofTaka('100')),
        ]);
    }

    moneyLedgersMigration()->up();

    // Both wallets posted to it, so it stays as a named legacy head and every
    // wallet starts a head of its own.
    expect($wallet->fresh()->name)->toBe('Mobile Financial Services (shared before 21 Sep 2026)')
        ->and(collect(['bkash', 'nagad', 'rocket'])->map(fn ($key) => $accounts[$key]->fresh()->account_id)->contains($wallet->id))->toBeFalse();
});

it('does nothing on an empty database, where the seeder plants the new shape', function () {
    DB::table('accounts')->whereIn('system_slug', ['cash_in_hand', 'cash_at_bank', 'mobile_financial_services'])->update(['system_slug' => null]);
    $before = DB::table('accounts')->count();

    moneyLedgersMigration()->up();

    expect(DB::table('accounts')->count())->toBe($before);
});

// ─── BANK & CASH MAKES THE HEAD ─────────────────────────────────────────────

it('takes over the placeholder for the first account and numbers the next one after it', function () {
    $brac = ($this->addAccount)('BRAC Bank', MoneyAccountType::Bank);
    $city = ($this->addAccount)('City Bank', MoneyAccountType::Bank);

    expect(Account::find($brac->account_id)->code)->toBe('11201')
        ->and(Account::find($brac->account_id)->system_slug)->toBe('cash_at_bank')
        ->and(Account::find($city->account_id)->code)->toBe('11202')
        ->and(Account::find($city->account_id)->name)->toBe('City Bank - Bor de Guna');
});

it('renames the head when the account is renamed', function () {
    $bank = ($this->addAccount)('BRAC Bank', MoneyAccountType::Bank);

    $this->actingAs($this->owner)->put(route('accounting.money-accounts.update', $bank->id), [
        'name' => 'BRAC Bank - Current', 'type' => 'bank', 'is_active' => true,
    ])->assertSessionHasNoErrors();

    expect(Account::find($bank->account_id)->name)->toBe('BRAC Bank - Current - Bor de Guna');
});

it('moves an unused account to the right group when its kind is corrected', function () {
    $wrong = ($this->addAccount)('bKash merchant', MoneyAccountType::Bank);

    $this->actingAs($this->owner)->put(route('accounting.money-accounts.update', $wrong->id), [
        'name' => 'bKash merchant', 'type' => 'mfs', 'is_active' => true,
    ])->assertSessionHasNoErrors();

    $head = Account::find($wrong->fresh()->account_id);

    expect($head->parent->system_slug)->toBe('mfs_accounts')
        ->and($head->code)->toStartWith('113');
});

it('refuses to change the kind of an account money has moved through', function () {
    $bank = ($this->addAccount)('BRAC Bank', MoneyAccountType::Bank);
    ($this->post)([
        JournalLineDraft::debit($bank->account_id, Money::ofTaka('500'), moneyAccount: $bank),
        JournalLineDraft::credit($this->capital->id, Money::ofTaka('500')),
    ]);

    $this->actingAs($this->owner)->put(route('accounting.money-accounts.update', $bank->id), [
        'name' => 'BRAC Bank', 'type' => 'cash', 'is_active' => true,
    ])->assertSessionHasErrors('type');

    expect($bank->fresh()->type)->toBe(MoneyAccountType::Bank);
});

// ─── THE POSTING ENGINE ─────────────────────────────────────────────────────

it('stamps a plain line on a bank head with that bank, so every cash view sees it', function () {
    $bank = ($this->addAccount)('BRAC Bank', MoneyAccountType::Bank);

    // How an opening balance arrives: a manual journal line with no money account.
    $journal = ($this->post)([
        JournalLineDraft::debit($bank->account_id, Money::ofTaka('10000')),
        JournalLineDraft::credit($this->capital->id, Money::ofTaka('10000')),
    ]);

    expect($journal->lines->firstWhere('account_id', $bank->account_id)->money_account_id)->toBe($bank->id);
});

it('refuses another business posting to this business bank', function () {
    $bank = ($this->addAccount)('BRAC Bank', MoneyAccountType::Bank);

    $other = Business::create(['name' => 'LaLaDia', 'slug' => 'laladia', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($other->id);

    expect(fn () => ($this->post)([
        JournalLineDraft::debit($bank->account_id, Money::ofTaka('1')),
        JournalLineDraft::credit($this->capital->id, Money::ofTaka('1')),
    ]))->toThrow(JournalException::class, 'ledger of another business');
});

it('refuses one account money on another account head', function () {
    $brac = ($this->addAccount)('BRAC Bank', MoneyAccountType::Bank);
    $city = ($this->addAccount)('City Bank', MoneyAccountType::Bank);

    expect(fn () => ($this->post)([
        JournalLineDraft::debit($city->account_id, Money::ofTaka('1'), moneyAccount: $brac),
        JournalLineDraft::credit($this->capital->id, Money::ofTaka('1')),
    ]))->toThrow(JournalException::class, 'is not the ledger of BRAC Bank');
});

it('refuses a head with no account behind it', function () {
    expect(fn () => ($this->post)([
        JournalLineDraft::debit(Account::bySlug('cash_at_bank')->id, Money::ofTaka('1')),
        JournalLineDraft::credit($this->capital->id, Money::ofTaka('1')),
    ]))->toThrow(JournalException::class, 'no Bank & cash account behind it');
});

it('reverses exactly what was posted', function () {
    $bank = ($this->addAccount)('BRAC Bank', MoneyAccountType::Bank);
    $journal = ($this->post)([
        JournalLineDraft::debit($bank->account_id, Money::ofTaka('700'), moneyAccount: $bank),
        JournalLineDraft::credit($this->capital->id, Money::ofTaka('700')),
    ]);

    $reversal = app(ReverseJournal::class)->handle($journal, $this->owner, memo: 'Entered twice');

    expect($reversal->lines->firstWhere('account_id', $bank->account_id)->money_account_id)->toBe($bank->id);
});

// ─── THE OTHER WAYS IN ARE SHUT ─────────────────────────────────────────────

it('keeps every head under the money groups off a voucher line, tied or not', function () {
    $loose = Account::create(['code' => '11299', 'name' => 'Typed in by hand', 'type' => 'asset', 'parent_id' => Account::bySlug('bank_accounts')->id, 'is_postable' => true]);

    expect(VoucherAccounts::refusal($loose, Carbon::today()))->not->toBeNull();
});

it('will not make or bend a money head from the chart screen', function () {
    $bank = ($this->addAccount)('BRAC Bank', MoneyAccountType::Bank);
    $group = Account::bySlug('bank_accounts');

    $this->actingAs($this->owner)->post(route('accounting.accounts.store'), [
        'code' => '11250', 'name' => 'Hand-made bank', 'type' => 'asset', 'parent_id' => $group->id, 'is_postable' => true, 'is_active' => true,
    ])->assertSessionHasErrors('parent_id');

    $head = Account::find($bank->account_id);
    $this->actingAs($this->owner)->put(route('accounting.accounts.update', $head->id), [
        'code' => $head->code, 'name' => 'Renamed on the chart', 'type' => 'asset', 'parent_id' => $group->id, 'is_postable' => true, 'is_active' => true,
    ])->assertSessionHasErrors('name');

    expect(Account::where('code', '11250')->exists())->toBeFalse()
        ->and($head->fresh()->name)->toBe('BRAC Bank - Bor de Guna');
});

it('will not turn a head with postings into a heading, nor a heading with accounts into a head', function () {
    $rent = Account::where('code', '6051')->sole();
    $bank = ($this->addAccount)('BRAC Bank', MoneyAccountType::Bank);
    ($this->post)([
        JournalLineDraft::debit($rent->id, Money::ofTaka('5')),
        JournalLineDraft::credit($bank->account_id, Money::ofTaka('5'), moneyAccount: $bank),
    ]);

    $this->actingAs($this->owner)->put(route('accounting.accounts.update', $rent->id), [
        'code' => $rent->code, 'name' => $rent->name, 'type' => 'expense', 'parent_id' => $rent->parent_id, 'is_postable' => false, 'is_active' => true,
    ])->assertSessionHasErrors('is_postable');

    $heading = $rent->parent;
    $this->actingAs($this->owner)->put(route('accounting.accounts.update', $heading->id), [
        'code' => $heading->code, 'name' => $heading->name, 'type' => 'expense', 'parent_id' => $heading->parent_id, 'is_postable' => true, 'is_active' => true,
    ])->assertSessionHasErrors('is_postable');
});

it('refuses to delete a bank head, with a message rather than an error page', function () {
    $bank = ($this->addAccount)('BRAC Bank', MoneyAccountType::Bank);

    $this->actingAs($this->owner)->delete(route('accounting.accounts.destroy', $bank->account_id))
        ->assertSessionHas('error');

    expect(Account::find($bank->account_id))->not->toBeNull();
});

it('does not offer the money groups to the New ledger head dialog', function () {
    $this->actingAs($this->owner)->getJson(route('accounting.accounts.quick.options', ['type' => 'asset']))
        ->assertOk()
        ->assertJsonMissing(['label' => '1120 - Bank Accounts']);
});

// ─── THE REPORTS ────────────────────────────────────────────────────────────

it('keeps the bank inside Current Assets with a subtotal, one line per account', function () {
    $brac = ($this->addAccount)('BRAC Bank', MoneyAccountType::Bank);
    $city = ($this->addAccount)('City Bank', MoneyAccountType::Bank);

    foreach ([[$brac, '300'], [$city, '200']] as [$account, $amount]) {
        ($this->post)([
            JournalLineDraft::debit($account->account_id, Money::ofTaka($amount), moneyAccount: $account),
            JournalLineDraft::credit($this->capital->id, Money::ofTaka($amount)),
        ]);
    }

    $sheet = BalanceSheet::asAt(Carbon::today(), $this->business);
    $current = $sheet->assets->sole();

    expect($current->code)->toBe('1100')
        ->and($current->total()->minor)->toBe(50000)
        ->and($current->lines->map(fn ($line) => $line->group?->code)->unique()->all())->toBe(['1120']);

    $this->actingAs($this->owner)->get(route('accounting.balance-sheet'))
        ->assertInertia(fn ($page) => $page
            ->where('assets.0.groups.1120.total', '500.00')
            ->where('assets.0.lines.0.group_code', '1120'));
});

it('forecasts from this business cash only', function () {
    $bank = ($this->addAccount)('BRAC Bank', MoneyAccountType::Bank);
    ($this->post)([
        JournalLineDraft::debit($bank->account_id, Money::ofTaka('1000'), moneyAccount: $bank),
        JournalLineDraft::credit($this->capital->id, Money::ofTaka('1000')),
    ]);

    $other = Business::create(['name' => 'LaLaDia', 'slug' => 'laladia', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($other->id);

    expect(CashFlowForecast::build()['opening_cash'])->toBe(0.0);

    TenantScope::enter($this->business->id);

    expect(CashFlowForecast::build()['opening_cash'])->toBe(1000.0);
});

it('shows no other business its bank statement', function () {
    $other = Business::create(['name' => 'LaLaDia', 'slug' => 'laladia', 'fiscal_year_start_month' => 7]);
    $theirs = MoneyAccount::withoutGlobalScopes()->create(['business_id' => $other->id, 'name' => 'Their bank', 'type' => 'bank']);

    $this->actingAs($this->owner)
        ->get(route('accounting.bank-statements', ['money_account_id' => $theirs->id]))
        ->assertNotFound();
});

it('names the ledger it created and never offers a picker', function () {
    ($this->addAccount)('BRAC Bank', MoneyAccountType::Bank);

    $this->actingAs($this->owner)->get(route('accounting.money-accounts.index'))
        ->assertInertia(fn ($page) => $page->where('accounts.0.ledger_account', '11201 - BRAC Bank - Bor de Guna'));

    expect(MoneyLedgers::codePrefix('1120'))->toBe('112');
});
