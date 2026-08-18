<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * C-PUR-E1: what a Purchasing refusal looks like from the browser.
 *
 * ⚠⚠ Same finding as Sales, one document later on the same morning.
 * `PurchaseOrderController` catches nothing, and the actions behind it throw
 * plain RuntimeException and InvalidArgumentException with messages clearly
 * written for a person: "Cannot receive 50 of line #7; only 20 remains on the
 * order." Uncaught, every one of them was a blank 500.
 *
 * ⚠ Over-receiving against a purchase order is one of the commonest mistakes a
 * storekeeper makes, and goods receipt is step 2 of the runbook's first morning.
 *
 * ⚠ TWO surfaces, deliberately. A form submission gets a validation error on a
 * key the form renders. A bare button (approve, cancel, payment status) has no
 * form to attach an error to, so it flashes to the global error toast, which is
 * how Foundation already refuses button actions in nine places.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Refusal Purchasing', 'slug' => 'refusal-purchasing']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new PurchasingPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->warehouse = Warehouse::create([
        'business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN',
    ]);
    $unit = UnitOfMeasure::create([
        'business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs',
    ]);
    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-DRUM',
        'name' => 'Mustard oil drum',
        'type' => ProductType::RawMaterial,
    ]);
    $this->supplier = Supplier::create([
        'business_id' => $this->business->id, 'name' => 'Razzak Oil Mill',
    ]);

    $this->manager = User::factory()->create();
    $this->manager->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->manager->assignRole('Manager');

    $this->asManager = fn () => $this->actingAs($this->manager)
        ->withSession(['current_business_id' => $this->business->id]);

    $this->orderFor = function (string $quantity = '20', string $cost = '100') {
        $order = app(CreatePurchaseOrder::class)->handle(
            supplier: $this->supplier,
            warehouse: $this->warehouse,
            lines: [['product' => $this->product, 'quantity' => $quantity, 'unit_cost' => $cost]],
            createdBy: $this->manager,
        );

        $order->approvalRequest->approve($this->manager);

        return $order->fresh(['lines', 'approvalRequest']);
    };
});

// ▶▶ THE GO-LIVE CASE. Runbook গ-২ step 2, the second real transaction of
// 1 September. The storekeeper types the wrong number.
it('tells the storekeeper he is over-receiving instead of failing blank', function () {
    $order = ($this->orderFor)('20');

    $response = ($this->asManager)()->post(
        route('purchasing.purchase-orders.receive', $order),
        ['lines' => [['purchase_order_line_id' => $order->lines->first()->id, 'quantity' => '50']]]
    );

    $response->assertSessionHasErrors('lines');

    expect(session('errors')->first('lines'))->toContain('only 20')
        ->and(GoodsReceipt::count())->toBe(0);
});

it('tells the storekeeper the order is not open for receiving instead of failing blank', function () {
    $order = ($this->orderFor)('20');
    $line = $order->lines->first();

    // Receive it all, which closes the order to further receiving.
    app(RecordGoodsReceipt::class)->handle($order, [['line' => $line, 'quantity' => '20']], $this->manager);

    $response = ($this->asManager)()->post(
        route('purchasing.purchase-orders.receive', $order->fresh()),
        ['lines' => [['purchase_order_line_id' => $line->id, 'quantity' => '1']]]
    );

    $response->assertSessionHasErrors('lines');
});

it('refuses a receipt with nothing on it with a readable message', function () {
    $order = ($this->orderFor)('20');

    $response = ($this->asManager)()->post(
        route('purchasing.purchase-orders.receive', $order),
        ['lines' => [['purchase_order_line_id' => $order->lines->first()->id, 'quantity' => '0']]]
    );

    $response->assertSessionHasErrors('lines');

    expect(GoodsReceipt::count())->toBe(0);
});

// ⚠⚠ The partial-configuration hole. `forReceipt` returns early and posts
// NOTHING when stock_on_hand is unmapped, which is the deliberate "accounting
// not wired yet" escape. But if stock_on_hand is mapped and GRNI is not, it
// walks past the guard and resolve() throws.
it('names the missing account mapping instead of failing blank', function () {
    $order = ($this->orderFor)('20');

    AccountMapping::where('key', 'inventory.goods_received_not_invoiced')->delete();

    $response = ($this->asManager)()->post(
        route('purchasing.purchase-orders.receive', $order),
        ['lines' => [['purchase_order_line_id' => $order->lines->first()->id, 'quantity' => '5']]]
    );

    $response->assertSessionHasErrors('lines');
});

it('tells the manager he is over-returning instead of failing blank', function () {
    $order = ($this->orderFor)('20');
    $line = $order->lines->first();

    app(RecordGoodsReceipt::class)->handle($order, [['line' => $line, 'quantity' => '5']], $this->manager);

    $response = ($this->asManager)()->post(
        route('purchasing.purchase-orders.returns.store', $order->fresh()),
        ['lines' => [['purchase_order_line_id' => $line->id, 'quantity' => '99']]]
    );

    $response->assertSessionHasErrors('lines');

    expect(session('errors')->first('lines'))->toContain('returnable');
});

// ⚠ A bare button, so the refusal goes to the global error toast rather than a
// form field. Two managers pressing approve, or one double-click.
it('flashes an error when an already-reviewed order is approved again', function () {
    $order = ($this->orderFor)('20');

    $response = ($this->asManager)()->post(route('purchasing.purchase-orders.approve', $order));

    $response->assertRedirect();
    expect(session('error'))->toContain('already been reviewed');
});

it('flashes an error when cancelling an order that still holds received stock', function () {
    $order = ($this->orderFor)('20');

    app(RecordGoodsReceipt::class)->handle(
        $order, [['line' => $order->lines->first(), 'quantity' => '20']], $this->manager
    );

    $response = ($this->asManager)()->post(route('purchasing.purchase-orders.cancel', $order->fresh()));

    $response->assertRedirect();
    expect(session('error'))->toContain('return it before cancelling')
        ->and(PurchaseOrder::sole()->status->value)->not->toBe('cancelled');
});

// ⚠ A refusal that still refuses. The point of the whole slice is that the
// answer does not change, only whether it can be read.
it('still records a good receipt exactly as before', function () {
    $order = ($this->orderFor)('20');

    ($this->asManager)()->post(
        route('purchasing.purchase-orders.receive', $order),
        ['lines' => [['purchase_order_line_id' => $order->lines->first()->id, 'quantity' => '20']]]
    )->assertRedirect();

    expect(GoodsReceipt::count())->toBe(1)
        ->and((float) $this->product->fresh()->currentStockQuantity())->toBe(20.0);
});
