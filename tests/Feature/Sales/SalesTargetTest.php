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

it('counts sales without VAT and less what customers returned this month', function () {
    ($this->asOwner)()->post(route('sales.targets.store'), [
        'user_id' => $this->seller->id, 'month' => '2026-09',
        'target_amount' => '100000', 'commission_percent' => '2',
    ])->assertSessionHasNoErrors();

    // 10,000 of goods + 1,500 VAT.
    $invoiceId = DB::table('sales_invoices')->insertGetId([
        'business_id' => $this->business->id, 'customer_id' => $this->customer->id,
        'invoice_number' => 'INV-VAT-1', 'invoice_date' => '2026-09-05',
        'subtotal' => 10000, 'invoice_discount' => 0, 'tax_total' => 1500, 'total' => 11500,
        'created_by' => $this->seller->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    // 2,000 of it came back on 12 Sep. Only the link rows matter to the
    // target, so the delivery side is left as bare ids (SQLite defers the
    // foreign keys to a commit the test never makes; a pragma that switches
    // them off is ignored inside the test transaction). MySQL has no deferral,
    // so its session check is switched off for these inserts and back on after.
    $mysql = DB::connection()->getDriverName() !== 'sqlite';
    DB::statement($mysql ? 'SET FOREIGN_KEY_CHECKS = 0' : 'PRAGMA defer_foreign_keys = ON');
    $lineId = DB::table('sales_invoice_lines')->insertGetId([
        'business_id' => $this->business->id, 'sales_invoice_id' => $invoiceId,
        'description' => 'Honey', 'quantity' => 10, 'unit_price' => 1000, 'line_net' => 10000,
        'tax_rate' => 15, 'tax_amount' => 1500, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $returnId = DB::table('sales_returns')->insertGetId([
        'business_id' => $this->business->id, 'customer_id' => $this->customer->id,
        'delivery_id' => 999, 'return_number' => 'SR-1', 'returned_at' => '2026-09-12',
        'credit_total' => 2300, 'created_by' => $this->owner->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $returnLineId = DB::table('sales_return_lines')->insertGetId([
        'business_id' => $this->business->id, 'sales_return_id' => $returnId,
        'delivery_line_id' => 999, 'quantity' => 2, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('sales_return_invoice_lines')->insert([
        'business_id' => $this->business->id, 'sales_return_line_id' => $returnLineId,
        'sales_invoice_line_id' => $lineId, 'quantity' => 2, 'gross' => 2000, 'discount' => 0,
        'net' => 2000, 'tax' => 300, 'created_at' => now(), 'updated_at' => now(),
    ]);
    if ($mysql) {
        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
    }

    ($this->asOwner)()->get(route('sales.targets', ['month' => '2026-09']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.actual', fn ($value) => (float) $value === 8000.0)
            ->where('rows.0.commission', fn ($value) => (float) $value === 160.0));
});

it('keeps the first-set time when a target is set again, and only sets one for a member of the business', function () {
    ($this->asOwner)()->post(route('sales.targets.store'), [
        'user_id' => $this->seller->id, 'month' => '2026-09', 'target_amount' => '1000',
    ])->assertSessionHasNoErrors();
    Carbon::setTestNow('2026-09-20 10:00:00');
    ($this->asOwner)()->post(route('sales.targets.store'), [
        'user_id' => $this->seller->id, 'month' => '2026-09', 'target_amount' => '2000',
    ])->assertSessionHasNoErrors();

    $target = DB::table('sales_targets')->first();
    expect((string) $target->created_at)->toStartWith('2026-09-15')
        ->and((string) $target->updated_at)->toStartWith('2026-09-20');

    $outsider = User::factory()->create();
    ($this->asOwner)()->post(route('sales.targets.store'), [
        'user_id' => $outsider->id, 'month' => '2026-09', 'target_amount' => '1000',
    ])->assertSessionHasErrors('user_id');
});

// Setting a target is a popup over the table since 14 Oct 2026 (the form no
// longer sits open above it). The page carries what the popup draws - the
// month it opens on and the people it offers - and the popup saves through
// the same route with the boxes it sends, a blank commission included.
it('carries what the add-target popup needs on the table, and saves through the same route', function () {
    ($this->asOwner)()->get(route('sales.targets', ['month' => '2026-10']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sales/targets')
            ->where('month', '2026-10')
            ->where('users', fn ($users) => collect($users)->pluck('id')->map(fn ($id) => (int) $id)->contains($this->seller->id)));

    ($this->asOwner)()->post(route('sales.targets.store'), [
        'user_id' => (string) $this->seller->id,
        'month' => '2026-10',
        'target_amount' => '50000',
        'commission_percent' => '',
    ])->assertRedirect()->assertSessionHasNoErrors();

    ($this->asOwner)()->get(route('sales.targets', ['month' => '2026-10']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.user', $this->seller->name)
            ->where('rows.0.target_amount', fn ($value) => (float) $value === 50000.0)
            ->where('rows.0.commission_percent', fn ($value) => (float) $value === 0.0));
});
