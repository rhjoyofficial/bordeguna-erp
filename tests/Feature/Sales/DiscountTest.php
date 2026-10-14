<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Sales\Actions\ResolveSellingPrice;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Discounts. Joy's own word for it: a place to set a lower price on any
 * product, with bulk handled by quantity rather than by inventing a bigger
 * pack (125 g is the pack; 500 g is four of them).
 *
 * ⚠⚠ THE POINT THESE TESTS DEFEND: `ResolveSellingPrice` knows nothing about
 * discounts and must keep knowing nothing. Every test below goes through the
 * ordinary resolver, so if somebody ever teaches it an `if` for discounts,
 * these still pass and the design has quietly died. The one that would catch
 * it is the last: strip the marking columns and prices resolve identically.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Disc Co', 'slug' => 'disc-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Permission::firstOrCreate(['name' => 'sales.prices.manage', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'sales.reports.view', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['sales.prices.manage', 'sales.reports.view']);
    Role::firstOrCreate(['name' => 'Staff', 'business_id' => $this->business->id, 'guard_name' => 'web']);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->retail = PriceGroup::create([
        'business_id' => $this->business->id,
        'code' => 'RETAIL', 'name' => 'Retail', 'prices_include_tax' => false, 'is_active' => true,
    ]);

    $unit = UnitOfMeasure::create([
        'business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs',
    ]);
    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'LLD-DRY-KAC-125G',
        'name' => 'Mourala Kachki 125 g',
        'type' => ProductType::FinishedGood,
        'is_active' => true,
    ]);

    // The list price: 280 a pack, from today.
    ProductPrice::create([
        'business_id' => $this->business->id,
        'product_id' => $this->product->id,
        'price_group_id' => $this->retail->id,
        'min_quantity' => 1,
        'unit_price' => 280,
        'effective_from' => now()->toDateString(),
        'is_discount' => false,
    ]);

    $this->customer = Customer::create([
        'business_id' => $this->business->id,
        'name' => 'Karim Traders',
        'price_group_id' => $this->retail->id,
    ]);
});

