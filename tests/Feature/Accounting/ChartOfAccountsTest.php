<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Enums\NormalBalance;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 2.1 of Accounting: the Chart of Accounts.
 *
 * C-GL-1 is settled - ONE shared chart, separate balances per business - so the
 * chart is installation-level configuration and the journal lines that reference
 * it are business-scoped. Almost every test in this file exists to keep that one
 * decision from being quietly reversed.
 *
 * See ACCOUNTING-LEDGER-ARCHITECTURE.md §2.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Ledger Co', 'slug' => 'ledger-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['accounting.view', 'accounting.chart.manage'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $accountantRole = Role::firstOrCreate(['name' => 'Bookkeeper', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $accountantRole->givePermissionTo(['accounting.view', 'accounting.chart.manage']);

    $readerRole = Role::firstOrCreate(['name' => 'Reader', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $readerRole->givePermissionTo('accounting.view');

    $this->accountant = User::factory()->create();
    $this->accountant->businesses()->attach($this->business->id);
    $this->accountant->assignRole($accountantRole);

    $this->reader = User::factory()->create();
    $this->reader->businesses()->attach($this->business->id);
    $this->reader->assignRole($readerRole);

    $this->outsider = User::factory()->create();
    $this->outsider->businesses()->attach($this->business->id);
});

// ─── The decision that everything else rests on ─────────────────────────────

it('keeps the chart out of every tenant, because there is only one of it', function () {
    // ⚠⚠ THE STRUCTURAL ASSERTION. If business_id ever appears on this table,
    // four businesses have four charts, consolidation becomes a name-matching
    // exercise and this decision has been reversed by accident.
    expect(Schema::hasColumn('accounts', 'business_id'))->toBeFalse();

    $account = Account::create(['code' => '9001', 'name' => 'Test', 'type' => AccountType::Asset]);

    // Seen from a different business, and from no business at all - which is the
    // half that matters, because a scheduled posting job has no tenant and every
    // other model in this system goes blind without one.
    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-ledger-co']);
    app(BusinessContext::class)->set($other->id);
    expect(Account::find($account->id))->not->toBeNull();

    app(BusinessContext::class)->forget();
    expect(Account::find($account->id))->not->toBeNull();
});

it('holds no balance, because the ledger owns that', function () {
    $account = Account::create(['code' => '9002', 'name' => 'Test', 'type' => AccountType::Asset]);

    // ⚠ The same absence assertion money_accounts carries. A balance column here
    // would be a second source of truth for a number the journal lines already
    // answer, and the stored one always wins an argument it should lose.
    expect(array_keys($account->getAttributes()))
        ->not->toContain('balance')
        ->not->toContain('current_balance')
        ->not->toContain('opening_balance');
});

// ─── The seeded chart ───────────────────────────────────────────────────────

it('seeds a chart whose every child matches its parent type', function () {
    $this->seed(ChartOfAccountsSeeder::class);

    // ⚠⚠ The assertion that actually checks the CHART rather than the code. An
    // expense filed under a current-asset group does not announce itself: the
    // trial balance still balances and the balance sheet is wrong by the amount
    // of it. Walking all of them is the only way to know the draft is sound.
    $accounts = Account::all()->keyBy('id');

    foreach ($accounts as $account) {
        if ($account->parent_id === null) {
            continue;
        }

        expect($accounts[$account->parent_id]->type)
            ->toBe($account->type, "{$account->label()} sits under a different type of account");
    }

    expect(Account::count())->toBeGreaterThan(40);
});

it('seeds every code at the same width, because chart order IS code order', function () {
    $this->seed(ChartOfAccountsSeeder::class);

    // ⚠ Account::scopeInChartOrder sorts by a STRING. A three-digit code sorts
    // before every four-digit one, so a single short code silently moves an
    // account to the top of every report that uses the chart order.
    foreach (Account::all() as $account) {
        expect(strlen($account->code))->toBe(4, "{$account->code} is not four digits");
    }
});

it('never lets a group account be posted to', function () {
    $this->seed(ChartOfAccountsSeeder::class);

    // Postable and having children are mutually exclusive: you post to "Cash in
    // Hand", never to "Current Assets", or the group total double counts.
    foreach (Account::with('children')->get() as $account) {
        if ($account->children->isNotEmpty()) {
            expect($account->is_postable)->toBeFalse("{$account->label()} is a group and is postable");
        }
    }
});

it('provides the named accounts the software itself has to find', function () {
    $this->seed(ChartOfAccountsSeeder::class);

    // ⚠ Phase 3 maps subsystem events onto these slugs. A missing one is a
    // posting that cannot be made, discovered at the worst possible moment.
    foreach ([
        'cash_in_hand', 'cash_at_bank', 'accounts_receivable', 'stock_on_hand',
        'accounts_payable', 'goods_received_not_invoiced', 'salaries_payable',
        'owners_capital', 'retained_earnings', 'opening_balance_equity',
        'sales_revenue', 'cost_of_goods_sold', 'inventory_write_off',
        'salaries_expense', 'rounding_difference',
    ] as $slug) {
        expect(Account::bySlug($slug))->not->toBeNull("no account carries the slug {$slug}");
    }

    // The account that proves the opening balances, and must read zero when they
    // are all in. It is equity, so a debit balance on it means something is
    // missing rather than that the business is insolvent.
    expect(Account::bySlug('opening_balance_equity')->type)->toBe(AccountType::Equity);
});

it('adds new accounts on a re-run without overwriting what somebody changed', function () {
    $this->seed(ChartOfAccountsSeeder::class);

    $rent = Account::where('code', '6200')->first();
    $rent->update(['name' => 'Office rent - Uttar Adabor']);

    // Somebody deleted an account they did not want. The re-run must put back
    // what is missing, and leave the rename alone.
    Account::where('code', '6910')->delete();

    $this->seed(ChartOfAccountsSeeder::class);

    // ⚠ Idempotent per ROW. This project has twice shipped a seeder that skipped
    // its entire body once it found its first record already present, so a later
    // addition never reached an installation that ran the earlier version.
    expect(Account::where('code', '6910')->exists())->toBeTrue()
        ->and(Account::where('code', '6200')->first()->name)->toBe('Office rent - Uttar Adabor');
});

it('does not duplicate anything when run twice', function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $first = Account::count();

    $this->seed(ChartOfAccountsSeeder::class);

    expect(Account::count())->toBe($first);
});

// ─── What an account means ──────────────────────────────────────────────────

it('derives the normal balance from the type rather than storing it', function () {
    expect(AccountType::Asset->normalBalance())->toBe(NormalBalance::Debit)
        ->and(AccountType::Expense->normalBalance())->toBe(NormalBalance::Debit)
        ->and(AccountType::Liability->normalBalance())->toBe(NormalBalance::Credit)
        ->and(AccountType::Equity->normalBalance())->toBe(NormalBalance::Credit)
        ->and(AccountType::Income->normalBalance())->toBe(NormalBalance::Credit);

    expect(Schema::hasColumn('accounts', 'normal_balance'))->toBeFalse();
});

it('splits the two statements by type', function () {
    expect(AccountType::Asset->isBalanceSheet())->toBeTrue()
        ->and(AccountType::Liability->isBalanceSheet())->toBeTrue()
        ->and(AccountType::Equity->isBalanceSheet())->toBeTrue()
        ->and(AccountType::Income->isBalanceSheet())->toBeFalse()
        ->and(AccountType::Expense->isBalanceSheet())->toBeFalse();
});

it('refuses to let a request point a system slug at another account', function () {
    // ⚠⚠ system_slug is not fillable on purpose. If a form could set it, one
    // request could aim `stock_on_hand` at Owner's Drawings and every future
    // inventory journal would follow it there. Same reasoning as W2's
    // businesses.is_default.
    $account = Account::create([
        'code' => '9003',
        'name' => 'Impostor',
        'type' => AccountType::Asset,
        'system_slug' => 'stock_on_hand',
    ]);

    expect($account->fresh()->system_slug)->toBeNull();
});

// ─── The screen ─────────────────────────────────────────────────────────────

it('shows the chart to somebody who may read the books', function () {
    $this->seed(ChartOfAccountsSeeder::class);

    $this->actingAs($this->reader)
        ->get(route('accounting.accounts.index'))
        ->assertOk();
});

it('refuses the chart to somebody with no accounting permission at all', function () {
    // A general ledger eventually carries a line per salary payment, so reading
    // it is close to reading payroll. Being inside the business is not enough.
    $this->actingAs($this->outsider)
        ->get(route('accounting.accounts.index'))
        ->assertForbidden();
});

it('lets a reader look without letting them change anything', function () {
    $this->actingAs($this->reader)
        ->post(route('accounting.accounts.store'), [
            'code' => '6320', 'name' => 'Security services', 'type' => 'expense',
            'parent_id' => null, 'is_postable' => true, 'is_active' => true,
        ])
        ->assertForbidden();
});

it('adds an account under a group of the same type', function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $operating = Account::where('code', '6000')->first();

    $this->actingAs($this->accountant)
        ->post(route('accounting.accounts.store'), [
            'code' => '6320', 'name' => 'Security services', 'type' => 'expense',
            'parent_id' => $operating->id, 'is_postable' => true, 'is_active' => true,
        ])
        ->assertRedirect();

    expect(Account::where('code', '6320')->first()->parent_id)->toBe($operating->id);
});

it('refuses an account whose type disagrees with its parent', function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $currentAssets = Account::where('code', '1100')->first();

    // ⚠ The mistake this prevents is made once, at setup, and found a year
    // later - because nothing about it is loud. The trial balance still
    // balances; only the balance sheet is wrong.
    $this->actingAs($this->accountant)
        ->post(route('accounting.accounts.store'), [
            'code' => '1195', 'name' => 'Rent paid', 'type' => 'expense',
            'parent_id' => $currentAssets->id, 'is_postable' => true, 'is_active' => true,
        ])
        ->assertSessionHasErrors('parent_id');

    expect(Account::where('code', '1195')->exists())->toBeFalse();
});

