<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\MoneyAvailability;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Support\Carbon;

/**
 * Slice 0, D4: a courier's balance is that courier's money, not every courier's.
 *
 * All courier accounts report under the one head 1145 (and gateways under
 * 1146), so a balance read by head alone gave Pathao the money Steadfast holds
 * too. The line's money_account_id is what says whose money it is.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-20 10:00:00');

    $this->business = Business::create(['name' => 'Courier Co', 'slug' => 'courier-co']);
    app(BusinessContext::class)->set($this->business->id);

    (new ChartOfAccountsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    $this->user = User::factory()->create();

    $account = fn (string $name, string $type, string $slug) => MoneyAccount::query()->forceCreate([
        'business_id' => $this->business->id, 'name' => $name, 'type' => $type,
        'account_id' => Account::bySlug($slug)->id, 'is_active' => true,
    ]);

    $this->pathao = $account('Pathao', 'courier', 'courier_receivable');
    $this->steadfast = $account('Steadfast', 'courier', 'courier_receivable');
    $this->sslcommerz = $account('SSLCommerz', 'gateway', 'gateway_receivable');
    $this->bkash = $account('bKash Pay', 'gateway', 'gateway_receivable');

    $sales = Account::query()->where('type', 'income')->where('is_postable', true)->orderBy('code')->value('id');

    $this->post = function (MoneyAccount $money, int $minor, string $date) use ($sales) {
        static $n = 0;
        $n++;

        $journal = DB::table('journals')->insertGetId([
            'business_id' => $this->business->id, 'journal_number' => "JV-{$n}", 'journal_type' => 'manual',
            'journal_date' => $date, 'memo' => 'Collected',
            'posted_by' => $this->user->id, 'posted_at' => now(), 'created_at' => now(),
        ]);
        DB::table('journal_lines')->insert([
            ['journal_id' => $journal, 'business_id' => $this->business->id, 'line_no' => 1,
                'account_id' => $money->account_id, 'amount_minor' => $minor, 'money_account_id' => $money->id, 'created_at' => now()],
            ['journal_id' => $journal, 'business_id' => $this->business->id, 'line_no' => 2,
                'account_id' => $sales, 'amount_minor' => -$minor, 'money_account_id' => null, 'created_at' => now()],
        ]);
    };

    ($this->post)($this->pathao, 300_000, '2026-09-10');
    ($this->post)($this->steadfast, 500_000, '2026-09-12');
    ($this->post)($this->sslcommerz, 120_000, '2026-09-11');
    ($this->post)($this->bkash, 80_000, '2026-09-11');
});

afterEach(fn () => Carbon::setTestNow());

it('gives each courier account its own balance on the shared head', function () {
    expect(MoneyAvailability::balanceMinor($this->pathao))->toBe(300_000)
        ->and(MoneyAvailability::balanceMinor($this->steadfast))->toBe(500_000)
        // As at the 11th Steadfast had collected nothing yet.
        ->and(MoneyAvailability::balanceMinor($this->steadfast, Carbon::parse('2026-09-11')))->toBe(0);
});

it('gives each gateway account its own balance on the shared head', function () {
    expect(MoneyAvailability::balanceMinor($this->sslcommerz))->toBe(120_000)
        ->and(MoneyAvailability::balanceMinor($this->bkash))->toBe(80_000);
});

it('runs the lowest balance from a date over that courier\'s own lines', function () {
    // Pathao from the 1st: 0 until the 10th, then 3,000. Steadfast's 5,000 on
    // the 12th is not Pathao's and must not appear in the running balance.
    ($this->post)($this->pathao, -250_000, '2026-09-15');

    expect(MoneyAvailability::lowestFromMinor($this->pathao, Carbon::parse('2026-09-11')))->toBe(50_000);
});
