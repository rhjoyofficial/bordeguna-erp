<?php

use App\Models\User;
use App\Modules\Accounting\Models\Account;
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

    // A cash sale of 1,500 lands in the till during the shift.
    DB::table('customer_receipts')->insert([
        'business_id' => $this->business->id,
        'customer_id' => DB::table('customers')->insertGetId([
            'business_id' => $this->business->id, 'code' => 'CUS-0001', 'name' => 'Walk-in', 'type' => 'walk_in',
            'created_at' => now(), 'updated_at' => now(),
        ]),
        'money_account_id' => $this->till->id,
        'received_on' => now()->toDateString(),
        'amount' => 1500,
        'created_by' => $this->cashier->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

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
            ->where('open_shift.opening_float', fn ($value) => (float) $value === 2000.0)
            ->where('open_shift.expected_cash', fn ($value) => (float) $value === 2000.0));

    test()->actingAs(test()->bystander)->withSession(['current_business_id' => test()->business->id])
        ->get(route('sales.shifts'))
        ->assertForbidden();
});