it('refuses to make an account its own ancestor', function () {
    $parent = Account::create(['code' => '9100', 'name' => 'Top', 'type' => AccountType::Asset, 'is_postable' => false]);
    $child = Account::create(['code' => '9110', 'name' => 'Under it', 'type' => AccountType::Asset, 'parent_id' => $parent->id]);

    // A two-step cycle passes a naive self-parent check and then hangs whatever
    // renders the tree, which is a page that never answers rather than an error.
    $this->actingAs($this->accountant)
        ->put(route('accounting.accounts.update', $parent->id), [
            'code' => '9100', 'name' => 'Top', 'type' => 'asset',
            'parent_id' => $child->id, 'is_postable' => false, 'is_active' => true,
        ])
        ->assertSessionHasErrors('parent_id');
});

it('refuses to re-type an account that has accounts under it', function () {
    $parent = Account::create(['code' => '9200', 'name' => 'Group', 'type' => AccountType::Asset, 'is_postable' => false]);
    Account::create(['code' => '9210', 'name' => 'Leaf', 'type' => AccountType::Asset, 'parent_id' => $parent->id]);

    $this->actingAs($this->accountant)
        ->put(route('accounting.accounts.update', $parent->id), [
            'code' => '9200', 'name' => 'Group', 'type' => 'expense',
            'parent_id' => null, 'is_postable' => false, 'is_active' => true,
        ])
        ->assertSessionHasErrors('type');

    expect($parent->fresh()->type)->toBe(AccountType::Asset);
});

