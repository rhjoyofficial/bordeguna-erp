<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\MoneyAccountLifetime;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Purchasing\Models\Supplier;
use App\Support\Lifetime\LifetimeRange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * B23 lifetime views (25 Oct 2026; R77): one money account's page, its whole
 * life in and out by the kind of document that moved it, read from its own
 * ledger head, so the end figure is the Bank and cash list's balance.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-20 10:00:00');

    $this->business = Business::create(['name' => 'Till Co', 'slug' => 'till-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);

    foreach (['accounting.view', 'accounting.journal.post'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $reader = Role::firstOrCreate(['name' => 'Reader', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $reader->givePermissionTo('accounting.view');
    $this->seed(ChartOfAccountsSeeder::class);

    $this->reader = User::factory()->create();
    $this->reader->businesses()->attach($this->business->id);
    $this->reader->assignRole($reader);
    $this->as = fn (User $user) => $this->actingAs($user)->withHeader('X-Business-Id', (string) $this->business->id);

    $this->till = Account::bySlug('cash_in_hand');
    $this->capital = Account::bySlug('owners_capital');
    $this->box = MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'Shop till', 'type' => 'cash', 'account_id' => $this->till->id]);
    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Razzak Oil Mill']);

    $this->post = function (string $amount, string $date, bool $in = true, $source = null) {
        app(PostJournal::class)->handle(new JournalDraft(
            date: Carbon::parse($date),
            lines: $in
                ? [JournalLineDraft::debit($this->till->id, Money::ofTaka($amount)), JournalLineDraft::credit($this->capital->id, Money::ofTaka($amount))]
                : [JournalLineDraft::credit($this->till->id, Money::ofTaka($amount)), JournalLineDraft::debit($this->capital->id, Money::ofTaka($amount))],
            source: $source,
        ), $this->reader);
    };

    ($this->post)('1000', '2026-09-01');
    ($this->post)('250', '2026-10-05', false);
    ($this->post)('300', '2026-10-06', false, $this->supplier);
});

afterEach(fn () => Carbon::setTestNow());

it('shows a money account\'s lifetime in and out by kind, ending at today\'s balance', function () {
    ($this->as)($this->reader)->get("/accounting/money-accounts/{$this->box->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('accounting/money-accounts/show')
            ->where('account.name', 'Shop till')
            ->where('account.balance', '450.00')
            ->where('range.all_time', true)
            ->where('lifetime.opening', '0.00')
            ->where('lifetime.money_in', '1,000.00')
            ->where('lifetime.money_out', '550.00')
            ->where('lifetime.closing', '450.00')
            ->has('lifetime.kinds', 2)
            ->where('account.ledger_url', fn (string $url) => str_starts_with($url, '/accounting/general-ledger?account='.$this->till->id)));
});

it('carries what came before the window in as an opening', function () {
    $lifetime = ($this->as)($this->reader)->get("/accounting/money-accounts/{$this->box->id}?from=2026-10-01")->viewData('page')['props']['lifetime'];

    expect($lifetime['opening'])->toBe('1,000.00')
        ->and($lifetime['money_in'])->toBe('0.00')
        ->and($lifetime['money_out'])->toBe('550.00')
        ->and($lifetime['closing'])->toBe('450.00');
});

it('reads the lifetime in a fixed number of queries however many journals there are', function () {
    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        (new MoneyAccountLifetime($this->box->fresh(), new LifetimeRange(Carbon::parse('2026-10-01'), null)))->summary();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $first = $count();

    foreach (range(1, 5) as $i) {
        ($this->post)('10', "2026-10-1{$i}");
    }

    expect($count())->toBe($first);
});

it('links each account on the Bank and cash list, exports, and stays behind the accounting permission', function () {
    expect(file_get_contents(resource_path('js/pages/accounting/money-accounts/index.tsx')))->toContain('/accounting/money-accounts/${account.id}');

    $csv = ($this->as)($this->reader)->get("/accounting/money-accounts/{$this->box->id}/lifetime-export")->assertOk()->streamedContent();
    expect($csv)->toStartWith("\xEF\xBB\xBF")->and($csv)->toContain('Closing');

    $outsider = User::factory()->create();
    $outsider->businesses()->attach($this->business->id);
    ($this->as)($outsider)->get("/accounting/money-accounts/{$this->box->id}")->assertForbidden();
    ($this->as)($outsider)->get("/accounting/money-accounts/{$this->box->id}/lifetime-export")->assertForbidden();
});
