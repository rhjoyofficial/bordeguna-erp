<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\AccountingPermissionsSeeder;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\database\seeders\InventoryPermissionsSeeder;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ WHO MAY READ WHAT (Joy, 26 Sep 2026, Q18 and Q19).
 *
 * Q18: Finance (the accountant) reads Sales, Purchasing and Inventory and
 * records customer receipts. Before this the accountant met a 403 on every
 * Sales page.
 *
 * Q19: the Manager reads the receivables ageing, a customer's statement and
 * the stock value, and nothing else of the ledger. Those three moved from the
 * `accounting.view` gate to `accounting.operations.view`, which the provider
 * composes so every ledger reader still passes.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Rights Co', 'slug' => 'rights-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Finance', 'Staff'] as $name) {
        Role::firstOrCreate(['name' => $name, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }

    $this->seed([
        AccountingPermissionsSeeder::class,
        SalesPermissionsSeeder::class,
        PurchasingPermissionsSeeder::class,
        InventoryPermissionsSeeder::class,
    ]);
    (new ChartOfAccountsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->as = function (string $role): User {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        $user->assignRole($role);

        return $user;
    };
});

it('lets Finance read Sales, Purchasing and Inventory and record a receipt, but not sell or pay', function () {
    $finance = Role::where('name', 'Finance')->where('business_id', $this->business->id)->firstOrFail();

    expect($finance->hasPermissionTo('sales.reports.view'))->toBeTrue()
        ->and($finance->hasPermissionTo('sales.receipt.record'))->toBeTrue()
        ->and($finance->hasPermissionTo('purchasing.reports.view'))->toBeTrue()
        ->and($finance->hasPermissionTo('inventory.reports.view'))->toBeTrue()
        ->and($finance->hasPermissionTo('sales.sell'))->toBeFalse()
        ->and($finance->hasPermissionTo('sales.prices.manage'))->toBeFalse()
        ->and($finance->hasPermissionTo('sales.credit.approve'))->toBeFalse()
        ->and($finance->hasPermissionTo('purchasing.payment.record'))->toBeFalse()
        ->and($finance->hasPermissionTo('purchasing.bill.record'))->toBeFalse()
        ->and($finance->hasPermissionTo('inventory.stock.record'))->toBeFalse()
        ->and($finance->hasPermissionTo('inventory.stock.adjust'))->toBeFalse();

    $user = ($this->as)('Finance');

    $this->actingAs($user)->get('/sales/customers')->assertOk();
    $this->actingAs($user)->get('/sales/invoices')->assertOk();
    $this->actingAs($user)->get('/sales/receipts')->assertOk();
    $this->actingAs($user)->get('/sales/receipts/new')->assertOk();
    $this->actingAs($user)->get('/purchasing/bills')->assertOk();
    $this->actingAs($user)->get('/inventory/stock')->assertOk();

    $this->actingAs($user)->get('/sales/counter')->assertForbidden();
    $this->actingAs($user)->get('/purchasing/payments/new')->assertForbidden();
});

it('lets the Manager read the receivables ageing, a customer statement and the stock value, and nothing else of the ledger', function () {
    $manager = Role::where('name', 'Manager')->where('business_id', $this->business->id)->firstOrFail();

    expect($manager->hasPermissionTo(AccountingPermissionsSeeder::OPERATIONS_VIEW))->toBeTrue()
        ->and($manager->hasPermissionTo('accounting.view'))->toBeFalse();

    $user = ($this->as)('Manager');

    $this->actingAs($user)->get('/accounting/receivables-ageing')->assertOk();
    $this->actingAs($user)->get('/accounting/customer-statement')->assertOk();
    $this->actingAs($user)->get('/accounting/inventory-valuation')->assertOk();

    // Margin, the accountant's ageing, and the ledger itself stay shut.
    $this->actingAs($user)->get('/accounting/cost-of-goods-sold')->assertForbidden();
    $this->actingAs($user)->get('/accounting/payables-ageing')->assertForbidden();
    $this->actingAs($user)->get('/accounting/trial-balance')->assertForbidden();
    $this->actingAs($user)->get('/accounting/general-ledger')->assertForbidden();
    $this->actingAs($user)->get('/accounting/vouchers/journal')->assertForbidden();

    // The sidebar flag the menu reads.
    $this->actingAs($user)->get('/sales/customers')->assertOk()
        ->assertInertia(fn ($page) => $page->where('canViewOperatingReports', true)->where('canViewAccounting', false));
});

it('keeps the three operating reports open to whoever reads the ledger, and shut to Staff', function () {
    $finance = ($this->as)('Finance');
    $owner = ($this->as)('Owner');
    $staff = ($this->as)('Staff');

    foreach (['/accounting/receivables-ageing', '/accounting/customer-statement', '/accounting/inventory-valuation'] as $url) {
        $this->actingAs($finance)->get($url)->assertOk();
        $this->actingAs($owner)->get($url)->assertOk();
        $this->actingAs($staff)->get($url)->assertForbidden();
    }
});
