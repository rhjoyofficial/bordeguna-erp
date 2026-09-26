<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Crm\database\seeders\CrmPermissionsSeeder;
use App\Modules\Foundation\database\seeders\ApprovalWorkflowSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Field sales take an order from a CRM customer; a Manager approves it
 * (Joy, 26 Sep 2026, Q21). The officer sees only the orders he took.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Field Co', 'slug' => 'field-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }
    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    (new WalkInCustomerSeeder)->run();
    (new SalesPermissionsSeeder)->run();
    (new CrmPermissionsSeeder)->run();
    (new ApprovalWorkflowSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN', 'is_default' => true]);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create(['business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id, 'sku' => 'SKU-HONEY', 'name' => 'Sundarban Honey 500g', 'type' => ProductType::FinishedGood]);
    $this->shop = Customer::create(['business_id' => $this->business->id, 'code' => 'SHP-001', 'name' => 'Corner Store', 'type' => CustomerType::Dealer, 'credit_limit' => '100000']);
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->product->id, 'unit_price' => '850', 'effective_from' => '2026-01-01']);
    Carbon::setTestNow('2026-09-26 10:00:00');

    $this->user = function (string $role): User {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $user;
    };

    app(RecordStockIn::class)->handle($this->product, $this->warehouse, '100', '500', User::factory()->create(), occurredAt: '2026-09-01 09:00:00');
});

afterEach(fn () => Carbon::setTestNow());

it('grants the field roles the right to record an order and nothing more of Sales', function () {
    $officer = ($this->user)('Sales Officer');
    $lead = ($this->user)('Sales Lead');

    foreach ([$officer, $lead] as $person) {
        expect($person->can('sales.orders.record'))->toBeTrue()
            ->and($person->can('sales.orders.see'))->toBeTrue()
            ->and($person->can('sales.reports.view'))->toBeFalse()
            ->and($person->can('sales.orders.approve'))->toBeFalse();
    }
});

it('lets an officer take an order that waits for a Manager, and shows him only his own orders', function () {
    $officer = ($this->user)('Sales Officer');
    $other = ($this->user)('Sales Officer');
    $manager = ($this->user)('Manager');

    $this->actingAs($officer)->post('/sales/orders', [
        'customer_id' => $this->shop->id,
        'lines' => [['product_id' => $this->product->id, 'quantity' => '10']],
    ])->assertRedirect();

    $mine = SalesOrder::firstOrFail();
    expect($mine->created_by)->toBe($officer->id)
        ->and($mine->approvalRequest->isPending())->toBeTrue()
        ->and((float) $mine->lines->first()->unit_price)->toBe(850.0);

    $this->actingAs($other)->post('/sales/orders', [
        'customer_id' => $this->shop->id,
        'lines' => [['product_id' => $this->product->id, 'quantity' => '5']],
    ])->assertRedirect();
    $theirs = SalesOrder::where('created_by', $other->id)->firstOrFail();

    // The list: his own only, with the note; the customer id preselects the form.
    $page = $this->actingAs($officer)->get('/sales/orders?new&customer='.$this->shop->id)->assertOk()->viewData('page')['props'];
    expect(collect($page['orders']['data'])->pluck('id')->all())->toBe([$mine->id])
        ->and($page['own_only'])->toBeTrue()
        ->and($page['preselect_customer'])->toBe($this->shop->id);

    // His own order opens; somebody else's is refused; the rest of Sales stays shut.
    $this->actingAs($officer)->get('/sales/orders/'.$mine->id)->assertOk();
    $this->actingAs($officer)->get('/sales/orders/'.$theirs->id)->assertForbidden();
    $this->actingAs($officer)->get('/sales/orders/'.$theirs->id.'/pick-list')->assertForbidden();
    $this->actingAs($officer)->get('/sales/customers')->assertForbidden();
    $this->actingAs($officer)->get('/sales/invoices')->assertForbidden();
    $this->actingAs($officer)->post('/sales/orders/'.$mine->id.'/approve')->assertForbidden();

    // The Manager sees both and approves the officer's.
    $all = $this->actingAs($manager)->get('/sales/orders')->assertOk()->viewData('page')['props'];
    expect(collect($all['orders']['data'])->pluck('id')->sort()->values()->all())->toBe([$mine->id, $theirs->id])
        ->and($all['own_only'])->toBeFalse();

    $this->actingAs($manager)->post('/sales/orders/'.$mine->id.'/approve')->assertSessionHas('success');
    expect($mine->fresh()->isDeliverable())->toBeTrue();
});
