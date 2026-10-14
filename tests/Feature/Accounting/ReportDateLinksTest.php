<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * FEA-008 (audit of 14 Oct 2026): a report opened from a link with a date
 * that is not a date (`from=abc`, `from=2026-13-40`) threw from Carbon::parse
 * and showed an error page. It is a validation message now, on every report.
 */
beforeEach(function () {
    $this->withoutVite();
    $this->business = Business::create(['name' => 'Report Co', 'slug' => 'report-co', 'fiscal_year_start_month' => 7]);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['accounting.view', 'accounting.operations.view'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $role = Role::firstOrCreate(['name' => 'Reader', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $role->givePermissionTo(['accounting.view', 'accounting.operations.view']);
    $this->reader = User::factory()->create();
    $this->reader->businesses()->attach($this->business->id);
    $this->reader->assignRole($role);

    $this->seed(ChartOfAccountsSeeder::class);
});

dataset('report links', [
    'profit and loss' => ['/accounting/income-statement', 'from'],
    'balance sheet' => ['/accounting/balance-sheet', 'as_at'],
    'cash flow' => ['/accounting/cash-flow', 'from'],
    'receivables ageing' => ['/accounting/receivables-ageing', 'as_at'],
    'payables ageing' => ['/accounting/payables-ageing', 'as_at'],
    'customer statement' => ['/accounting/customer-statement', 'from'],
    'supplier statement' => ['/accounting/supplier-statement', 'from'],
    'employee statement' => ['/accounting/employee-statement', 'from'],
    'cash and bank book' => ['/accounting/cash-and-bank', 'from'],
    'general ledger' => ['/accounting/general-ledger', 'from'],
    'trial balance' => ['/accounting/trial-balance', 'to'],
    'inventory valuation' => ['/accounting/inventory-valuation', 'as_at'],
    'cost of goods sold' => ['/accounting/cost-of-goods-sold', 'from'],
]);

it('answers a report link with a broken date with a message, never an error page', function (string $url, string $key) {
    foreach (['abc', '2026-13-40'] as $bad) {
        $this->actingAs($this->reader)->from('/dashboard')
            ->get($url.'?'.$key.'='.$bad)
            ->assertRedirect('/dashboard')
            ->assertSessionHasErrors($key);
    }

    // A good date still opens the report.
    $this->actingAs($this->reader)->get($url.'?'.$key.'=2026-09-30')->assertOk();
})->with('report links');
