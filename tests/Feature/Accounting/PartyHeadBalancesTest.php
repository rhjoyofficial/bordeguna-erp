<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\PartyHeadBalances;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * B23 lifetime views (25 Oct 2026; R111): a customer's, supplier's or
 * employee's own head on the chart shows the person's balance (every line
 * that names them, on any control head) and opens their page.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-20 10:00:00');

    $this->business = Business::create(['name' => 'Heads Co', 'slug' => 'heads-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    Permission::findOrCreate('accounting.view', 'web');
    $reader = Role::firstOrCreate(['name' => 'Reader', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $reader->givePermissionTo('accounting.view');
    $this->seed(ChartOfAccountsSeeder::class);

    $this->reader = User::factory()->create();
    $this->reader->businesses()->attach($this->business->id);
    $this->reader->assignRole($reader);
    $this->as = fn (User $user) => $this->actingAs($user)->withHeader('X-Business-Id', (string) $this->business->id);

    $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Rahim', 'code' => 'RAHIM']);
    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Padma Traders']);

    $this->post = function (array $lines) {
        app(PostJournal::class)->handle(new JournalDraft(date: Carbon::parse('2026-10-10'), lines: $lines), $this->reader);
    };

    // Rahim owes us 500; we owe Padma 700.
    ($this->post)([
        JournalLineDraft::debit(Account::bySlug('accounts_receivable')->id, Money::ofTaka('500'), party: $this->customer),
        JournalLineDraft::credit(Account::bySlug('owners_capital')->id, Money::ofTaka('500')),
    ]);
    ($this->post)([
        JournalLineDraft::credit(Account::bySlug('accounts_payable')->id, Money::ofTaka('700'), party: $this->supplier),
        JournalLineDraft::debit(Account::bySlug('owners_capital')->id, Money::ofTaka('700')),
    ]);
});

afterEach(fn () => Carbon::setTestNow());

it('shows each party head\'s balance on the chart, with the page that opens it', function () {
    $customerHead = $this->customer->fresh()->account_id;
    $supplierHead = $this->supplier->fresh()->account_id;

    ($this->as)($this->reader)->get('/accounting/accounts')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('accounting/accounts/index')
            ->where("party_heads.{$customerHead}.balance", '500.00')
            ->where("party_heads.{$customerHead}.side", 'dr')
            ->where("party_heads.{$customerHead}.url", '/sales/customers/'.$this->customer->id)
            ->where("party_heads.{$supplierHead}.balance", '700.00')
            ->where("party_heads.{$supplierHead}.side", 'cr')
            ->where("party_heads.{$supplierHead}.url", '/purchasing/suppliers/'.$this->supplier->id));
});

it('reads every party head balance in a fixed number of queries', function () {
    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        PartyHeadBalances::byHead();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $first = $count();

    foreach (range(1, 4) as $i) {
        $customer = Customer::create(['business_id' => $this->business->id, 'name' => "Dealer {$i}", 'code' => "D{$i}"]);
        ($this->post)([
            JournalLineDraft::debit(Account::bySlug('accounts_receivable')->id, Money::ofTaka('10'), party: $customer),
            JournalLineDraft::credit(Account::bySlug('owners_capital')->id, Money::ofTaka('10')),
        ]);
    }

    expect($count())->toBe($first)
        ->and(count(PartyHeadBalances::byHead()))->toBe(6);
});
