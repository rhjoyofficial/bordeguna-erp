<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Typing a count in (21 Sep 2026 audit, B.6).
 *
 * ⚠⚠ Every row had its own Save button, and Complete reads only SAVED rows,
 * so a counter who typed forty numbers and pressed Complete had every unsaved
 * one "skipped" - and a refused number was never shown at all, so it sat in
 * its box looking saved. "Save all" sends every typed row in one request with
 * errors keyed by LINE ID, which is what lets the screen put each refusal on
 * the row it belongs to.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Count Entry Co', 'slug' => 'count-entry-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['inventory.reports.view', 'inventory.stock.adjust', 'inventory.stock.request'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN', 'is_default' => true]);
    Warehouse::create(['business_id' => $this->business->id, 'name' => 'Annex', 'code' => 'ANX']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->reason = Reason::create(['business_id' => $this->business->id, 'context' => 'inventory.stock_adjustment', 'name' => 'Physical count correction']);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->user->givePermissionTo(['inventory.reports.view', 'inventory.stock.adjust']);

    $this->as = fn () => $this->actingAs($this->user)->withSession(['current_business_id' => $this->business->id]);

    foreach (['A' => 'Apple jar', 'B' => 'Banana chips', 'C' => 'Cashew pack'] as $sku => $name) {
        $product = Product::create([
            'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
            'sku' => "SKU-{$sku}", 'name' => $name, 'type' => ProductType::FinishedGood,
        ]);
        app(RecordStockIn::class)->handle($product, $this->warehouse, '10', '20', $this->user);
    }

    ($this->as)()->post(route('inventory.stock-counts.store'), ['warehouse_id' => $this->warehouse->id])->assertSessionHasNoErrors();
    $this->count = StockCount::sole();
    $this->lines = $this->count->lines()->orderBy('id')->get();
});

it('saves every typed row in one request', function () {
    [$a, $b, $c] = $this->lines;

    ($this->as)()->from(route('inventory.stock-counts.show', $this->count))
        ->patch(route('inventory.stock-counts.lines.update-many', $this->count), [
            'lines' => [
                $a->id => ['counted_quantity' => '9', 'unit_cost' => null],
                $c->id => ['counted_quantity' => '12', 'unit_cost' => '21'],
            ],
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', '2 lines saved.');

    expect((float) $a->fresh()->counted_quantity)->toBe(9.0)
        ->and($b->fresh()->counted_quantity)->toBeNull()
        ->and((float) $c->fresh()->counted_quantity)->toBe(12.0)
        ->and((float) $c->fresh()->unit_cost)->toBe(21.0);
});

it('puts a refused number on the row it belongs to, and saves nothing', function () {
    [$a, $b] = $this->lines;

    ($this->as)()->patch(route('inventory.stock-counts.lines.update-many', $this->count), [
        'lines' => [
            $a->id => ['counted_quantity' => '9'],
            $b->id => ['counted_quantity' => '-2'],
        ],
    ])->assertSessionHasErrors(["lines.{$b->id}.counted_quantity" => 'A count cannot be below zero.']);

    expect($a->fresh()->counted_quantity)->toBeNull();
});

it('refuses a line from another count whole rather than half-saving', function () {
    [$a] = $this->lines;

    ($this->as)()->post(route('inventory.stock-counts.store'), ['warehouse_id' => Warehouse::where('code', 'ANX')->value('id')]);
    $otherCount = StockCount::query()->whereKeyNot($this->count->id)->sole();

    // A line id that exists, but on a different count.
    ($this->as)()->patch(route('inventory.stock-counts.lines.update-many', $otherCount), [
        'lines' => [$a->id => ['counted_quantity' => '1']],
    ])->assertSessionHasErrors('count');

    expect($a->fresh()->counted_quantity)->toBeNull();
});

it('refuses to save into a count that is no longer a draft', function () {
    [$a] = $this->lines;
    $this->count->update(['status' => 'cancelled']);

    ($this->as)()->patch(route('inventory.stock-counts.lines.update-many', $this->count), [
        'lines' => [$a->id => ['counted_quantity' => '1']],
    ])->assertSessionHasErrors('count');
});

it('says the corrections are waiting for approval, and the page links there', function () {
    [$a, $b] = $this->lines;

    ($this->as)()->patch(route('inventory.stock-counts.lines.update-many', $this->count), [
        'lines' => [$a->id => ['counted_quantity' => '8'], $b->id => ['counted_quantity' => '10']],
    ])->assertSessionHasNoErrors();

    // No approval limit is set, so the one variance waits.
    ($this->as)()->from(route('inventory.stock-counts.show', $this->count))
        ->post(route('inventory.stock-counts.complete', $this->count), ['reason_id' => $this->reason->id])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('awaitingApproval', '1 correction(s) from this count are waiting for approval. Stock does not change until they are approved.');

    expect(InventoryAdjustment::sole()->approvalRequest->isPending())->toBeTrue();

    ($this->as)()->withSession(['awaitingApproval' => 'waiting'])
        ->get(route('inventory.stock-counts.show', $this->count))
        ->assertInertia(fn ($page) => $page->where('awaitingApproval', 'waiting'));
});

it('refuses a reason from another business as a field error', function () {
    $elsewhere = Business::create(['name' => 'Elsewhere', 'slug' => 'elsewhere-count']);
    $foreign = Reason::create(['business_id' => $elsewhere->id, 'context' => 'inventory.stock_adjustment', 'name' => 'Theirs']);

    ($this->as)()->post(route('inventory.stock-counts.complete', $this->count), ['reason_id' => $foreign->id])
        ->assertSessionHasErrors('reason_id');

    expect($this->count->fresh()->status->value)->toBe('draft');
});

it('pages the list of counts and preselects the default warehouse', function () {
    ($this->as)()->get(route('inventory.stock-counts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('inventory/stock-counts/index')
            ->where('defaultWarehouseId', $this->warehouse->id)
            // ⚠ The paginator's meta: what the new pager reads. It was always
            // sent; nothing rendered it.
            ->where('counts.current_page', 1)
            ->where('counts.last_page', 1)
            ->where('counts.total', 1));
});
