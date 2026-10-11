<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Module 6, Slice 4: the group cockpit.
 *
 * One screen where the owner sees the group breathe: per company - cash
 * now, receivables, payables, this month's income and spend - and honest
 * group totals. Everything is read from each company's own books at view
 * time; nothing consolidated is ever stored. Until intercompany pairs
 * exist (slice 3, waiting on Joy), the totals are plain sums and the
 * screen says so.
 */
beforeEach(function () {
    $this->bdg = Business::create(['name' => 'Bor de Guna', 'slug' => 'bdg']);
    $this->lld = Business::create(['name' => 'LaLaDia', 'slug' => 'lld']);

    app(BusinessContext::class)->set($this->bdg->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->bdg->id);
    foreach ([$this->bdg, $this->lld] as $business) {
        Role::create(['name' => 'Owner', 'guard_name' => 'web', 'business_id' => $business->id]);
    }

    Carbon::setTestNow('2026-09-15 10:00:00');

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach([$this->bdg->id, $this->lld->id]);

    // ⚠ The chart of accounts is CENTRAL (no business_id) - one chart,
    // every company posts to it; journal_lines carry the business. Books
    // per company: one September sale each, LaLaDia half the size.
    // ⚠ The till's head sits under the Cash in Hand group: cash is read from
    // the cash, bank and MFS heads, never from "any line with a money account"
    // (courier and gateway money is not cash, Slice 0 D2).
    $cashGroup = DB::table('accounts')->insertGetId([
        'code' => '1110', 'name' => 'Cash in Hand', 'type' => 'asset', 'system_slug' => 'cash_accounts',
        'is_postable' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $cash = DB::table('accounts')->insertGetId([
        'code' => '11101', 'name' => 'Cash', 'type' => 'asset', 'parent_id' => $cashGroup,
        'is_postable' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $income = DB::table('accounts')->insertGetId([
        'code' => '4010', 'name' => 'Sales', 'type' => 'income',
        'is_postable' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ([[$this->bdg->id, 10000], [$this->lld->id, 5000]] as [$biz, $amount]) {
        $moneyAccount = DB::table('money_accounts')->insertGetId([
            'business_id' => $biz, 'name' => 'Till '.$biz, 'type' => 'cash', 'account_id' => $cash,
            'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $journal = DB::table('journals')->insertGetId([
            'business_id' => $biz, 'journal_number' => 'JV-'.$biz, 'journal_type' => 'manual',
            'journal_date' => '2026-09-10', 'memo' => 'Cash sale',
            'posted_by' => $this->owner->id, 'posted_at' => now(), 'created_at' => now(),
        ]);
        DB::table('journal_lines')->insert([
            ['journal_id' => $journal, 'business_id' => $biz, 'line_no' => 1,
                'account_id' => $cash, 'amount_minor' => $amount * 100, 'money_account_id' => $moneyAccount,
                'created_at' => now()],
            ['journal_id' => $journal, 'business_id' => $biz, 'line_no' => 2,
                'account_id' => $income, 'amount_minor' => -$amount * 100, 'money_account_id' => null,
                'created_at' => now()],
        ]);
    }

    $this->asOwner = fn () => $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->bdg->id]);
});

afterEach(fn () => Carbon::setTestNow());

it('shows each company breathing, and honest group totals', function () {
    ($this->asOwner)()->get(route('group.cockpit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('group/cockpit')
            ->has('companies', 2)
            ->where('companies.0.name', 'Bor de Guna')
            ->where('companies.0.cash', fn ($value) => (float) $value === 10000.0)
            ->where('companies.0.month_income', fn ($value) => (float) $value === 10000.0)
            ->where('companies.1.cash', fn ($value) => (float) $value === 5000.0)
            ->where('totals.cash', fn ($value) => (float) $value === 15000.0)
            ->where('totals.month_income', fn ($value) => (float) $value === 15000.0));
});

it('shows a limited viewer only their own company', function () {
    $limited = User::factory()->create();
    $limited->businesses()->attach($this->bdg->id);

    $this->actingAs($limited)->withSession(['current_business_id' => $this->bdg->id])
        ->get(route('group.cockpit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('companies', 1)
            ->where('totals.cash', fn ($value) => (float) $value === 10000.0));
});

it('shows money between our businesses on its own, cancelling across the group', function () {
    // Finance decision C2: BDG sent LaLaDia 3,000. BDG is owed it (1195),
    // LaLaDia owes it (2190); the trade columns do not move.
    $dueFrom = DB::table('accounts')->insertGetId(['code' => '1195', 'name' => 'Due from Other Businesses', 'type' => 'asset',
        'is_postable' => 1, 'is_active' => 1, 'system_slug' => 'due_from_businesses', 'created_at' => now(), 'updated_at' => now()]);
    $dueTo = DB::table('accounts')->insertGetId(['code' => '2190', 'name' => 'Due to Other Businesses', 'type' => 'liability',
        'is_postable' => 1, 'is_active' => 1, 'system_slug' => 'due_to_businesses', 'created_at' => now(), 'updated_at' => now()]);
    $cash = DB::table('accounts')->where('code', '11101')->value('id');

    foreach ([[$this->bdg->id, $dueFrom, 300_000], [$this->lld->id, $dueTo, -300_000]] as [$biz, $account, $minor]) {
        $journal = DB::table('journals')->insertGetId([
            'business_id' => $biz, 'journal_number' => 'IB-'.$biz, 'journal_type' => 'manual',
            'journal_date' => '2026-09-12', 'memo' => 'Inter-business transfer',
            'posted_by' => $this->owner->id, 'posted_at' => now(), 'created_at' => now(),
        ]);
        DB::table('journal_lines')->insert([
            ['journal_id' => $journal, 'business_id' => $biz, 'line_no' => 1, 'account_id' => $account, 'amount_minor' => $minor, 'money_account_id' => null, 'created_at' => now()],
            ['journal_id' => $journal, 'business_id' => $biz, 'line_no' => 2, 'account_id' => $cash, 'amount_minor' => -$minor, 'money_account_id' => null, 'created_at' => now()],
        ]);
    }

    ($this->asOwner)()->get(route('group.cockpit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('companies.0.intercompany', fn ($value) => (float) $value === 3000.0)
            ->where('companies.1.intercompany', fn ($value) => (float) $value === -3000.0)
            ->where('totals.intercompany', fn ($value) => (float) $value === 0.0)
            ->where('totals.receivables', fn ($value) => (float) $value === 0.0));
});
