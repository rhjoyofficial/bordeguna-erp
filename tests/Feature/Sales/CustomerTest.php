<?php

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PriceGroup;
use Illuminate\Database\QueryException;

/**
 * Sales slice 1: who we sell to.
 *
 * Joy's constraint: a customer must be able to become a retail customer, a
 * distributor, a dealer, a corporate account or a walk-in without the record
 * being redesigned later. See SALES-PHASE1-DESIGN.md §3.1.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Cust Co', 'slug' => 'cust-co']);
    app(BusinessContext::class)->set($this->business->id);
});

it('holds everything a distributor needs from the first migration', function () {
    $group = PriceGroup::create([
        'business_id' => $this->business->id,
        'code' => 'DISTRIBUTOR',
        'name' => 'Distributor',
    ]);

    $customer = Customer::create([
        'business_id' => $this->business->id,
        'code' => 'DIST-001',
        'name' => 'Karim Traders',
        'type' => CustomerType::Distributor,
        'contact_name' => 'Karim Uddin',
        'phone' => '01711000000',
        'email' => 'karim@example.com',
        'address' => 'Savar, Dhaka',
        'payment_terms' => 'Net 30',
        'credit_limit' => '500000',
        'price_group_id' => $group->id,
    ]);

    expect($customer->fresh()->type)->toBe(CustomerType::Distributor)
        ->and($customer->fresh()->credit_limit)->toBe('500000.0000')
        ->and($customer->fresh()->priceGroup->code)->toBe('DISTRIBUTOR');
});

it('defaults a new customer to retail and active', function () {
    $customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Someone']);

    expect($customer->fresh()->type)->toBe(CustomerType::Retail)
        ->and($customer->fresh()->is_active)->toBeTrue()
        ->and($customer->fresh()->is_system)->toBeFalse();
});

// ⚠ A distributor's code is how the sales team refers to them, and the same
// code twice is how two accounts quietly become one.
it('refuses the same customer code twice in one business', function () {
    Customer::create(['business_id' => $this->business->id, 'code' => 'DIST-001', 'name' => 'First']);
    Customer::create(['business_id' => $this->business->id, 'code' => 'DIST-001', 'name' => 'Second']);
})->throws(QueryException::class);

it('allows the same code in a different business', function () {
    Customer::create(['business_id' => $this->business->id, 'code' => 'DIST-001', 'name' => 'Ours']);

    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-cust-co']);
    app(BusinessContext::class)->set($other->id);

    $theirs = Customer::create(['business_id' => $other->id, 'code' => 'DIST-001', 'name' => 'Theirs']);

    expect($theirs->exists)->toBeTrue();
});

// ⚠ Walk-ins have no code, and more than one may exist. A unique index over a
// nullable column must not make the second one an error.
it('allows many customers with no code', function () {
    Customer::create(['business_id' => $this->business->id, 'name' => 'Nameless One']);
    Customer::create(['business_id' => $this->business->id, 'name' => 'Nameless Two']);

    expect(Customer::count())->toBe(2);
});

describe('the walk-in customer', function () {
    // ⚠⚠ A REAL ROW, not a null customer_id. A null forces every report and
    // every receivable query to special-case it, and makes a genuine counter
    // sale indistinguishable from one where somebody forgot to pick a customer.
    it('is planted for every business', function () {
        $second = Business::create(['name' => 'Second Co', 'slug' => 'second-cust-co']);

        (new WalkInCustomerSeeder)->run();

        app(BusinessContext::class)->set($this->business->id);
        expect(Customer::walkIn())->not->toBeNull()
            ->and(Customer::walkIn()->type)->toBe(CustomerType::WalkIn)
            ->and(Customer::walkIn()->is_system)->toBeTrue();

        app(BusinessContext::class)->set($second->id);
        expect(Customer::walkIn())->not->toBeNull();
    });

    // ⚠ Idempotent per ROW. This project has twice shipped a seeder that
    // skipped its whole body on finding its first record already present.
    it('is not duplicated when the seeder runs again', function () {
        (new WalkInCustomerSeeder)->run();
        (new WalkInCustomerSeeder)->run();

        app(BusinessContext::class)->set($this->business->id);

        expect(Customer::where('code', Customer::WALK_IN_CODE)->count())->toBe(1);
    });

    // ⚠ A getter that creates master data on read is how a test passes against
    // a row production does not have.
    it('is null rather than created on demand', function () {
        expect(Customer::walkIn())->toBeNull();
    });
});

// ⚠ The global business scope is what enforces this. The ACL bypass found on
// 2 August was exactly this shape: a query that looked scoped and was not.
it('never returns a customer from another business', function () {
    Customer::create(['business_id' => $this->business->id, 'name' => 'Ours']);

    $other = Business::create(['name' => 'Rival Co', 'slug' => 'rival-cust-co']);
    app(BusinessContext::class)->set($other->id);

    expect(Customer::count())->toBe(0);
});
