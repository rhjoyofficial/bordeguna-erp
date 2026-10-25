<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\ApproveSalesOrder;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\Actions\RecordCustomerAdvance;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Exceptions\CreditRefused;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\ProductPrice;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * IP21 (25 Oct 2026): the customer's open advance with us (2150, CADV
 * documents) is credit they already have. A customer whose advance covers the
 * order is not refused, and the credit box says "advance with us".
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Credit Co', 'slug' => 'credit-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    foreach (['sales.orders.approve', 'sales.orders.record', 'sales.credit.override', 'sales.reports.view'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    $owner = Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $owner->givePermissionTo(['sales.orders.approve', 'sales.orders.record', 'sales.credit.override', 'sales.reports.view']);
    $manager = Role::firstOrCreate(['name' => 'Manager', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $manager->givePermissionTo(['sales.orders.approve', 'sales.orders.record', 'sales.reports.view']);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');
    $this->manager = User::factory()->create();
    $this->manager->businesses()->attach($this->business->id);
    $this->manager->assignRole('Manager');

    // ⚠ Orders are taken by somebody who may not approve them, so they wait;
    // an approver's own order approves itself when the credit rules allow.
    $clerk = Role::firstOrCreate(['name' => 'Staff', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $clerk->givePermissionTo(['sales.orders.record']);
    $this->clerk = User::factory()->create();
    $this->clerk->businesses()->attach($this->business->id);
    $this->clerk->assignRole('Staff');

    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'BRAC Bank', 'type' => 'bank', 'is_active' => true,
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);

    $warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAVAR']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-HONEY', 'name' => 'Sundarban Honey 500g', 'type' => ProductType::FinishedGood,
    ]);

    Carbon::setTestNow('2026-09-21 10:00:00');
    // ⭐ B17 (25 Oct 2026, Q4 = A): approving holds stock, so there is some.
    app(RecordStockIn::class)->handle($this->product, $warehouse, '1000', '500', $this->owner, occurredAt: '2026-09-01 09:00:00');
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->product->id, 'unit_price' => '1000', 'effective_from' => '2026-09-01']);

    $this->customerWith = fn (?string $limit, CustomerType $type = CustomerType::Retail) => Customer::create([
        'business_id' => $this->business->id, 'name' => 'Rahim Traders', 'type' => $type, 'credit_limit' => $limit,
    ]);

    // 3 x 1,000 = 3,000 taka, pending approval.
    $this->orderFor = fn (Customer $customer, string $qty = '3') => app(CreateSalesOrder::class)->handle(
        customer: $customer,
        lines: [['product' => $this->product, 'quantity' => $qty]],
        createdBy: $this->clerk,
        warehouse: $warehouse,
    );

    $this->advance = fn (Customer $customer, string $amount) => app(RecordCustomerAdvance::class)
        ->receive($customer, $this->bank, '2026-09-20', $amount, $this->owner, 'bKash TX1');
});

afterEach(fn () => Carbon::setTestNow());

it('approves a cash-only trade customer whose advance covers the order', function () {
    $shop = ($this->customerWith)(null, CustomerType::Shop);

    expect(fn () => app(ApproveSalesOrder::class)->handle(($this->orderFor)($shop), $this->manager))
        ->toThrow(CreditRefused::class, 'cash only');

    ($this->advance)($shop, '5000');
    $order = ($this->orderFor)($shop);
    app(ApproveSalesOrder::class)->handle($order, $this->manager);

    expect($order->fresh()->approvalRequest->status)->toBe(ApprovalStatus::Approved);
});

it('approves a distributor whose advance covers the order, and refuses one whose advance falls short', function () {
    $covered = ($this->customerWith)(null, CustomerType::Distributor);
    ($this->advance)($covered, '3000');
    $order = ($this->orderFor)($covered);
    app(ApproveSalesOrder::class)->handle($order, $this->manager);

    $short = Customer::create(['business_id' => $this->business->id, 'name' => 'Short Distributors', 'type' => CustomerType::Distributor]);
    ($this->advance)($short, '1000');

    expect($order->fresh()->approvalRequest->status)->toBe(ApprovalStatus::Approved)
        ->and(fn () => app(ApproveSalesOrder::class)->handle(($this->orderFor)($short), $this->manager))
        ->toThrow(CreditRefused::class, 'Their advance with us of 1,000.00 is already counted.');
});

it('takes the advance off what they would owe before comparing with the limit', function () {
    // Limit 2,000; order 3,000; advance 1,500 -> 1,500 net, within.
    $within = ($this->customerWith)('2000');
    ($this->advance)($within, '1500');
    $order = ($this->orderFor)($within);
    app(ApproveSalesOrder::class)->handle($order, $this->manager);

    // Advance 500 -> 2,500 net, over.
    $over = Customer::create(['business_id' => $this->business->id, 'name' => 'Over Traders', 'type' => CustomerType::Retail, 'credit_limit' => '2000']);
    ($this->advance)($over, '500');

    expect($order->fresh()->approvalRequest->status)->toBe(ApprovalStatus::Approved)
        ->and(fn () => app(ApproveSalesOrder::class)->handle(($this->orderFor)($over), $this->manager))
        ->toThrow(CreditRefused::class, 'over their credit limit of 2,000.00');
});

it('shows the advance with us in the credit box on the form and on the order', function () {
    $shop = ($this->customerWith)(null, CustomerType::Shop);
    ($this->advance)($shop, '5000');
    $order = ($this->orderFor)($shop);

    $this->actingAs($this->owner)->withHeader('X-Business-Id', (string) $this->business->id)
        ->postJson(route('sales.orders.exposure'), ['customer_id' => $shop->id])
        ->assertOk()
        ->assertJsonPath('advance_with_us', '5000.00')
        ->assertJsonPath('cash_only', true);

    $this->actingAs($this->owner)->get(route('sales.orders.show', $order))
        ->assertInertia(fn (Assert $page) => $page
            ->where('exposure.advance_with_us', '5000.00')
            ->where('credit_refusal', null));
});
