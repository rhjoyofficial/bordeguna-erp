<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\CashFlowForecast;
use App\Modules\Foundation\Mail\MorningBriefingMail;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Slice 0, D2: cash is what the business holds in cash, bank and MFS, never
 * what a courier or a payment gateway is holding for it.
 *
 * Courier (1145) and gateway (1146) accounts are money accounts too, so their
 * journal lines carry a money_account_id. The cockpit, the forecast's opening
 * cash and the morning briefing all summed "every line with a money account",
 * which counted cash on delivery a courier had not yet paid out as money in
 * the till. It is a receivable: it cannot pay anybody this morning.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-10 07:00:00');

    $this->business = Business::create(['name' => 'Cash Co', 'slug' => 'cash-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    (new ChartOfAccountsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    Permission::findOrCreate('accounting.view', 'web');
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo('accounting.view');

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    // 10,000 in the till and 3,000 a courier collected and has not paid out:
    // the business HOLDS 10,000.
    $moneyAccount = fn (string $name, string $type, string $slug) => DB::table('money_accounts')->insertGetId([
        'business_id' => $this->business->id, 'name' => $name, 'type' => $type,
        'account_id' => Account::bySlug($slug)->id, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $till = $moneyAccount('Till', 'cash', 'cash_in_hand');
    $courier = $moneyAccount('Pathao', 'courier', 'courier_receivable');
    $sales = Account::query()->where('type', 'income')->where('is_postable', true)->orderBy('code')->value('id');

    $journal = DB::table('journals')->insertGetId([
        'business_id' => $this->business->id, 'journal_number' => 'JV-1', 'journal_type' => 'manual',
        'journal_date' => '2026-09-05', 'memo' => 'Sales',
        'posted_by' => $this->owner->id, 'posted_at' => now(), 'created_at' => now(),
    ]);
    DB::table('journal_lines')->insert([
        ['journal_id' => $journal, 'business_id' => $this->business->id, 'line_no' => 1,
            'account_id' => Account::bySlug('cash_in_hand')->id, 'amount_minor' => 1_000_000, 'money_account_id' => $till, 'created_at' => now()],
        ['journal_id' => $journal, 'business_id' => $this->business->id, 'line_no' => 2,
            'account_id' => Account::bySlug('courier_receivable')->id, 'amount_minor' => 300_000, 'money_account_id' => $courier, 'created_at' => now()],
        ['journal_id' => $journal, 'business_id' => $this->business->id, 'line_no' => 3,
            'account_id' => $sales, 'amount_minor' => -1_300_000, 'money_account_id' => null, 'created_at' => now()],
    ]);
});

afterEach(fn () => Carbon::setTestNow());

it('shows cash on the cockpit without courier money, and courier money beside it', function () {
    $this->actingAs($this->owner)->withSession(['current_business_id' => $this->business->id])
        ->get(route('group.cockpit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('companies.0.cash', fn ($value) => (float) $value === 10000.0)
            ->where('companies.0.in_transit', fn ($value) => (float) $value === 3000.0)
            ->where('totals.cash', fn ($value) => (float) $value === 10000.0)
            ->where('totals.in_transit', fn ($value) => (float) $value === 3000.0));
});

it('opens the cash-flow forecast with cash only', function () {
    expect(CashFlowForecast::build(4)['opening_cash'])->toBe(10000.0);
});

it('tells the morning briefing the cash it holds, not what couriers hold', function () {
    Mail::fake();

    $this->artisan('erp:morning-briefing')->assertExitCode(0);

    Mail::assertQueued(MorningBriefingMail::class, fn (MorningBriefingMail $mail) => (float) $mail->figures['cash_now'] === 10000.0);
});
