<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryPermissionsSeeder;
use App\Modules\Inventory\database\seeders\InventoryReasonsSeeder;
use App\Modules\Inventory\Enums\InventoryAdjustmentType;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\InventorySetting;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockRequest;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\AdjustmentCause;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⚠⚠ THE STOCK SCREEN COULD NOT RECORD A SINGLE CORRECTION (found 21 Sep 2026).
 *
 * The Owner/Manager "Direct adjustment" form never sent the `type` the
 * endpoint required, and the Staff "Request adjustment" form posted
 * `type: adjustment` to the stock-request endpoint, which accepts only
 * transfers. Neither refusal was rendered. The engine tests were green the
 * whole time because they post `type` themselves - the half the screen never
 * did. So these post EXACTLY what the new "What happened?" dialog sends: a
 * reason and a direction, no type.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Correction Co', 'slug' => 'correction-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    // The REAL permission and reason seeders, so the roles and the six reason
    // names are the ones a business actually has.
    (new InventoryPermissionsSeeder)->run();
    app()->call([new InventoryReasonsSeeder, 'run']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Mirpur', 'code' => 'MIR', 'is_default' => true]);
    $this->other = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Adabor', 'code' => 'ADB']);
    $this->kg = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $this->kg->id,
        'sku' => 'RICE-1', 'name' => 'Chinigura rice', 'type' => ProductType::FinishedGood,
    ]);

    $this->reason = fn (string $name) => Reason::query()->forContext('inventory.stock_adjustment')->where('name', $name)->firstOrFail();

    $this->userAs = function (string $role): User {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $user;
    };

    $this->owner = ($this->userAs)('Owner');
    $this->staff = ($this->userAs)('Staff');
    $this->as = fn (User $user) => $this->actingAs($user)->withSession(['current_business_id' => $this->business->id]);

    app(RecordStockIn::class)->handle($this->product, $this->warehouse, '50', '80', $this->owner);
});

