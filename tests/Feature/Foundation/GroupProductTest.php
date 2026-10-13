<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Module 6, Slice 2: group product links - the same design as partner
 * links, applied to SKUs. No shared master, no renames; the link is the
 * knowledge, and the glance shows stock and price side by side for the
 * companies the viewer belongs to.
 */
beforeEach(function () {
    $this->bdg = Business::create(['name' => 'Bor de Guna', 'slug' => 'bdg']);
    $this->lld = Business::create(['name' => 'LaLaDia', 'slug' => 'lld']);

    app(BusinessContext::class)->set($this->bdg->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->bdg->id);
    foreach (['inventory.reports.view', 'inventory.catalog.manage'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    foreach ([$this->bdg, $this->lld] as $business) {
        Role::create(['name' => 'Owner', 'guard_name' => 'web', 'business_id' => $business->id])
            ->givePermissionTo(['inventory.reports.view', 'inventory.catalog.manage']);
        Role::create(['name' => 'Self-service', 'guard_name' => 'web', 'business_id' => $business->id]);
    }

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach([$this->bdg->id, $this->lld->id]);
    foreach ([$this->lld, $this->bdg] as $business) {
        app(PermissionRegistrar::class)->setPermissionsTeamId($business->id);
        $this->owner->unsetRelation('roles')->assignRole('Owner');
    }

    $makeProduct = function (int $businessId, string $sku, string $name) {
        app(BusinessContext::class)->set($businessId);
        $unit = UnitOfMeasure::create(['business_id' => $businessId, 'name' => 'Pieces', 'abbreviation' => 'pcs']);

        return Product::create([
            'business_id' => $businessId, 'unit_of_measure_id' => $unit->id,
            'sku' => $sku, 'name' => $name,
            'type' => ProductType::FinishedGood, 'is_active' => true,
        ]);
    };

    // The same honey sold by both companies, same SKU.
    $this->bdgHoney = $makeProduct($this->bdg->id, 'HONEY-500', 'Sundarban Honey 500g');
    $this->lldHoney = $makeProduct($this->lld->id, 'HONEY-500', 'Honey 500 (LLD)');

    // Stock: 12 pieces in Bor de Guna, 5 in LaLaDia.
    foreach ([[$this->bdg->id, $this->bdgHoney->id, 12], [$this->lld->id, $this->lldHoney->id, 5]] as [$biz, $product, $qty]) {
        DB::table('stock_batches')->insert([
            'business_id' => $biz, 'product_id' => $product,
            'warehouse_id' => DB::table('warehouses')->insertGetId([
                'business_id' => $biz, 'name' => 'W'.$biz, 'code' => 'W'.$biz,
                'created_at' => now(), 'updated_at' => now(),
            ]),
            'batch_number' => 'B-'.$biz,
            'unit_cost' => 500, 'initial_quantity' => $qty, 'current_quantity' => $qty,
            'status' => 'active', 'received_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    app(BusinessContext::class)->set($this->bdg->id);

    $this->asOwner = fn () => $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->bdg->id]);
});

it('suggests identical SKUs across companies and links them into one product', function () {
    ($this->asOwner)()->get(route('group.products'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('group/products')
            ->has('suggestions', 1)
            ->where('suggestions.0.sku', 'HONEY-500'));

    ($this->asOwner)()->post(route('group.products.link'), [
        'name' => 'Sundarban Honey 500g',
        'members' => [
            ['business_id' => $this->bdg->id, 'product_id' => $this->bdgHoney->id],
            ['business_id' => $this->lld->id, 'product_id' => $this->lldHoney->id],
        ],
    ])->assertSessionHasNoErrors();

    ($this->asOwner)()->get(route('group.products'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('links', 1)
            ->has('links.0.members', 2)
            ->where('links.0.total_stock', fn ($value) => (float) $value === 17.0)
            ->has('suggestions', 0));
});

it('shows only the companies the viewer belongs to', function () {
    ($this->asOwner)()->post(route('group.products.link'), [
        'name' => 'Sundarban Honey 500g',
        'members' => [
            ['business_id' => $this->bdg->id, 'product_id' => $this->bdgHoney->id],
            ['business_id' => $this->lld->id, 'product_id' => $this->lldHoney->id],
        ],
    ]);

    $limited = User::factory()->create();
    $limited->businesses()->attach($this->bdg->id);
    $limited->assignRole('Owner');

    $this->actingAs($limited)->withSession(['current_business_id' => $this->bdg->id])
        ->get(route('group.products'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('links.0.members', 1)
            ->where('links.0.total_stock', fn ($value) => (float) $value === 12.0));
});

/**
 * ⚠⚠ Audit, 13 Oct 2026: membership was the only gate, so the zero-permission
 * self-service login read stock and prices and could delete any link by id.
 */
it('refuses the screen and the unlink to a login that may not read stock', function () {
    ($this->asOwner)()->post(route('group.products.link'), [
        'name' => 'Sundarban Honey 500g',
        'members' => [
            ['business_id' => $this->bdg->id, 'product_id' => $this->bdgHoney->id],
            ['business_id' => $this->lld->id, 'product_id' => $this->lldHoney->id],
        ],
    ])->assertSessionHasNoErrors();
    $link = (int) DB::table('group_product_links')->value('id');

    $selfService = User::factory()->create();
    $selfService->businesses()->attach([$this->bdg->id, $this->lld->id]);
    $selfService->assignRole('Self-service');

    $as = fn () => $this->actingAs($selfService)->withSession(['current_business_id' => $this->bdg->id]);
    $as()->get(route('group.products'))->assertForbidden();
    $as()->delete(route('group.products.unlink', $link))->assertForbidden();

    expect(DB::table('group_product_links')->count())->toBe(1);
});
