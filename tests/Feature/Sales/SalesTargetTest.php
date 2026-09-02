<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Module 4, Slice 5 (CRM spec §39-§40): sales targets + commission, phase 1.
 *
 * CALCULATION ONLY, and proudly so: the screen shows target vs the invoices
 * a person actually wrote and what the commission WOULD be. Paying it stays
 * with payroll, by a human hand - a screen that computed money into someone's
 * salary by itself would be a second payroll.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Target Co', 'slug' => 'target-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }
    (new SalesPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Carbon::setTestNow('2026-09-15 10:00:00');

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    $this->seller = User::factory()->create();
    $this->seller->businesses()->attach($this->business->id);

    $this->customer = Customer::create([
        'business_id' => $this->business->id,
        'code' => 'CUS-0001', 'name' => 'Rahim Traders', 'type' => 'dealer', 'is_active' => true,
    ]);

    $this->asOwner = fn () => $this->actingAs($this->owner)
        ->withHeader('X-Business-Id', (string) $this->business->id);
});

afterEach(fn () => Carbon::setTestNow());

function invoiceFor(User $seller, string $date, float $total): void
{
    DB::table('sales_invoices')->insert([
        'business_id' => test()->business->id, 'customer_id' => test()->customer->id,
        'invoice_number' => 'INV-'.uniqid(),
        'invoice_date' => $date,
        'subtotal' => $total, 'invoice_discount' => 0, 'tax_total' => 0, 'total' => $total,
        'created_by' => $seller->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('shows target vs the invoices the person actually wrote, and the would-be commission', function () {
    ($this->asOwner)()->post(route('sales.targets.store'), [
        'user_id' => $this->seller->id,
        'month' => '2026-09',
        'target_amount' => '100000',
        'commission_percent' => '2',
    ])->assertSessionHasNoErrors();

    invoiceFor($this->seller, '2026-09-05', 60000);
    invoiceFor($this->seller, '2026-09-10', 20000);
    // Another month and another person stay out of it.
    invoiceFor($this->seller, '2026-08-20', 999999);
    invoiceFor($this->owner, '2026-09-08', 50000);

    ($this->asOwner)()->get(route('sales.targets', ['month' => '2026-09']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sales/targets')
            ->has('rows', 1)
            ->where('rows.0.target_amount', fn ($value) => (float) $value === 100000.0)
            ->where('rows.0.actual', fn ($value) => (float) $value === 80000.0)
            ->where('rows.0.achievement_percent', fn ($value) => (float) $value === 80.0)
            // 2% of 80,000 - what payroll WOULD pay, never what it does.
            ->where('rows.0.commission', fn ($value) => (float) $value === 1600.0));
});

it('replaces a month-and-person target instead of stacking a second one', function () {
    foreach (['100000', '120000'] as $amount) {
        ($this->asOwner)()->post(route('sales.targets.store'), [
            'user_id' => $this->seller->id, 'month' => '2026-09',
            'target_amount' => $amount, 'commission_percent' => '2',
        ])->assertSessionHasNoErrors();
    }

    expect(DB::table('sales_targets')->count())->toBe(1)
        ->and((float) DB::table('sales_targets')->value('target_amount'))->toBe(120000.0);
});

it('keeps the screen off limits without the sales permission', function () {
    $bystander = User::factory()->create();
    $bystander->businesses()->attach($this->business->id);

    $this->actingAs($bystander)->withHeader('X-Business-Id', (string) $this->business->id)
        ->get(route('sales.targets'))
        ->assertForbidden();
});
