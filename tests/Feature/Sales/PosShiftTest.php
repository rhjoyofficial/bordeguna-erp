<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Module 3, Slice 1 (POS spec §16-§18, §80-§82): the cashier shift.
 *
 * A shift is the accountability wrapper around the till: opened with a
 * counted float, fed by cash sales and till movements, closed with a count -
 * and the over/short is COMPUTED, never typed. The shift records custody; it
 * posts nothing itself. Money still moves only through the documents that
 * already move it, which is what keeps this slice honest.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Till Co', 'slug' => 'till-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Permission::firstOrCreate(['name' => 'sales.sell', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'sales.reports.view', 'guard_name' => 'web']);

    Role::firstOrCreate(['name' => 'Cashier', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['sales.sell']);
    Role::firstOrCreate(['name' => 'Bystander', 'business_id' => $this->business->id, 'guard_name' => 'web']);

    $this->cashier = User::factory()->create();
    $this->cashier->businesses()->attach($this->business->id);
    $this->cashier->assignRole('Cashier');

    $this->bystander = User::factory()->create();
    $this->bystander->businesses()->attach($this->business->id);
    $this->bystander->assignRole('Bystander');

    $gl = Account::create(['code' => '1110', 'name' => 'Cash in Hand', 'type' => 'asset', 'is_postable' => true]);
    $this->till = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'Counter Till', 'type' => 'cash', 'account_id' => $gl->id,
        'currency' => 'BDT', 'is_active' => true,
    ]);

    // Money into (positive) or out of (negative) the till, through the one
    // posting engine, exactly as a sale, a refund or a voucher reaches it.
    $sales = Account::create(['code' => '4101', 'name' => 'Product Sales (automatic)', 'type' => 'income', 'is_postable' => true]);
    $this->postToTill = fn (string $taka) => app(PostJournal::class)->handle(new JournalDraft(
        date: now(),
        lines: [
            JournalLineDraft::signed($gl->id, Money::ofTaka($taka), moneyAccount: $this->till),
            JournalLineDraft::signed($sales->id, Money::ofTaka($taka)->negated()),
        ],
    ), $this->cashier);
});

function asCashier()
{
    return test()->actingAs(test()->cashier)->withSession(['current_business_id' => test()->business->id]);
}

function openShift(float $float = 2000): int
{
    asCashier()->post(route('sales.shifts.open'), [
        'money_account_id' => test()->till->id,
        'opening_float' => $float,
    ])->assertSessionHasNoErrors();

    return (int) DB::table('pos_shifts')->latest('id')->value('id');
}

it('opens a shift with a counted float, and refuses a second on the same till', function () {
    openShift(2000);

    expect(DB::table('pos_shifts')->where('status', 'open')->count())->toBe(1);

    asCashier()->post(route('sales.shifts.open'), [
        'money_account_id' => $this->till->id,
        'opening_float' => 500,
    ])->assertSessionHasErrors('money_account_id');

    expect(DB::table('pos_shifts')->count())->toBe(1);
});

it('computes expected cash from float, cash sales and till movements - and the over/short from the count', function () {
    $shift = openShift(2000);

    // A cash sale of 1,500 lands in the till during the shift, as the ledger
    // records it: a line on the till stamped with the till (21 Sep 2026).
    ($this->postToTill)('1500');

    // 300 taken out for change; 100 put in.
    asCashier()->post(route('sales.shifts.movement', $shift), [
        'type' => 'cash_out', 'amount' => 300, 'reason' => 'Change for the market',
    ])->assertSessionHasNoErrors();
    asCashier()->post(route('sales.shifts.movement', $shift), [
        'type' => 'cash_in', 'amount' => 100, 'reason' => 'Owner top-up',
    ])->assertSessionHasNoErrors();

    // Close counting 3,250 against an expected 3,300: 50 short.
    asCashier()->post(route('sales.shifts.close', $shift), [
        'closing_count' => 3250,
    ])->assertSessionHasNoErrors();

    $closed = DB::table('pos_shifts')->find($shift);

    expect((float) $closed->expected_cash)->toBe(3300.0)
        ->and((float) $closed->over_short)->toBe(-50.0)
        ->and($closed->status)->toBe('closed');
});

it('refuses a close without a count, and a movement without a reason', function () {
    $shift = openShift();

    asCashier()->post(route('sales.shifts.close', $shift), [])
        ->assertSessionHasErrors('closing_count');

    asCashier()->post(route('sales.shifts.movement', $shift), ['type' => 'cash_out', 'amount' => 100])
        ->assertSessionHasErrors('reason');
});

