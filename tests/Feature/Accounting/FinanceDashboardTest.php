<?php

use App\Models\User;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The finance overview.
 *
 * ⚠⚠ THE POINT OF THIS SCREEN IS THAT IT COMPUTES NOTHING OF ITS OWN. Every
 * figure comes from the report class that already produces the statement of the
 * same name. A dashboard with its own arithmetic eventually disagrees with the
 * statements, and then nobody knows which to believe. The tests below check that
 * it AGREES, not that it can add up.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Books Co', 'slug' => 'books-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Permission::firstOrCreate(['name' => 'accounting.view', 'guard_name' => 'web']);

    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['accounting.view']);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');
});

function account(int $businessId, string $code, string $name, AccountType $type): Account
{
    return Account::create([
        'code' => $code, 'name' => $name, 'type' => $type, 'is_postable' => true,
    ]);
}

it('says plainly that nothing has been posted, rather than showing a broken-looking screen', function () {
    // ⚠⚠ A dashboard of zeroes on a business that has not started trading looks
    // identical to a dashboard that is broken, and somebody reports it as a bug.
    $this->actingAs($this->owner)->get('/accounting/overview')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('accounting/finance-dashboard')
            ->where('hasPostings', false)
            ->where('thisMonth.income', 0)
            ->where('owed.to_us', 0)
        );
});

it('always draws a whole six months, so an empty month is visibly empty', function () {
    // ⚠ Six slots whatever the data. A chart that only plots months with figures
    // silently changes its own x-axis and makes a gap look like growth.
    $this->actingAs($this->owner)->get('/accounting/overview')
        ->assertInertia(fn ($page) => $page
            ->where('months', fn ($m) => count($m) === 6)
            ->where('profitTrend', fn ($t) => count($t) === 6)
        );
});

/**
 * ⚠⚠ `forceCreate`, and deliberately. Journal and JournalLine have an EMPTY
 * $fillable on purpose: only the posting engine may build one, and journal lines
 * are immutable once written. A test fixture is the one legitimate exception,
 * and saying so here is cheaper than somebody later "fixing" the models.
 */
function postedJournal(int $businessId, int $userId, string $number, array $lines): void
{
    $journal = App\Modules\Accounting\Models\Journal::forceCreate([
        'business_id' => $businessId,
        'journal_number' => $number,
        'journal_type' => 'manual',
        'journal_date' => now()->startOfMonth()->addDay(),
        'memo' => 'Test posting',
        'posted_by' => $userId,
        'posted_at' => now(),
    ]);

    foreach ($lines as $index => [$accountId, $minor]) {
        App\Modules\Accounting\Models\JournalLine::forceCreate([
            'business_id' => $businessId,
            'journal_id' => $journal->id,
            'line_no' => $index + 1,
            'account_id' => $accountId,
            'amount_minor' => $minor,
        ]);
    }
}

it('reports income and expense from the ledger, in taka rather than paisa', function () {
    $sales = account($this->business->id, '4000', 'Sales', AccountType::Income);
    $rent = account($this->business->id, '6200', 'Rent', AccountType::Expense);
    $bank = account($this->business->id, '1100', 'Bank', AccountType::Asset);

    // ⚠⚠ `amount_minor`, signed whole paisa. Income is credit-normal and so
    // NEGATIVE in the ledger's sign; there is no `amount` column and never was.
    postedJournal($this->business->id, $this->owner->id, 'JV-1', [[$bank->id, 500000], [$sales->id, -500000]]);
    postedJournal($this->business->id, $this->owner->id, 'JV-2', [[$rent->id, 200000], [$bank->id, -200000]]);

    $this->actingAs($this->owner)->get('/accounting/overview')
        ->assertInertia(fn ($page) => $page
            ->where('hasPostings', true)
            // 500000 paisa = 5000.00 taka. Converted once, at the very edge.
            // ⚠ Compared as a NUMBER: a whole taka amount serialises to JSON as
            // 5000, not 5000.0, so a strict comparison against a float fails on
            // a figure that is perfectly correct.
            ->where('thisMonth.income', fn ($v) => (float) $v === 5000.0)
            ->where('thisMonth.expenses', fn ($v) => (float) $v === 2000.0)
            ->where('thisMonth.netProfit', fn ($v) => (float) $v === 3000.0)
        );
});

it('lists where cash is kept without offering a second opinion on the balances', function () {
    MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Office cash', 'type' => 'cash', 'is_active' => true,
    ]);
    MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Closed wallet', 'type' => 'mfs', 'is_active' => false,
    ]);

    // ⚠ Active only, and NAMES only. Balances live in the cash book, which reads
    // them from the ledger; a second computation here is exactly the kind of
    // disagreement this screen exists to avoid.
    $this->actingAs($this->owner)->get('/accounting/overview')
        ->assertInertia(fn ($page) => $page->where('moneyAccounts', fn ($a) => count($a) === 1 && $a[0]['name'] === 'Office cash'));
});

it('shows every ageing bucket even when they are all empty', function () {
    // ⚠ Five buckets always. A breakdown that hides empty buckets makes the
    // reader count which ones are missing.
    $this->actingAs($this->owner)->get('/accounting/overview')
        ->assertInertia(fn ($page) => $page
            ->where('receivables', fn ($r) => count($r) === 5)
            ->where('payables', fn ($p) => count($p) === 5)
        );
});

it('is shut to anybody who may not read the statements it summarises', function () {
    $nobody = User::factory()->create();
    $nobody->businesses()->attach($this->business->id);

    // ⚠ A dashboard is not a softer thing than the report behind it.
    $this->actingAs($nobody)->get('/accounting/overview')->assertForbidden();
});