it('records damage from the Stock screen without being told the type', function () {
    ($this->as)($this->owner)->from(route('inventory.stock.index'))
        ->post(route('inventory.stock.adjustments.store'), [
            'reason_id' => ($this->reason)('Broken/Damaged')->id,
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'direction' => 'decrease',
            'quantity' => '3',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('inventory.stock.index'));

    $adjustment = InventoryAdjustment::sole();

    // ⚠ The type is DERIVED from the reason, and it is the account mapping
    // key, so it has to be the right one - not merely present.
    expect($adjustment->type)->toBe(InventoryAdjustmentType::Damaged)
        ->and($adjustment->reason_id)->toBe(($this->reason)('Broken/Damaged')->id);
});

it('derives the type from every seeded reason, and falls back to a manual correction', function () {
    $expected = [
        'Broken/Damaged' => [InventoryAdjustmentType::Damaged, true],
        'Expired' => [InventoryAdjustmentType::Expired, true],
        'Shrinkage/Lost' => [InventoryAdjustmentType::LostOrStolen, true],
        'Spoiled' => [InventoryAdjustmentType::Spoiled, true],
        'Damaged returns' => [InventoryAdjustmentType::DamagedReturn, true],
        'Physical count correction' => [InventoryAdjustmentType::ManualCorrection, false],
        'Data entry error' => [InventoryAdjustmentType::ManualCorrection, false],
        'Other' => [InventoryAdjustmentType::ManualCorrection, false],
    ];

    foreach ($expected as $name => [$type, $onlyReduces]) {
        $reason = ($this->reason)($name);

        expect(AdjustmentCause::typeFor($reason))->toBe($type, $name)
            ->and(AdjustmentCause::onlyReduces($type))->toBe($onlyReduces, $name);
    }

    // A reason renamed to the system's own label is still understood; one the
    // system cannot read lands on the neutral type rather than a guess.
    expect(AdjustmentCause::typeFor(new Reason(['name' => '  Lost   or STOLEN '])))->toBe(InventoryAdjustmentType::LostOrStolen)
        ->and(AdjustmentCause::typeFor(new Reason(['name' => 'Rats'])))->toBe(InventoryAdjustmentType::ManualCorrection);
});

it('still honours an explicit type from a caller that knows better', function () {
    ($this->as)($this->owner)->post(route('inventory.stock.adjustments.store'), [
        'type' => InventoryAdjustmentType::InternalConsumption->value,
        'reason_id' => ($this->reason)('Other')->id,
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'direction' => 'decrease',
        'quantity' => '1',
    ])->assertSessionHasNoErrors();

    expect(InventoryAdjustment::sole()->type)->toBe(InventoryAdjustmentType::InternalConsumption);
});

it('records found stock as a count correction with the cost typed in', function () {
    ($this->as)($this->owner)->post(route('inventory.stock.adjustments.store'), [
        'reason_id' => ($this->reason)('Physical count correction')->id,
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'direction' => 'increase',
        'quantity' => '4',
        'unit_cost' => '80',
    ])->assertSessionHasNoErrors();

    $adjustment = InventoryAdjustment::sole();

    expect($adjustment->type)->toBe(InventoryAdjustmentType::ManualCorrection)
        ->and($adjustment->direction->value)->toBe('increase')
        ->and((string) $adjustment->unit_cost)->toBe('80.0000');
});

it('says what is missing on the keys the dialog renders', function () {
    ($this->as)($this->owner)->post(route('inventory.stock.adjustments.store'), [])
        ->assertSessionHasErrors([
            'reason_id' => 'Choose what happened.',
            'product_id',
            'warehouse_id',
            'quantity',
            'direction' => 'Choose whether there is more or less than the system shows.',
        ]);

    // Found stock with no cost: refused on the cost box, in words.
    ($this->as)($this->owner)->post(route('inventory.stock.adjustments.store'), [
        'reason_id' => ($this->reason)('Other')->id,
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'direction' => 'increase',
        'quantity' => '2',
    ])->assertSessionHasErrors(['unit_cost' => 'Stock that turned up needs a cost for each unit.']);

    expect(InventoryAdjustment::count())->toBe(0);
});

it('refuses another business\'s product, warehouse or reason as a field error, not a blank 404', function () {
    $elsewhere = Business::create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);
    app(BusinessContext::class)->set($elsewhere->id);
    $foreignUnit = UnitOfMeasure::create(['business_id' => $elsewhere->id, 'name' => 'Piece', 'abbreviation' => 'pc']);
    $foreignProduct = Product::create([
        'business_id' => $elsewhere->id, 'unit_of_measure_id' => $foreignUnit->id,
        'sku' => 'X-1', 'name' => 'Not yours', 'type' => ProductType::FinishedGood,
    ]);
    $foreignWarehouse = Warehouse::create(['business_id' => $elsewhere->id, 'name' => 'Theirs', 'code' => 'THR']);
    $foreignReason = Reason::create(['business_id' => $elsewhere->id, 'context' => 'inventory.stock_adjustment', 'name' => 'Damaged']);
    app(BusinessContext::class)->set($this->business->id);

    ($this->as)($this->owner)->post(route('inventory.stock.adjustments.store'), [
        'reason_id' => $foreignReason->id,
        'product_id' => $foreignProduct->id,
        'warehouse_id' => $foreignWarehouse->id,
        'direction' => 'decrease',
        'quantity' => '1',
    ])->assertSessionHasErrors(['reason_id', 'product_id', 'warehouse_id']);

    ($this->as)($this->owner)->post(route('inventory.stock.transfers.store'), [
        'product_id' => $foreignProduct->id,
        'warehouse_id' => $this->warehouse->id,
        'target_warehouse_id' => $foreignWarehouse->id,
        'quantity' => '1',
        'reason_id' => Reason::query()->forContext('inventory.stock_transfer')->firstOrFail()->id,
    ])->assertSessionHasErrors(['product_id', 'target_warehouse_id']);

    expect(InventoryAdjustment::count())->toBe(0)->and(StockTransfer::count())->toBe(0);
});

it('makes the Owner\'s correction wait, and points at Approvals, while no limit is set', function () {
    // ⚠ No limit is the default and stays the default: nothing on this screen
    // or in a seeder invents one.
    expect(InventorySetting::query()->value('adjustment_approval_threshold'))->toBeNull();

    ($this->as)($this->owner)->from(route('inventory.stock.index'))
        ->post(route('inventory.stock.adjustments.store'), [
            'reason_id' => ($this->reason)('Expired')->id,
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'direction' => 'decrease',
            'quantity' => '2',
        ])
        ->assertSessionHas('success', 'Stock adjustment submitted for approval.')
        ->assertSessionHas('awaitingApproval');

    expect(InventoryAdjustment::sole()->approvalRequest->isPending())->toBeTrue()
        ->and($this->product->fresh()->currentStockQuantity())->toBe('50');

    // ⛔ Assert the PROP, not the session: a flashed key reaches no page until
    // a controller passes it (HandleInertiaRequests, dead engine #9).
    ($this->as)($this->owner)->withSession(['awaitingApproval' => 'Stock adjustment submitted for approval. Stock does not change until it is approved.'])
        ->get(route('inventory.stock.index'))
        ->assertInertia(fn ($page) => $page
            ->where('awaiting_approval', 'Stock adjustment submitted for approval. Stock does not change until it is approved.')
            ->where('approval_threshold', null));
});

it('saves the Owner\'s correction at once under a limit the owner set', function () {
    InventorySetting::forCurrentBusiness()->update(['adjustment_approval_threshold' => '1000']);

    ($this->as)($this->owner)->post(route('inventory.stock.adjustments.store'), [
        'reason_id' => ($this->reason)('Broken/Damaged')->id,
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'direction' => 'decrease',
        'quantity' => '2', // 2 x 80 = 160, under 1000
    ])
        ->assertSessionHas('success', 'Stock adjustment saved.')
        ->assertSessionMissing('awaitingApproval');

    expect($this->product->fresh()->currentStockQuantity())->toBe('48');
});