it('shows the shift screen with the running expectation, and keeps outsiders off', function () {
    openShift(2000);

    asCashier()
        ->get(route('sales.shifts'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sales/shifts')
            ->where('open_shifts.0.opening_float', fn ($value) => (float) $value === 2000.0)
            ->where('open_shifts.0.expected_cash', fn ($value) => (float) $value === 2000.0)
            ->where('open_shifts.0.is_mine', true));

    test()->actingAs(test()->bystander)->withSession(['current_business_id' => test()->business->id])
        ->get(route('sales.shifts'))
        ->assertForbidden();
});

// ─── Accountant meeting 19 Sep 2026, F5: the difference is booked ─────────────

it('books a short till to Cash Over and Short when the shift closes', function () {
    $loss = Account::create([
        'code' => '6812', 'name' => 'Cash Over and Short', 'type' => 'expense', 'is_postable' => true, 'system_slug' => 'cash_over_short',
    ]);
    AccountMapping::assign('sales.cash_over_short', $loss, now()->parse('2000-01-01'), 'test');

    $shift = openShift(2000);
    asCashier()->post(route('sales.shifts.close', $shift), ['closing_count' => 1950])->assertSessionHasNoErrors();

    $closed = DB::table('pos_shifts')->find($shift);
    $lines = DB::table('journal_lines')->where('journal_id', $closed->over_short_journal_id)->pluck('amount_minor', 'account_id');

    // 50 short: the expense is debited and the till is credited.
    expect($closed->over_short_journal_id)->not->toBeNull()
        ->and((int) $lines[$loss->id])->toBe(5000)
        ->and((int) $lines[$this->till->account_id])->toBe(-5000);
});

it('still closes the shift when the books are not set up to take the difference', function () {
    $shift = openShift(2000);
    asCashier()->post(route('sales.shifts.close', $shift), ['closing_count' => 1950])->assertSessionHasNoErrors();

    $closed = DB::table('pos_shifts')->find($shift);

    expect($closed->status)->toBe('closed')->and($closed->over_short_journal_id)->toBeNull();
});

// ─── 21 Sep 2026: the ledger is the till's truth, and every till is shown ─────

it('counts money that LEFT the till, so a cash refund is not booked as a shortage', function () {
    $shift = openShift(2000);

    ($this->postToTill)('1500');   // a cash sale
    ($this->postToTill)('-400');   // a cash refund, or an expense paid from the drawer

    // The drawer holds 2,000 + 1,500 - 400 = 3,100, and so does the expectation.
    asCashier()->post(route('sales.shifts.close', $shift), ['closing_count' => 3100])->assertSessionHasNoErrors();

    $closed = DB::table('pos_shifts')->find($shift);

    expect((float) $closed->expected_cash)->toBe(3100.0)
        ->and((float) $closed->over_short)->toBe(0.0);
});

it('shows every open till, and offers only the tills that are not open', function () {
    $second = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Back Till', 'type' => 'cash',
        'account_id' => Account::create(['code' => '11102', 'name' => 'Back Till', 'type' => 'asset', 'is_postable' => true])->id,
    ]);
    $third = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Office Cash', 'type' => 'cash',
        'account_id' => Account::create(['code' => '11103', 'name' => 'Office Cash', 'type' => 'asset', 'is_postable' => true])->id,
    ]);

    openShift(2000);
    asCashier()->post(route('sales.shifts.open'), ['money_account_id' => $second->id, 'opening_float' => 500])->assertSessionHasNoErrors();

    asCashier()->get(route('sales.shifts'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('open_shifts', 2)
            ->where('tills', fn ($tills) => collect($tills)->pluck('id')->all() === [$third->id]));
});

it('will not open a shift on the cash box of another business or on a bank account', function () {
    $other = Business::create(['name' => 'Other', 'slug' => 'other']);
    $theirs = MoneyAccount::withoutGlobalScopes()->create(['business_id' => $other->id, 'name' => 'Their Till', 'type' => 'cash', 'account_id' => $this->till->account_id]);
    $bank = MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'BRAC', 'type' => 'bank', 'account_id' => $this->till->account_id]);

    foreach ([$theirs, $bank] as $account) {
        asCashier()->post(route('sales.shifts.open'), ['money_account_id' => $account->id, 'opening_float' => 1])
            ->assertSessionHasErrors('money_account_id');
    }
});
