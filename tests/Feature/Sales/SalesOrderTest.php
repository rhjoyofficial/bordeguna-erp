<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Exceptions\NoSellingPriceException;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sales orders slice 1: the recorded promise.
 *
 * ⚠⚠ AN ORDER MOVES NO STOCK AND POSTS NO JOURNAL, EVER. The tests here that
 * assert zero journals and zero movements are the architecture, not incidental
 * assertions. See SALES-ORDER-ARCHITECTURE.md §2, §7.
 *
 * ⚠ Approval mirrors the PO (C-SO-1): Owner/Manager self-approve, Staff-created
 * orders sit pending, and isDeliverable() is false until approved.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Order Co', 'slug' => 'order-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    Permission::firstOrCreate(['name' => 'sales.orders.approve', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'sales.orders.record', 'guard_name' => 'web']);

    foreach (['Owner', 'Staff'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }
    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()
        ->givePermissionTo(['sales.orders.approve', 'sales.orders.record']);
    Role::where('business_id', $this->business->id)->where('name', 'Staff')->first()
        ->givePermissionTo(['sales.orders.record']);

    $this->warehouse = Warehouse::create([
        'business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAVAR',
    ]);
    $unit = UnitOfMeasure::create([
        'business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs',
    ]);
    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-HONEY',
        'name' => 'Sundarban Honey 500g',
        'type' => ProductType::FinishedGood,
    ]);
    $this->customer = Customer::create([
        'business_id' => $this->business->id,
        'name' => 'Karim Traders',
        // A dealer, not a distributor: since 21 Sep 2026 a distributor's order
        // waits for payment before approval, which is not what this file tests.
        'type' => CustomerType::Dealer,
        // 25 Oct 2026 (Q11 = A): blank = cash only for a dealer.
        'credit_limit' => '10000000',
    ]);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');

    Carbon::setTestNow('2026-08-11 10:00:00');

    // The standard selling price, in force from before the order date.
    ProductPrice::create([
        'business_id' => $this->business->id,
        'product_id' => $this->product->id,
        'unit_price' => '850.0000',
        'effective_from' => '2026-08-01',
        'created_by' => $this->owner->id,
    ]);

    $this->order = function (array $overrides = []) {
        return app(CreateSalesOrder::class)->handle(
            customer: $overrides['customer'] ?? $this->customer,
            lines: $overrides['lines'] ?? [['product' => $this->product, 'quantity' => '40']],
            createdBy: $overrides['createdBy'] ?? $this->owner,
            warehouse: $overrides['warehouse'] ?? null,
            orderedAt: $overrides['orderedAt'] ?? null,
            expectedDate: $overrides['expectedDate'] ?? null,
            note: $overrides['note'] ?? null,
        );
    };
});

afterEach(fn () => Carbon::setTestNow());

it('records the promise: customer, quantities, agreed price, and why', function () {
    $order = ($this->order)();

    expect($order->order_number)->toStartWith('SO-')
        ->and($order->status)->toBe(SalesOrderStatus::Open)
        ->and($order->lines)->toHaveCount(1)
        ->and((float) $order->lines->first()->quantity_ordered)->toBe(40.0)
        ->and((float) $order->lines->first()->quantity_delivered)->toBe(0.0)
        ->and((float) $order->lines->first()->unit_price)->toBe(850.0)
        ->and($order->lines->first()->price_note)->toBe('Standard selling price');
});

// ⚠⚠ The architecture in two assertions. An order is a promise, and a promise
// is not an accounting event: no journal, no stock movement, nothing for the
// reconciliation to see. §7 of the doc, stated as a test.
it('moves no stock and posts no journal', function () {
    ($this->order)();

    expect(Journal::count())->toBe(0)
        ->and(StockMovement::count())->toBe(0);
});

it('copies the resolved price for the customer tier, not a reference to it', function () {
    // An agreed price for THIS customer beats the standard price...
    ProductPrice::create([
        'business_id' => $this->business->id,
        'product_id' => $this->product->id,
        'customer_id' => $this->customer->id,
        'unit_price' => '800.0000',
        'effective_from' => '2026-08-01',
        'created_by' => $this->owner->id,
    ]);

    $order = ($this->order)();
    $line = $order->lines->first();

    expect((float) $line->unit_price)->toBe(800.0)
        ->and($line->price_note)->toBe('Agreed price for this customer');

    // ...and repricing the rule afterwards must NOT touch the order: the
    // price was COPIED at order time. This is what makes C-SO-4 possible.
    ProductPrice::where('customer_id', $this->customer->id)->update(['unit_price' => '999.0000']);

    expect((float) $line->fresh()->unit_price)->toBe(800.0);
});

it('takes a manual override verbatim and says so', function () {
    $order = ($this->order)([
        'lines' => [['product' => $this->product, 'quantity' => '40', 'unit_price' => '790.5000']],
    ]);

    expect((float) $order->lines->first()->unit_price)->toBe(790.5)
        ->and($order->lines->first()->price_note)->toBe('Manual override');
});

