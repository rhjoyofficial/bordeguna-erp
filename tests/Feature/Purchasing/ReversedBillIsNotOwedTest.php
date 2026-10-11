<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Support\Ageing;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Slice 0, D1: a reversed supplier bill is not owed and was not a purchase.
 *
 * ReverseSupplierBill refuses a bill that has been paid or credited, so a
 * reversed bill never has money against it: leaving it out is the whole fix,
 * with nothing to net. The group cockpit, the cash-flow forecast and the
 * subledger reconciliation already left reversed bills out; the payables
 * ageing (and the finance dashboard's "owed by us", which reads it) and the
 * main dashboard did not.
 *
 * ⚠ The reversal journal is dated the day of the reversal, so an ageing as at
 * an EARLIER date must still show the bill: that is what the control account
 * said on that date, and the tie-out depends on it.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-20 10:00:00');

    $this->business = Business::create(['name' => 'Reversed Co', 'slug' => 'reversed-co', 'fiscal_year_start_month' => 7]);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    (new ChartOfAccountsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    foreach (['accounting.view', 'purchasing.reports.view'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $role = Role::firstOrCreate(['name' => 'Reader', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $role->givePermissionTo(['accounting.view', 'purchasing.reports.view']);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);
    $this->user->assignRole($role);

    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Dhaka Glassware']);

    $this->bill = fn (string $number, string $total, ?string $reversedAt = null) => SupplierBill::create([
        'business_id' => $this->business->id,
        'supplier_id' => $this->supplier->id,
        'bill_number' => $number,
        'bill_date' => '2026-09-01',
        'due_date' => '2026-09-10',
        'total' => $total,
        'created_by' => $this->user->id,
        'reversed_at' => $reversedAt,
        'reversed_by' => $reversedAt === null ? null : $this->user->id,
    ]);

    ($this->bill)('SB-KEPT', '1000');
    ($this->bill)('SB-REVERSED', '5000', '2026-09-15 00:00:00');
});

afterEach(fn () => Carbon::setTestNow());

it('leaves a reversed bill out of the payables ageing from the day it was reversed', function () {
    expect(Ageing::payables(Carbon::parse('2026-09-20'))->total()->minor)->toBe(100000)
        ->and(Ageing::payables(Carbon::parse('2026-09-15'))->total()->minor)->toBe(100000);
});

it('still shows a reversed bill as owed on a date before its reversal', function () {
    // ⚠ The bill really was owed on the 10th; the reversal journal is dated the 15th.
    expect(Ageing::payables(Carbon::parse('2026-09-10'))->total()->minor)->toBe(600000);
});

it('does not count a reversed bill as owed by us on the finance dashboard', function () {
    $this->actingAs($this->user)
        ->get('/accounting/overview')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('owed.by_us', fn ($value) => (float) $value === 1000.0));
});

it('does not count a reversed bill as a purchase on the dashboard', function () {
    $this->actingAs($this->user)
        // The old dashboard lives on at /dashboard/classic since the role dashboards (11 Oct 2026).
        ->get('/dashboard/classic')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('total_purchases', '1,000.00')
            ->where('trade_chart', fn ($chart) => (float) collect($chart)->sum('purchases') === 1000.0));
});