it('sells four packs cheaper without inventing a 500 g product', function () {
    $this->actingAs($this->owner)->post('/sales/discounts', [
        'product_id' => $this->product->id,
        'price_group_id' => $this->retail->id,
        'unit_price' => 262.50,
        'min_quantity' => 4,
        'effective_from' => now()->toDateString(),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $resolver = app(ResolveSellingPrice::class);

    // One pack still costs the list price.
    expect((float) $resolver->resolve($this->product, $this->customer, '1')->unitPrice)->toBe(280.0);

    // Four packs, which is how 500 g is sold, drop to the discount.
    expect((float) $resolver->resolve($this->product, $this->customer, '4')->unitPrice)->toBe(262.5);

    // And so does more than four.
    expect((float) $resolver->resolve($this->product, $this->customer, '10')->unitPrice)->toBe(262.5);
});

it('refuses a discount that is not actually lower than the list price', function () {
    $this->actingAs($this->owner)->post('/sales/discounts', [
        'product_id' => $this->product->id,
        'price_group_id' => $this->retail->id,
        'unit_price' => 300,
        'effective_from' => now()->toDateString(),
    ])->assertSessionHasErrors('unit_price');

    expect(ProductPrice::where('is_discount', true)->count())->toBe(0);
});

it('warns when a saved discount is not the price that would be charged', function () {
    // A newer list price starting the same day outranks an older discount,
    // because ranking reads effective_from before id. The operator has to be
    // told, or they quote a number the counter will not honour.
    $yesterday = now()->subDay()->toDateString();

    $this->actingAs($this->owner)->post('/sales/discounts', [
        'product_id' => $this->product->id,
        'price_group_id' => $this->retail->id,
        'unit_price' => 250,
        'effective_from' => $yesterday,
    ])->assertSessionHas('warning');
});

it('ends a discount by closing it rather than deleting it', function () {
    $this->actingAs($this->owner)->post('/sales/discounts', [
        'product_id' => $this->product->id,
        'price_group_id' => $this->retail->id,
        'unit_price' => 250,
        'effective_from' => now()->toDateString(),
    ])->assertSessionHasNoErrors();

    $discount = ProductPrice::where('is_discount', true)->firstOrFail();

    $this->actingAs($this->owner)
        ->post("/sales/discounts/{$discount->id}/close")
        ->assertRedirect()->assertSessionHasNoErrors();

    // Still there, so an invoice priced on it can still be explained.
    expect(ProductPrice::find($discount->id))->not->toBeNull()
        ->and(ProductPrice::find($discount->id)->effective_to)->not->toBeNull();
});

it('keeps a discount away from a customer group it was not set for', function () {
    $dealer = PriceGroup::create([
        'business_id' => $this->business->id,
        'code' => 'DEALER', 'name' => 'Dealer', 'prices_include_tax' => false, 'is_active' => true,
    ]);

    $this->actingAs($this->owner)->post('/sales/discounts', [
        'product_id' => $this->product->id,
        'price_group_id' => $this->retail->id,
        'unit_price' => 250,
        'effective_from' => now()->toDateString(),
    ])->assertSessionHasNoErrors();

    $dealerCustomer = Customer::create([
        'business_id' => $this->business->id,
        'name' => 'Dealer Bhai',
        'price_group_id' => $dealer->id,
    ]);

    // A dealer has no price at all here, and a retail discount must not leak
    // across to them.
    expect(app(ResolveSellingPrice::class)->find($this->product, $dealerCustomer, '1'))->toBeNull();
});

it('refuses a staff member', function () {
    $staff = User::factory()->create();
    $staff->businesses()->attach($this->business->id);
    $staff->assignRole('Staff');

    $this->actingAs($staff)->get('/sales/discounts')->assertForbidden();
    $this->actingAs($staff)->post('/sales/discounts', [
        'product_id' => $this->product->id,
        'unit_price' => 100,
        'effective_from' => now()->toDateString(),
    ])->assertForbidden();
});

it('resolves identically whether or not the row is marked as a discount', function () {
    $this->actingAs($this->owner)->post('/sales/discounts', [
        'product_id' => $this->product->id,
        'price_group_id' => $this->retail->id,
        'unit_price' => 250,
        'effective_from' => now()->addDay()->toDateString(),
    ])->assertSessionHasNoErrors();

    $resolver = app(ResolveSellingPrice::class);
    $tomorrow = now()->addDay();

    $withMarking = (float) $resolver->resolve($this->product, $this->customer, '1', $tomorrow)->unitPrice;

    // Strip the marking entirely. If the resolver ever starts reading it, this
    // number moves and the design has been broken.
    ProductPrice::where('is_discount', true)->update(['is_discount' => false, 'discount_label' => null]);

    $withoutMarking = (float) app(ResolveSellingPrice::class)
        ->resolve($this->product, $this->customer, '1', $tomorrow)->unitPrice;

    expect($withMarking)->toBe(250.0)->and($withoutMarking)->toBe($withMarking);
});

// Setting a discount is a popup over the list since 14 Oct 2026 (the form no
// longer sits open above it); ending one stays on its row. The page carries
// what the popup draws, including the list price the saving is worked out
// from, and the popup saves through the same route with the boxes it sends.
it('carries what the add-discount popup needs on the list, and saves through the same route', function () {
    $this->actingAs($this->owner)->get('/sales/discounts')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sales/discounts')
            ->where('on', now()->toDateString())
            ->where('products', fn ($products) => collect($products)->contains(
                fn ($p) => $p['id'] === $this->product->id && (float) $p['list_price'] === 280.0))
            ->where('groups', fn ($groups) => collect($groups)->contains(fn ($g) => $g['id'] === $this->retail->id && $g['code'] === 'RETAIL')));

    $this->actingAs($this->owner)->post('/sales/discounts', [
        'product_id' => (string) $this->product->id,
        'price_group_id' => (string) $this->retail->id,
        'unit_price' => '250',
        'min_quantity' => '1',
        'effective_from' => now()->toDateString(),
        'effective_to' => '',
        'discount_label' => 'Eid offer',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->actingAs($this->owner)->get('/sales/discounts')
        ->assertInertia(fn (Assert $page) => $page->where('discounts', fn ($discounts) => collect($discounts)->contains(
            fn ($d) => $d['label'] === 'Eid offer' && (float) $d['unit_price'] === 250.0 && $d['group'] === 'Retail' && $d['is_winning'] === true)));
});