it('refuses to promise a price it cannot resolve', function () {
    ProductPrice::query()->delete();

    ($this->order)();
})->throws(NoSellingPriceException::class);

it('numbers orders SO-{fy}-0001 and increments within the business', function () {
    $first = ($this->order)();
    $second = ($this->order)();

    expect($first->order_number)->toEndWith('-0001')
        ->and($second->order_number)->toEndWith('-0002')
        ->and($first->order_number)->toStartWith('SO-')
        ->and(substr($first->order_number, 0, -4))->toBe(substr($second->order_number, 0, -4));
});

// ⚠ C-SO-1, both halves. An Owner's order is approved the moment it exists
// (self-approval, no friction); a Staff order waits for review, and goods
// cannot leave against it until that review happens.
it('self-approves an order created by an approver', function () {
    $order = ($this->order)();

    expect($order->approvalRequest->status)->toBe(ApprovalStatus::Approved)
        ->and($order->isDeliverable())->toBeTrue();
});

it('leaves a staff-created order pending and not deliverable', function () {
    $order = ($this->order)(['createdBy' => $this->staff]);

    expect($order->approvalRequest->status)->toBe(ApprovalStatus::Pending)
        ->and($order->isDeliverable())->toBeFalse();
});

it('becomes deliverable when the pending order is approved', function () {
    $order = ($this->order)(['createdBy' => $this->staff]);

    approveIfPending($order->approvalRequest, $this->owner);

    expect($order->fresh()->isDeliverable())->toBeTrue();
});

it('accepts every customer type without branching, walk-in included', function () {
    foreach (CustomerType::cases() as $type) {
        $customer = Customer::create([
            'business_id' => $this->business->id,
            'name' => "Customer {$type->value}",
            'type' => $type,
        ]);

        $order = ($this->order)(['customer' => $customer]);

        expect($order->customer_id)->toBe($customer->id);
    }

    expect(SalesOrder::count())->toBe(count(CustomerType::cases()));
});

it('refuses a service line: a service cannot be delivered, so it cannot be ordered', function () {
    $service = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => UnitOfMeasure::first()->id,
        'sku' => 'SKU-DELIVERY-FEE',
        'name' => 'Home delivery',
        'type' => ProductType::Service,
    ]);

    ($this->order)(['lines' => [['product' => $service, 'quantity' => '1', 'unit_price' => '100']]]);
})->throws(ValidationException::class);

it('refuses an inactive customer', function () {
    $this->customer->update(['is_active' => false]);

    ($this->order)();
})->throws(ValidationException::class);

it('refuses an order with no positive-quantity line', function () {
    ($this->order)(['lines' => [['product' => $this->product, 'quantity' => '0']]]);
})->throws(ValidationException::class);

it('refuses a warehouse from another business', function () {
    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-co']);
    $foreign = Warehouse::create([
        'business_id' => $other->id, 'name' => 'Elsewhere', 'code' => 'ELSE',
    ]);

    ($this->order)(['warehouse' => $foreign]);
})->throws(ValidationException::class);

// ⚠ The warehouse is a DEFAULT for the delivery screen, not a commitment
// (§4.1): an order may name one, or none at all.
it('accepts an order with no warehouse and one with a warehouse', function () {
    $without = ($this->order)();
    $with = ($this->order)(['warehouse' => $this->warehouse]);

    expect($without->warehouse_id)->toBeNull()
        ->and($with->warehouse_id)->toBe($this->warehouse->id);
});

it('resolves the price at the ORDER date, not today', function () {
    // A price rise takes effect tomorrow; an order dated today still gets
    // today's price - and an order dated after the rise gets the new one.
    ProductPrice::create([
        'business_id' => $this->business->id,
        'product_id' => $this->product->id,
        'unit_price' => '900.0000',
        'effective_from' => '2026-08-12',
        'created_by' => $this->owner->id,
    ]);

    $today = ($this->order)();
    $afterRise = ($this->order)(['orderedAt' => '2026-08-12']);

    expect((float) $today->lines->first()->unit_price)->toBe(850.0)
        ->and((float) $afterRise->lines->first()->unit_price)->toBe(900.0);
});

it('applies quantity breaks from the resolver at order quantities', function () {
    ProductPrice::create([
        'business_id' => $this->business->id,
        'product_id' => $this->product->id,
        'min_quantity' => '20',
        'unit_price' => '820.0000',
        'effective_from' => '2026-08-01',
        'created_by' => $this->owner->id,
    ]);

    $bulk = ($this->order)(); // 40 units, qualifies for the break
    $small = ($this->order)(['lines' => [['product' => $this->product, 'quantity' => '5']]]);

    expect((float) $bulk->lines->first()->unit_price)->toBe(820.0)
        ->and((float) $small->lines->first()->unit_price)->toBe(850.0);
});
