<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The readiness check itself.
 *
 * ⚠⚠ The property that matters most is that it CHANGES NOTHING. It is written
 * to be run against production during the preparation week and again on cutover
 * morning, so a test that only proved it reports correctly would be missing the
 * point entirely.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Preflight Co', 'slug' => 'preflight-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Carbon::setTestNow('2026-09-01 08:00:00');
});

afterEach(fn () => Carbon::setTestNow());

it('refuses to guess which business to check', function () {
    $this->artisan('cutover:preflight')
        ->expectsOutputToContain('Say which business to check')
        ->expectsOutputToContain('preflight-co')
        ->assertExitCode(1);
});

it('refuses a business that does not exist', function () {
    $this->artisan('cutover:preflight --business=no-such-company')
        ->expectsOutputToContain('No active business matches')
        ->assertExitCode(1);
});

// ▶▶ The state Bor de Guna is actually in today: catalogue but no prices, no
// raw materials, nothing counted.
it('fails an unprepared business and says exactly why', function () {
    $this->artisan('cutover:preflight --business=preflight-co --date=2026-09-01')
        ->expectsOutputToContain('NOT ready')
        ->assertExitCode(1);
});

it('names the missing pieces rather than reporting a bare failure', function () {
    $unit = UnitOfMeasure::create([
        'business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs',
    ]);
    Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-UNPRICED',
        'name' => 'Mustard Oil 1L',
        'type' => ProductType::FinishedGood,
    ]);

    $this->artisan('cutover:preflight --business=preflight-co --date=2026-09-01')
        // The product that would refuse its first sale, named.
        ->expectsOutputToContain('SKU-UNPRICED')
        // Rehearsal finding F1, stated as a failure rather than a reminder.
        ->expectsOutputToContain('No active raw material products exist')
        ->assertExitCode(1);
});

it('passes a business that is genuinely ready', function () {
    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    (new WalkInCustomerSeeder)->run();
    (new SalesPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner'] as $name) {
        Role::firstOrCreate(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    Warehouse::create([
        'business_id' => $this->business->id, 'name' => 'Mirpur', 'code' => 'MDPUR', 'is_default' => true,
    ]);
    $unit = UnitOfMeasure::create([
        'business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs',
    ]);

    $honey = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-HONEY',
        'name' => 'Sundarban Honey 500g',
        'type' => ProductType::FinishedGood,
    ]);
    Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-BOTTLE',
        'name' => 'Bottle 500ml',
        'type' => ProductType::RawMaterial,
    ]);

    ProductPrice::create([
        'business_id' => $this->business->id,
        'product_id' => $honey->id,
        'unit_price' => '850',
        'effective_from' => '2026-01-01',
    ]);

    PriceGroup::firstOrCreate([
        'business_id' => $this->business->id, 'code' => 'RETAIL',
    ], ['name' => 'Retail', 'is_active' => true, 'prices_include_tax' => false]);

    Customer::query()->update(['price_group_id' => PriceGroup::first()->id]);

    MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'Till',
        'type' => 'cash',
        'account_id' => Account::bySlug('cash_in_hand')->id,
    ]);

    $owner = User::factory()->create();
    $owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $owner->assignRole('Owner');
    $owner->update(['is_super_admin' => true]);

    $this->artisan('cutover:preflight --business=preflight-co --date=2026-09-01')
        ->expectsOutputToContain('Ready for the first real purchase and sale cycle')
        ->assertExitCode(0);
});

// ⚠⚠ THE POINT. Safe to run against production, twice, on the worst morning of
// the year.
it('changes absolutely nothing in the database', function () {
    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    Warehouse::create([
        'business_id' => $this->business->id, 'name' => 'Mirpur', 'code' => 'MDPUR',
    ]);
    $unit = UnitOfMeasure::create([
        'business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs',
    ]);
    Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-HONEY',
        'name' => 'Sundarban Honey 500g',
        'type' => ProductType::FinishedGood,
    ]);

    $before = [
        'products' => Product::withoutGlobalScopes()->count(),
        'warehouses' => Warehouse::withoutGlobalScopes()->count(),
        'movements' => StockMovement::withoutGlobalScopes()->count(),
        'journal_lines' => JournalLine::withoutGlobalScopes()->count(),
        'accounts' => Account::withoutGlobalScopes()->count(),
        'prices' => ProductPrice::withoutGlobalScopes()->count(),
        'customers' => Customer::withoutGlobalScopes()->count(),
        'money_accounts' => MoneyAccount::withoutGlobalScopes()->count(),
    ];

    $this->artisan('cutover:preflight --business=preflight-co --date=2026-09-01');
    $this->artisan('cutover:preflight --business=preflight-co --date=2026-09-01');

    $after = [
        'products' => Product::withoutGlobalScopes()->count(),
        'warehouses' => Warehouse::withoutGlobalScopes()->count(),
        'movements' => StockMovement::withoutGlobalScopes()->count(),
        'journal_lines' => JournalLine::withoutGlobalScopes()->count(),
        'accounts' => Account::withoutGlobalScopes()->count(),
        'prices' => ProductPrice::withoutGlobalScopes()->count(),
        'customers' => Customer::withoutGlobalScopes()->count(),
        'money_accounts' => MoneyAccount::withoutGlobalScopes()->count(),
    ];

    expect($after)->toBe($before);
});

// ⚠ The tenant-restoring habit the verifiers already document. This command
// enters a business to look around, and a caller standing somewhere else must
// still be standing there afterwards.
it('leaves the caller in the business it found them in', function () {
    $other = Business::create(['name' => 'Somewhere Else', 'slug' => 'somewhere-else']);
    app(BusinessContext::class)->set($other->id);

    $this->artisan('cutover:preflight --business=preflight-co --date=2026-09-01');

    expect(app(BusinessContext::class)->id())->toBe($other->id);
});

// ⚠ C-INV-E2 in Accounting: the chart screen renders code/name/type/parent_id and
// delete is a bare button, so a refusal under `account` was never once displayed.
it('flashes an error when a system account cannot be deleted', function () {
    (new ChartOfAccountsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    $owner = User::factory()->create();
    $owner->businesses()->attach($this->business->id);
    $owner->update(['is_super_admin' => true]);

    $stock = Account::bySlug('stock_on_hand');

    $response = $this->actingAs($owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->delete(route('accounting.accounts.destroy', $stock));

    $response->assertRedirect();
    expect(session('error'))->toContain('used by the system itself')
        ->and(Account::bySlug('stock_on_hand'))->not->toBeNull();
});