it('lets Staff report damage through the same endpoint, and it always waits for a manager', function () {
    InventorySetting::forCurrentBusiness()->update(['adjustment_approval_threshold' => '1000000']);

    ($this->as)($this->staff)->post(route('inventory.stock.adjustments.store'), [
        'reason_id' => ($this->reason)('Shrinkage/Lost')->id,
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'direction' => 'decrease',
        'quantity' => '1',
    ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('awaitingApproval');

    $adjustment = InventoryAdjustment::sole();

    expect($adjustment->type)->toBe(InventoryAdjustmentType::LostOrStolen)
        ->and($adjustment->approvalRequest->isPending())->toBeTrue()
        ->and($this->product->fresh()->currentStockQuantity())->toBe('50');
});

it('still refuses a correction sent to the transfer-request endpoint, where the old Staff form sent it', function () {
    // ⚠ The regression this whole screen was rebuilt around. The endpoint is
    // right to refuse; what changed is that nothing on the screen posts here
    // any more except "Ask to move stock".
    ($this->as)($this->staff)->post(route('inventory.stock-requests.store'), [
        'type' => 'adjustment',
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity' => '1',
        'reason_id' => ($this->reason)('Broken/Damaged')->id,
    ])->assertSessionHasErrors(['type', 'target_warehouse_id']);

    expect(StockRequest::count())->toBe(0);
});

it('lets Staff ask to move stock, and points at Approvals', function () {
    ($this->as)($this->staff)->post(route('inventory.stock-requests.store'), [
        'type' => 'transfer',
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'target_warehouse_id' => $this->other->id,
        'quantity' => '5',
        'reason_id' => Reason::query()->forContext('inventory.stock_transfer')->firstOrFail()->id,
    ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('awaitingApproval');

    expect(StockRequest::sole()->approvalRequest->isPending())->toBeTrue();

    // Same warehouse twice is said in words.
    ($this->as)($this->staff)->post(route('inventory.stock-requests.store'), [
        'type' => 'transfer',
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'target_warehouse_id' => $this->warehouse->id,
        'quantity' => '5',
        'reason_id' => Reason::query()->forContext('inventory.stock_transfer')->firstOrFail()->id,
    ])->assertSessionHasErrors(['target_warehouse_id' => 'Stock has to move to a different warehouse.']);
});

it('hands the screen everything the dialogs need: reasons with their meaning, units, the default warehouse', function () {
    ($this->as)($this->owner)->get(route('inventory.stock.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('inventory/stock/index')
            ->where('default_warehouse_id', $this->warehouse->id)
            ->where('products.0.unit', 'kg')
            ->where('stock.data.0.product.unit_of_measure.abbreviation', 'kg')
            ->where('on_hand.'.$this->product->id.'-'.$this->warehouse->id, fn ($qty) => (float) $qty === 50.0)
            ->where('last_costs.'.$this->product->id, fn ($cost) => (float) $cost === 80.0)
            ->has('adjustment_reasons', 8)
            ->where('adjustment_reasons', fn ($reasons) => collect($reasons)->firstWhere('name', 'Broken/Damaged')['only_reduces'] === true
                && collect($reasons)->firstWhere('name', 'Broken/Damaged')['type'] === 'damaged'
                && collect($reasons)->firstWhere('name', 'Other')['only_reduces'] === false)
            ->where('can_adjust_directly', true)
            ->where('can_set_threshold', true));

    ($this->as)($this->staff)->get(route('inventory.stock.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can_adjust_directly', false)
            ->where('can_request', true)
            ->where('can_set_threshold', false));
});

// ⚠ Bug hunt 16 Oct 2026 (02 L8): only the dialog fixed the direction and the
// reason's context, so a crafted request could do either.
it('refuses to raise stock for a reason that only takes it away', function () {
    ($this->as)($this->owner)->post(route('inventory.stock.adjustments.store'), [
        'product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id,
        'reason_id' => ($this->reason)('Broken/Damaged')->id, 'direction' => 'increase', 'quantity' => '3', 'unit_cost' => '100',
    ])->assertSessionHasErrors('direction');

    expect(InventoryAdjustment::count())->toBe(0);
});

it('refuses a reason that is not a stock correction reason', function () {
    $transferReason = Reason::create(['business_id' => $this->business->id, 'context' => 'inventory.stock_transfer', 'name' => 'Rebalancing']);

    ($this->as)($this->owner)->post(route('inventory.stock.adjustments.store'), [
        'product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id,
        'reason_id' => $transferReason->id, 'direction' => 'decrease', 'quantity' => '1',
    ])->assertSessionHasErrors('reason_id');
});
