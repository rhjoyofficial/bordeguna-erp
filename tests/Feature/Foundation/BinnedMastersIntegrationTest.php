<?php

use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductFamily;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Sales\Actions\CreateSalesOrder;
use Illuminate\Validation\ValidationException;
use Tests\Support\SalesWorld;

/**
 * ⭐ Integration (25 Oct 2026), IP15's Recycle bin meets the older lookups and
 * IP11's families: an unscoped lookup never finds a binned row, and a size
 * whose family is in the bin is restored only after the family.
 */
beforeEach(function () {
    $this->withoutVite();
    $this->s = SalesWorld::make();
    $this->why = new ActionReason(null, 'Entered by mistake');
});

it('refuses a binned brand on a new order', function () {
    $brand = Brand::create(['business_id' => $this->s->w->business->id, 'code' => 'OLD', 'name' => 'Old brand', 'slug' => 'old-brand']);
    $brand->recycle($this->s->w->owner, $this->why);

    expect(fn () => app(CreateSalesOrder::class)->handle(
        customer: $this->s->customer,
        lines: [['product' => $this->s->honey, 'quantity' => '1', 'unit_price' => '850']],
        createdBy: $this->s->w->owner,
        warehouse: $this->s->warehouse,
        orderedAt: '2026-10-10',
        brandId: $brand->id,
    ))->toThrow(ValidationException::class, 'Recycle bin');
});

it('restores a binned size only after its binned family, and says so', function () {
    $business = $this->s->w->business->id;
    $unit = UnitOfMeasure::query()->firstOrFail();
    $family = ProductFamily::create(['business_id' => $business, 'name' => 'Miniket Rice', 'sku' => 'MINIKET', 'attribute_name' => 'Packaging size']);
    $size = Product::create([
        'business_id' => $business, 'unit_of_measure_id' => $unit->id, 'sku' => 'MINIKET-25', 'name' => 'Miniket Rice 25 kg',
        'type' => ProductType::FinishedGood,
    ]);
    // Written only by the family actions, never mass-assigned.
    $size->forceFill(['product_family_id' => $family->id, 'family_attribute_value' => '25 kg'])->save();

    $size->recycle($this->s->w->owner, $this->why);
    $family->fresh()->recycle($this->s->w->owner, $this->why);

    $binned = Product::onlyTrashed()->findOrFail($size->id);
    expect($binned->whyNotRestorable())->toContain('Miniket Rice')
        ->and(fn () => $binned->restoreFromBin($this->s->w->owner, $this->why))->toThrow(ValidationException::class);

    ProductFamily::onlyTrashed()->findOrFail($family->id)->restoreFromBin($this->s->w->owner, $this->why);
    Product::onlyTrashed()->findOrFail($size->id)->restoreFromBin($this->s->w->owner, $this->why);

    expect(Product::query()->find($size->id))->not->toBeNull();
});
