<?php

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Sales\Actions\ResolveSellingPrice;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Exceptions\NoSellingPriceException;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;
use Illuminate\Support\Carbon;

/**
 * Sales slice 1: the pricing engine, which is one table and one resolver.
 *
 * Joy's constraint was that pricing grow into customer, distributor,
 * promotional and quantity pricing without the engine being rewritten. These
 * tests are the evidence that each of those is a ROW rather than a branch: not
 * one of them adds a code path, and the resolver never learns what a promotion
 * is.
 *
 * See SALES-PHASE1-DESIGN.md §3.2.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Price Co', 'slug' => 'price-co']);
    app(BusinessContext::class)->set($this->business->id);

    $unit = UnitOfMeasure::create([
        'business_id' => $this->business->id,
        'name' => 'Pieces',
        'abbreviation' => 'pcs',
    ]);

    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-HONEY',
        'name' => 'Sundarban Honey 500g',
        'type' => ProductType::FinishedGood,
    ]);

    Carbon::setTestNow('2026-08-04 10:00:00');

    // ⚠ Groups are ROWS now (C-PRC-1), created here the way the seeder would.
    // The free-text version of these tests could not fail on a case mismatch,
    // which is exactly the defect the FK exists to make unrepresentable.
    $this->groups = collect(['RETAIL', 'DEALER', 'DISTRIBUTOR'])
        ->mapWithKeys(fn (string $code) => [$code => PriceGroup::create([
            'business_id' => $this->business->id,
            'code' => $code,
            'name' => ucfirst(mb_strtolower($code)),
        ])]);

    $this->price = function (array $attributes) {
        return ProductPrice::create(array_merge([
            'business_id' => $this->business->id,
            'product_id' => $this->product->id,
            'effective_from' => '2026-01-01',
        ], $attributes));
    };

    $this->resolver = app(ResolveSellingPrice::class);
});

afterEach(fn () => Carbon::setTestNow());

it('resolves the standard selling price when nothing more specific exists', function () {
    ($this->price)(['unit_price' => '850']);

    $resolved = $this->resolver->resolve($this->product);

    expect($resolved->unitPrice)->toBe('850.0000')
        ->and($resolved->tier())->toBe('default');
});

it('refuses to price a product nothing prices', function () {
    $this->resolver->resolve($this->product);
})->throws(NoSellingPriceException::class, 'Sundarban Honey 500g');

// ⚠ A zero would be far worse than an exception. A zero-price sale still posts
// cost of goods sold, so it balances perfectly and reads as a terrible margin
// months later rather than as the error it is.
it('returns null from find rather than inventing a price', function () {
    expect($this->resolver->find($this->product))->toBeNull();
});

it('prefers a price agreed with the customer over their group and the default', function () {
    $customer = Customer::create([
        'business_id' => $this->business->id,
        'name' => 'Rahim Stores',
        'type' => CustomerType::Dealer,
        'price_group_id' => $this->groups['DEALER']->id,
    ]);

    ($this->price)(['unit_price' => '850']);
    ($this->price)(['unit_price' => '780', 'price_group_id' => $this->groups['DEALER']->id]);
    ($this->price)(['unit_price' => '740', 'customer_id' => $customer->id]);

    $resolved = $this->resolver->resolve($this->product, $customer);

    expect($resolved->unitPrice)->toBe('740.0000')
        ->and($resolved->tier())->toBe('customer');
});

it('prefers a group price over the default', function () {
    $customer = Customer::create([
        'business_id' => $this->business->id,
        'name' => 'Karim Traders',
        'type' => CustomerType::Distributor,
        'price_group_id' => $this->groups['DISTRIBUTOR']->id,
    ]);

    ($this->price)(['unit_price' => '850']);
    ($this->price)(['unit_price' => '700', 'price_group_id' => $this->groups['DISTRIBUTOR']->id]);

    expect($this->resolver->resolve($this->product, $customer)->tier())->toBe('price_group');
});

it('ignores a group price for a customer who is not in that group', function () {
    $customer = Customer::create([
        'business_id' => $this->business->id,
        'name' => 'Walk-in',
        'type' => CustomerType::Retail,
    ]);

    ($this->price)(['unit_price' => '850']);
    ($this->price)(['unit_price' => '700', 'price_group_id' => $this->groups['DISTRIBUTOR']->id]);

    expect($this->resolver->resolve($this->product, $customer)->unitPrice)->toBe('850.0000');
});

// ⚠⚠ D-PRC-2: RETAIL IS THE DEFAULT GROUP. A customer with no group is priced
// as RETAIL, so a retail price entered as a RETAIL-group row reaches them -
// otherwise "the default group" would be a sentence in a design note and not a
// behaviour, and retail prices would need entering twice.
it('prices a customer with no group as RETAIL, the default group', function () {
    $customer = Customer::create([
        'business_id' => $this->business->id,
        'name' => 'Counter Regular',
        'type' => CustomerType::Retail,
    ]);

    ($this->price)(['unit_price' => '850']);
    ($this->price)(['unit_price' => '820', 'price_group_id' => $this->groups['RETAIL']->id]);

    $resolved = $this->resolver->resolve($this->product, $customer);

    expect($resolved->unitPrice)->toBe('820.0000')
        ->and($resolved->tier())->toBe('price_group');
});

// The mirror: a customer explicitly IN the RETAIL group prices identically to
// one in no group. Explicit choice and default must never diverge.
it('prices an explicit RETAIL member and a no-group customer identically', function () {
    $explicit = Customer::create([
        'business_id' => $this->business->id,
        'name' => 'Explicit Retail',
        'type' => CustomerType::Retail,
        'price_group_id' => $this->groups['RETAIL']->id,
    ]);
    $defaulted = Customer::create([
        'business_id' => $this->business->id,
        'name' => 'Defaulted Retail',
        'type' => CustomerType::Retail,
    ]);

    ($this->price)(['unit_price' => '850']);
    ($this->price)(['unit_price' => '820', 'price_group_id' => $this->groups['RETAIL']->id]);

    expect($this->resolver->resolve($this->product, $explicit)->unitPrice)
        ->toBe($this->resolver->resolve($this->product, $defaulted)->unitPrice);
});

it('ignores a price agreed with a different customer', function () {
    $mine = Customer::create(['business_id' => $this->business->id, 'name' => 'Mine', 'type' => CustomerType::Retail]);
    $theirs = Customer::create(['business_id' => $this->business->id, 'name' => 'Theirs', 'type' => CustomerType::Retail]);

    ($this->price)(['unit_price' => '850']);
    ($this->price)(['unit_price' => '600', 'customer_id' => $theirs->id]);

    expect($this->resolver->resolve($this->product, $mine)->unitPrice)->toBe('850.0000');
});

// ⚠ A price check with nobody at the counter must see only the default tier.
// Showing a negotiated rate to an anonymous enquiry is a pricing leak.
it('sees only the default tier when there is no customer', function () {
    $customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Big Buyer', 'type' => CustomerType::Corporate]);

    ($this->price)(['unit_price' => '850']);
    ($this->price)(['unit_price' => '600', 'customer_id' => $customer->id]);

    expect($this->resolver->resolve($this->product)->unitPrice)->toBe('850.0000');
});

it('applies a quantity break only once the line reaches it', function () {
    ($this->price)(['unit_price' => '850']);
    ($this->price)(['unit_price' => '800', 'min_quantity' => '12']);
    ($this->price)(['unit_price' => '750', 'min_quantity' => '48']);

    expect($this->resolver->resolve($this->product, null, '11')->unitPrice)->toBe('850.0000')
        ->and($this->resolver->resolve($this->product, null, '12')->unitPrice)->toBe('800.0000')
        ->and($this->resolver->resolve($this->product, null, '47')->unitPrice)->toBe('800.0000')
        ->and($this->resolver->resolve($this->product, null, '48')->unitPrice)->toBe('750.0000')
        ->and($this->resolver->resolve($this->product, null, '500')->unitPrice)->toBe('750.0000');
});

// ⚠ Quantities are decimal(15,4), so a quantity break may be fractional - half
// a kilo, two and a half dozen. Verified by breaking it: truncating the scale
// makes a 2.5 break fire at 2. ℹ It does NOT prove integer scaling beats float
// comparison; a plain float compare passes this. Claimed no further than that,
// because this project has already had to correct a comment that said more than
// its test showed.
it('reaches a fractional quantity break exactly', function () {
    ($this->price)(['unit_price' => '850']);
    ($this->price)(['unit_price' => '800', 'min_quantity' => '2.5']);

    expect($this->resolver->resolve($this->product, null, '2.4999')->unitPrice)->toBe('850.0000')
        ->and($this->resolver->resolve($this->product, null, '2.5000')->unitPrice)->toBe('800.0000');
});

it('takes the deepest quantity break within the most specific tier', function () {
    $customer = Customer::create([
        'business_id' => $this->business->id,
        'name' => 'Bulk Dealer',
        'type' => CustomerType::Dealer,
        'price_group_id' => $this->groups['DEALER']->id,
    ]);

    // ⚠ The group price is cheaper than the customer's, and must still lose.
    // Specificity decides first, quantity second - otherwise a negotiated rate
    // silently stops applying the moment somebody buys a lot.
    ($this->price)(['unit_price' => '850']);
    ($this->price)(['unit_price' => '600', 'price_group_id' => $this->groups['DEALER']->id, 'min_quantity' => '48']);
    ($this->price)(['unit_price' => '740', 'customer_id' => $customer->id]);
    ($this->price)(['unit_price' => '700', 'customer_id' => $customer->id, 'min_quantity' => '48']);

    expect($this->resolver->resolve($this->product, $customer, '10')->unitPrice)->toBe('740.0000')
        ->and($this->resolver->resolve($this->product, $customer, '48')->unitPrice)->toBe('700.0000');
});

// ⚠⚠ THE GUARD FOR SPECIFICITY ITSELF, and the reason it exists: with the tier
// ordering deliberately removed, every other test in this file still passed,
// because in each of them the more specific row was also the newest and the id
// tiebreak picked it by accident.
//
// Here the negotiated price is OLDER and has a LOWER minimum than the general
// bulk break, so nothing but specificity can decide it. The business case is
// ordinary and expensive: announcing a bulk discount must not silently cancel
// every price a salesperson has already agreed with a dealer.
it('keeps a negotiated price when a later general bulk break would beat it', function () {
    $customer = Customer::create([
        'business_id' => $this->business->id,
        'name' => 'Long-standing Dealer',
        'type' => CustomerType::Dealer,
    ]);

    ($this->price)([
        'unit_price' => '740',
        'customer_id' => $customer->id,
        'effective_from' => '2026-01-01',
    ]);

    // Added months later, for everybody, at a deeper quantity.
    ($this->price)(['unit_price' => '850', 'effective_from' => '2026-01-01']);
    ($this->price)([
        'unit_price' => '780',
        'min_quantity' => '48',
        'effective_from' => '2026-07-01',
        'note' => 'General bulk offer',
    ]);

    $resolved = $this->resolver->resolve($this->product, $customer, '100');

    expect($resolved->unitPrice)->toBe('740.0000')
        ->and($resolved->tier())->toBe('customer');
});

// The same trap one tier down: a group rate must survive a newer general break.
it('keeps a group price when a later general break would beat it', function () {
    $customer = Customer::create([
        'business_id' => $this->business->id,
        'name' => 'Grouped Dealer',
        'type' => CustomerType::Dealer,
        'price_group_id' => $this->groups['DEALER']->id,
    ]);

    ($this->price)(['unit_price' => '780', 'price_group_id' => $this->groups['DEALER']->id, 'effective_from' => '2026-01-01']);
    ($this->price)(['unit_price' => '850', 'effective_from' => '2026-01-01']);
    ($this->price)(['unit_price' => '800', 'min_quantity' => '48', 'effective_from' => '2026-07-01']);

    expect($this->resolver->resolve($this->product, $customer, '100')->unitPrice)->toBe('780.0000');
});

// A promotion is a row with an end date. The resolver never learns the word.
it('applies a promotional price only inside its window', function () {
    ($this->price)(['unit_price' => '850']);
    ($this->price)([
        'unit_price' => '699',
        'effective_from' => '2026-08-01',
        'effective_to' => '2026-08-10',
        'note' => 'Eid promotion',
    ]);

    expect($this->resolver->resolve($this->product, null, '1', '2026-07-31')->unitPrice)->toBe('850.0000')
        ->and($this->resolver->resolve($this->product, null, '1', '2026-08-01')->unitPrice)->toBe('699.0000')
        ->and($this->resolver->resolve($this->product, null, '1', '2026-08-10')->unitPrice)->toBe('699.0000')
        ->and($this->resolver->resolve($this->product, null, '1', '2026-08-11')->unitPrice)->toBe('850.0000');
});

// ⚠⚠ NOT EffectiveDate::resolve. That helper refuses a future date and asserts
// the accounting period is open, which is right for moving stock and wrong for
// asking a question. Break this and next month's promotion cannot be checked.
it('prices a future date, because asking is not an event', function () {
    ($this->price)([
        'unit_price' => '650',
        'effective_from' => '2026-09-01',
        'effective_to' => '2026-09-30',
    ]);
    ($this->price)(['unit_price' => '850']);

    expect($this->resolver->resolve($this->product, null, '1', '2026-09-15')->unitPrice)->toBe('650.0000');
});

// ⚠ Superseding a price is a new row, never an edit - which is what lets an
// invoice raised in July still be explained in September.
it('takes the later rule when two of equal specificity are in force', function () {
    ($this->price)(['unit_price' => '850', 'effective_from' => '2026-01-01']);
    ($this->price)(['unit_price' => '900', 'effective_from' => '2026-07-01']);

    expect($this->resolver->resolve($this->product, null, '1', '2026-06-30')->unitPrice)->toBe('850.0000')
        ->and($this->resolver->resolve($this->product, null, '1', '2026-07-01')->unitPrice)->toBe('900.0000');
});

it('reports which rule produced the price', function () {
    $customer = Customer::create([
        'business_id' => $this->business->id,
        'name' => 'Explained Co',
        'type' => CustomerType::Dealer,
        'price_group_id' => $this->groups['DEALER']->id,
    ]);

    ($this->price)(['unit_price' => '780', 'price_group_id' => $this->groups['DEALER']->id, 'min_quantity' => '12']);

    $resolved = $this->resolver->resolve($this->product, $customer, '20');

    expect($resolved->explain())->toBe('Price for group [DEALER] from 12.0000 units')
        ->and($resolved->rule->id)->not->toBeNull();
});

// ⚠ The global business scope is what enforces this, and a test that never
// crosses a tenant boundary is a test that never checks it. The ACL bypass
// found on 2 August was exactly this shape.
it('never resolves a price belonging to another business', function () {
    ($this->price)(['unit_price' => '850']);

    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-co']);
    app(BusinessContext::class)->set($other->id);

    expect($this->resolver->find($this->product))->toBeNull();
});
