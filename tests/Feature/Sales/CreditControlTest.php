<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\ApproveSalesOrder;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Exceptions\CreditRefused;
use App\Modules\Sales\Models\CreditOverride;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\ProductPrice;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The credit limit blocks and a distributor pays first (Joy, 21 Sep 2026:
 * "Block, Owner can override"). Checked at approval, the one door every order
 * passes; an Owner's written reason sets it aside, and that is recorded.
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

    $warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAVAR']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-HONEY', 'name' => 'Sundarban Honey 500g', 'type' => ProductType::FinishedGood,
    ]);

    Carbon::setTestNow('2026-09-21 10:00:00');
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
});

afterEach(fn () => Carbon::setTestNow());

it('approves an order within the credit limit', function () {
    $order = ($this->orderFor)(($this->customerWith)('5000'));

    app(ApproveSalesOrder::class)->handle($order, $this->manager);

    expect($order->fresh()->approvalRequest->status)->toBe(ApprovalStatus::Approved);
});

it('refuses an order that takes the customer over the limit, and approves nothing', function () {
    $order = ($this->orderFor)(($this->customerWith)('2000'));

    expect(fn () => app(ApproveSalesOrder::class)->handle($order, $this->manager))
        ->toThrow(CreditRefused::class, 'over their credit limit of 2,000.00');

    expect($order->fresh()->approvalRequest->isPending())->toBeTrue();
});

it('counts what the customer already owes on approved orders', function () {
    $customer = ($this->customerWith)('5000');
    app(ApproveSalesOrder::class)->handle(($this->orderFor)($customer), $this->manager);

    // 3,000 approved and undelivered + 3,000 more = 6,000 > 5,000.
    expect(fn () => app(ApproveSalesOrder::class)->handle(($this->orderFor)($customer), $this->manager))
        ->toThrow(CreditRefused::class);
});

it('approves the approver own order within the rules, and holds it for an Owner outside them', function () {
    $within = app(CreateSalesOrder::class)->handle(customer: ($this->customerWith)('5000'), lines: [['product' => $this->product, 'quantity' => '3']], createdBy: $this->manager);
    $over = app(CreateSalesOrder::class)->handle(customer: ($this->customerWith)('1000'), lines: [['product' => $this->product, 'quantity' => '3']], createdBy: $this->manager);

    expect($within->approvalRequest->status)->toBe(ApprovalStatus::Approved)
        ->and($over->approvalRequest->isPending())->toBeTrue();
});

it('checks nobody with no limit set, and gives no credit at all with a limit of 0', function () {
    app(ApproveSalesOrder::class)->handle(($this->orderFor)(($this->customerWith)(null)), $this->manager);

    expect(fn () => app(ApproveSalesOrder::class)->handle(($this->orderFor)(($this->customerWith)('0')), $this->manager))
        ->toThrow(CreditRefused::class);
});

it('refuses a distributor order that is not paid in advance, whatever the limit', function () {
    $order = ($this->orderFor)(($this->customerWith)(null, CustomerType::Distributor));

    expect(fn () => app(ApproveSalesOrder::class)->handle($order, $this->manager))
        ->toThrow(CreditRefused::class, 'distributors pay in advance');
});

it('lets an Owner approve it anyway with a reason, and records exactly what was set aside', function () {
    $order = ($this->orderFor)(($this->customerWith)('2000'));

    app(ApproveSalesOrder::class)->handle($order, $this->owner, 'Cheque for the balance promised on the 25th');

    $override = CreditOverride::sole();

    expect($order->fresh()->approvalRequest->status)->toBe(ApprovalStatus::Approved)
        ->and($override->rule)->toBe('credit_limit')
        ->and((string) $override->credit_limit)->toBe('2000.00')
        ->and((string) $override->owed_before)->toBe('0.00')
        ->and((string) $override->amount)->toBe('3000.00')
        ->and((string) $override->owed_after)->toBe('3000.00')
        ->and($override->reason)->toBe('Cheque for the balance promised on the 25th')
        ->and($override->overridden_by)->toBe($this->owner->id);

    expect(fn () => $override->update(['reason' => 'changed']))->toThrow(LogicException::class);
});

it('does not let a reason alone, or the permission alone, set the rules aside', function () {
    $order = ($this->orderFor)(($this->customerWith)('2000'));

    expect(fn () => app(ApproveSalesOrder::class)->handle($order, $this->manager, 'I say so'))->toThrow(CreditRefused::class)
        ->and(fn () => app(ApproveSalesOrder::class)->handle($order, $this->owner, '   '))->toThrow(CreditRefused::class);

    expect(CreditOverride::count())->toBe(0);
});

it('refuses from the Approvals button with the sentence, and the order page says why before anybody presses it', function () {
    $order = ($this->orderFor)(($this->customerWith)('2000'));

    $this->actingAs($this->manager)->withHeader('X-Business-Id', (string) $this->business->id)
        ->post(route('sales.orders.approve', $order))
        ->assertSessionHas('error');

    $this->actingAs($this->owner)->get(route('sales.orders.show', $order))
        ->assertInertia(fn (Assert $page) => $page
            ->where('credit_refusal', fn ($message) => str_contains($message, 'credit limit of 2,000.00'))
            ->where('can_override_credit', true));

    $this->actingAs($this->owner)->withHeader('X-Business-Id', (string) $this->business->id)
        ->post(route('sales.orders.approve', $order), ['credit_override_reason' => 'Long-standing customer'])
        ->assertSessionHas('success');

    expect(CreditOverride::count())->toBe(1);
});
