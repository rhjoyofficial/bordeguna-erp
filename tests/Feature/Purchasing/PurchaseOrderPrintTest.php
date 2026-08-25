<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The purchase order as the supplier receives it.
 *
 * ⚠⚠ A purchase order is an offer to pay, so unlike a delivery challan it
 * carries money - and it carries the PURCHASE unit beside every quantity,
 * which is the difference between ordering 100 kilograms and 100 litres.
 */
beforeEach(function () {
    $this->business = Business::create([
        'name' => 'Buyer Co',
        'slug' => 'buyer-co',
        'legal_name' => 'Buyer Company Limited',
        'address' => 'Adabor, Dhaka',
        'phone' => '01711000000',
        'email' => 'buy@buyerco.test',
    ]);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAVAR']);

    $litre = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Litre', 'abbreviation' => 'L']);
    $kilo = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);

    // ⚠ Held in litres, bought in kilograms: the real mustard-oil shape.
    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $litre->id,
        'sku' => 'BNC-OIL-MUS-BULK',
        'name' => 'Mustard Oil, bulk',
        'type' => ProductType::RawMaterial,
    ]);

    // ⚠⚠ `purchase_unit_id` is deliberately NOT fillable: the conversion
    // machinery owns it, so a mass-assigned array drops it in silence. Caught
    // by this test failing on an absent unit, which is exactly the trap the
    // printed order exists to close.
    $this->product->purchase_unit_id = $kilo->id;
    $this->product->save();

    $this->supplier = Supplier::create([
        'business_id' => $this->business->id,
        'code' => 'ROILMIL',
        'name' => 'Razzak Oil Mill',
        'contact_name' => 'Razzak',
        'address' => 'Manikganj',
        'phone' => '01733000000',
    ]);

    foreach (['purchasing.po.create', 'purchasing.po.approve', 'purchasing.reports.view'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    foreach (['Owner', 'Outsider'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }

    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()
        ->givePermissionTo(['purchasing.po.create', 'purchasing.po.approve', 'purchasing.reports.view']);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    $this->order = app(CreatePurchaseOrder::class)->handle(
        $this->supplier,
        $this->warehouse,
        [['product' => $this->product, 'quantity' => '100', 'unit_cost' => '210.50']],
        $this->owner,
        '2026-09-05',
        'Bring the drums back.',
    );

    $this->asOwner = fn () => $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id]);
});

it('prints the order with the supplier, the totals and the letterhead', function () {
    ($this->asOwner)()->get(route('purchasing.purchase-orders.print', $this->order))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('purchasing/purchase-orders/print')
            ->where('order.po_number', $this->order->po_number)
            ->where('order.supplier.name', 'Razzak Oil Mill')
            ->where('order.warehouse.name', 'Savar')
            // 100 x 210.50, totalled on the server with the ledger's own Money
            // type rather than added up again by the page.
            ->where('subtotal', fn ($value) => (float) $value === 21050.0)
            ->where('total', fn ($value) => (float) $value === 21050.0)
            ->where('issuer.legal_name', 'Buyer Company Limited')
        );
});

it('sends the purchase unit down with the line, not the stock unit', function () {
    // ⚠⚠ The whole point. This oil is bought in kg and held in L; an order that
    // says only "100" is an order somebody fills in the wrong unit.
    ($this->asOwner)()->get(route('purchasing.purchase-orders.print', $this->order))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('order.lines.0.product.purchase_unit.abbreviation', 'kg')
            ->where('order.lines.0.product.unit_of_measure.abbreviation', 'L')
        );
});

it('will not print an order belonging to another business', function () {
    $other = Business::create(['name' => 'Rival Buyer', 'slug' => 'rival-buyer']);

    DB::table('purchase_orders')->where('id', $this->order->id)->update(['business_id' => $other->id]);

    ($this->asOwner)()->get("/purchasing/purchase-orders/{$this->order->id}/print")->assertNotFound();
});

it('is shut to somebody who may not read purchasing at all', function () {
    $outsider = User::factory()->create();
    $outsider->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $outsider->assignRole('Outsider');

    $this->actingAs($outsider)->withSession(['current_business_id' => $this->business->id])
        ->get(route('purchasing.purchase-orders.print', $this->order))
        ->assertForbidden();
});