it('deactivates rather than deletes an account the system posts to', function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $payable = Account::bySlug('accounts_payable');

    // ⚠ C-INV-E2: this asserted an error under the key `account`, which the
    // chart screen does not render, and delete is a bare button with no form
    // behind it. The refusal was correct and completely invisible. It flashes
    // now. What is refused has not changed.
    $this->actingAs($this->accountant)
        ->delete(route('accounting.accounts.destroy', $payable->id))
        ->assertRedirect();

    expect(session('error'))->toContain('used by the system itself')
        ->and(Account::find($payable->id))->not->toBeNull();

    // Deactivation is the supported way out, and it leaves the history readable.
    $this->actingAs($this->accountant)
        ->put(route('accounting.accounts.update', $payable->id), [
            'code' => $payable->code, 'name' => $payable->name, 'type' => $payable->type->value,
            'parent_id' => $payable->parent_id, 'is_postable' => true, 'is_active' => false,
        ])
        ->assertRedirect();

    expect($payable->fresh()->is_active)->toBeFalse()
        ->and(Account::postable()->whereKey($payable->id)->exists())->toBeFalse();
});

it('refuses to delete a group that still has accounts under it', function () {
    $parent = Account::create(['code' => '9300', 'name' => 'Group', 'type' => AccountType::Asset, 'is_postable' => false]);
    Account::create(['code' => '9310', 'name' => 'Leaf', 'type' => AccountType::Asset, 'parent_id' => $parent->id]);

    $this->actingAs($this->accountant)
        ->delete(route('accounting.accounts.destroy', $parent->id))
        ->assertRedirect();

    expect(session('error'))->toContain('has accounts under it')
        ->and(Account::find($parent->id))->not->toBeNull();
});

it('deletes an ordinary account nobody is using', function () {
    $account = Account::create(['code' => '9400', 'name' => 'Typo', 'type' => AccountType::Expense]);

    $this->actingAs($this->accountant)
        ->delete(route('accounting.accounts.destroy', $account->id))
        ->assertRedirect();

    expect(Account::find($account->id))->toBeNull();
});

it('keeps codes unique across the whole installation', function () {
    Account::create(['code' => '9500', 'name' => 'First', 'type' => AccountType::Asset]);

    $this->actingAs($this->accountant)
        ->post(route('accounting.accounts.store'), [
            'code' => '9500', 'name' => 'Second', 'type' => 'asset',
            'parent_id' => null, 'is_postable' => true, 'is_active' => true,
        ])
        ->assertSessionHasErrors('code');
});

it('reads an account the way a report prints it', function () {
    $account = Account::create(['code' => '1110', 'name' => 'Cash in Hand', 'type' => AccountType::Asset]);

    expect($account->label())->toBe('1110 - Cash in Hand');
});
