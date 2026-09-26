<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PriceGroup;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The customer screen, through the real HTTP layer.
 *
 * ⚠⚠ WHY THIS FILE EXISTS. `CustomerTest` proved that `contact_name`, `email`
 * and `address` all work - by calling `Customer::create()` directly. Every one
 * of those assertions passed while the SCREEN could not set a single one of
 * them: the three were declared in the form's state and posted as EMPTY STRINGS
 * on every save, with no input ever rendered, and `note` was not in the form at
 * all. The column existed, the controller validated it, and nothing could put a
 * value in it.
 *
 * That is the `products.barcode` failure of 4 August for the FOURTH time, after
 * barcode, brand_id and purchase_unit_id. The pattern is always the same: tests
 * that construct the model directly prove the database and prove nothing about
 * the screen.
 *
 * ⚠ `update()` and its PUT route also existed from the first slice with nothing
 * calling them - the same gap suppliers and products both had - so a customer
 * entered with a typo could never be corrected.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Customer Screen Co', 'slug' => 'customer-screen-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new WalkInCustomerSeeder)->run();
    (new SalesPermissionsSeeder)->run();

    $this->dealerGroup = PriceGroup::create([
        'business_id' => $this->business->id,
        'code' => 'DEALER',
        'name' => 'Dealer',
    ]);

    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $this->actingAs($user);
    };

    $this->full = [
        'name' => 'Karim Traders',
        'code' => 'KARIM',
        'type' => 'distributor',
        'contact_name' => 'Karim Uddin',
        'phone' => '01711000000',
        'email' => 'karim@example.com',
        // ⚠ The structural guard caught the absence of these the moment they
        // joined Customer::$fillable, which is exactly what it is for. Optional
        // for an individual consumer; a dealer, distributor or corporate
        // customer has them.
        'bin' => '004123456789',
        'tin' => '123456789012',
        'address' => 'Savar, Dhaka',
        'payment_terms' => 'Net 30',
        'credit_days' => '30',
        'credit_limit' => '500000',
        'price_group_id' => (string) $this->dealerGroup->id,
        'note' => 'Collects from the Savar warehouse.',
    ];
});

/**
 * ⚠⚠ THE STRUCTURAL GUARD, driven by the model rather than a hand-copied list.
 * Add a fillable field to Customer and forget the screen, and this fails. It is
 * the same guard Product and Supplier already carry, and its absence here is
 * why four fields drifted out of reach unnoticed.
 */
it('lets the screen set every field the model can hold', function () {
    ($this->asRole)('Manager')->post('/sales/customers', $this->full);

    $customer = Customer::where('code', 'KARIM')->firstOrFail();

    // business_id is set by the tenant scope; is_active by deactivation; and
    // is_system only ever by the seeder that plants the walk-in row.
    $expected = collect((new Customer)->getFillable())
        ->reject(fn ($f) => in_array($f, ['business_id', 'is_active', 'is_system'], true))
        ->values();

    expect($expected)->not->toBeEmpty();

    $unsettable = $expected->reject(fn ($f) => filled($customer->{$f}))->values()->all();

    expect($unsettable)->toBe([], 'these fields cannot be set from the screen: '.implode(', ', $unsettable));
});

it('creates a customer with the four fields that were previously unreachable', function () {
    ($this->asRole)('Manager')->post('/sales/customers', $this->full)->assertRedirect();

    $customer = Customer::where('code', 'KARIM')->firstOrFail();

    expect($customer->bin)->toBe('004123456789')
        ->and($customer->tin)->toBe('123456789012')
        ->and($customer->contact_name)->toBe('Karim Uddin')
        ->and($customer->email)->toBe('karim@example.com')
        ->and($customer->address)->toBe('Savar, Dhaka')
        ->and($customer->note)->toBe('Collects from the Savar warehouse.')
        ->and($customer->is_active)->toBeTrue()
        ->and($customer->is_system)->toBeFalse();
});

it('updates every field through the screen', function () {
    ($this->asRole)('Manager')->post('/sales/customers', $this->full);
    $customer = Customer::where('code', 'KARIM')->firstOrFail();

    $changed = array_merge($this->full, [
        'name' => 'Karim Traders Ltd',
        'contact_name' => 'Karim Uddin Chowdhury',
        'email' => 'accounts@karimtraders.com',
        'address' => 'Plot 14, Savar, Dhaka',
        'note' => 'Collects fortnightly.',
        'credit_limit' => '750000',
    ]);

    ($this->asRole)('Manager')
        ->put("/sales/customers/{$customer->id}", $changed)
        ->assertRedirect();

    $customer->refresh();

    expect($customer->name)->toBe('Karim Traders Ltd')
        ->and($customer->contact_name)->toBe('Karim Uddin Chowdhury')
        ->and($customer->email)->toBe('accounts@karimtraders.com')
        ->and($customer->address)->toBe('Plot 14, Savar, Dhaka')
        ->and($customer->note)->toBe('Collects fortnightly.')
        ->and((string) $customer->credit_limit)->toBe('750000.0000')
        // ⚠ The code is the import identity key and must survive an edit.
        ->and($customer->code)->toBe('KARIM')
        ->and(Customer::where('code', 'KARIM')->count())->toBe(1);
});

it('lets a customer keep its own code when edited', function () {
    ($this->asRole)('Manager')->post('/sales/customers', $this->full);
    $customer = Customer::where('code', 'KARIM')->firstOrFail();

    // Without the `ignore()` branch on the unique rule, saving without changing
    // the code would report the customer as a duplicate of itself.
    ($this->asRole)('Manager')
        ->put("/sales/customers/{$customer->id}", array_merge($this->full, ['name' => 'Renamed']))
        ->assertSessionHasNoErrors();

    expect($customer->fresh()->name)->toBe('Renamed');
});

it('refuses a duplicate code with a validation error', function () {
    ($this->asRole)('Manager')->post('/sales/customers', $this->full);

    ($this->asRole)('Manager')
        ->post('/sales/customers', array_merge($this->full, ['name' => 'Someone Else']))
        ->assertSessionHasErrors('code');

    expect(Customer::where('code', 'KARIM')->count())->toBe(1);
});

it('refuses an invalid email rather than storing it', function () {
    ($this->asRole)('Manager')
        ->post('/sales/customers', array_merge($this->full, ['email' => 'not-an-email']))
        ->assertSessionHasErrors('email');

    expect(Customer::where('code', 'KARIM')->exists())->toBeFalse();
});

// ── The walk-in row ─────────────────────────────────────────────────────────

it('refuses to change the walk-in code, because the counter resolves by it', function () {
    // ⚠⚠ `Customer::walkIn()` finds the counter customer by `code = 'WALK-IN'`.
    // Making edit mode reachable would otherwise let somebody rename that code
    // and break EVERY counter sale, with the failure appearing at the till
    // rather than on the screen that caused it.
    $walkIn = Customer::where('code', Customer::WALK_IN_CODE)->sole();

    ($this->asRole)('Manager')
        ->put("/sales/customers/{$walkIn->id}", [
            'name' => 'Counter Sale',
            'code' => 'COUNTER',
            'type' => 'walk_in',
        ])
        ->assertSessionHasErrors('code');

    expect($walkIn->fresh()->code)->toBe(Customer::WALK_IN_CODE)
        ->and(Customer::walkIn())->not->toBeNull();
});

it('refuses to reclassify the walk-in row', function () {
    $walkIn = Customer::where('code', Customer::WALK_IN_CODE)->sole();

    ($this->asRole)('Manager')
        ->put("/sales/customers/{$walkIn->id}", [
            'name' => 'Walk-in Customer',
            'code' => Customer::WALK_IN_CODE,
            'type' => 'distributor',
        ])
        ->assertSessionHasErrors('type');

    expect($walkIn->fresh()->type->value)->toBe('walk_in');
});

it('still lets the walk-in row be renamed', function () {
    // ⚠ Only its identity is frozen. A business may reasonably want the counter
    // customer to read "Counter Sale" on a receipt.
    $walkIn = Customer::where('code', Customer::WALK_IN_CODE)->sole();

    ($this->asRole)('Manager')
        ->put("/sales/customers/{$walkIn->id}", [
            'name' => 'Counter Sale',
            'code' => Customer::WALK_IN_CODE,
            'type' => 'walk_in',
            'address' => 'Uttar Adabor counter',
        ])
        ->assertSessionHasNoErrors();

    expect($walkIn->fresh()->name)->toBe('Counter Sale')
        ->and($walkIn->fresh()->address)->toBe('Uttar Adabor counter')
        ->and(Customer::walkIn())->not->toBeNull();
});

// ── Who may do what ─────────────────────────────────────────────────────────

it('stops Staff from creating or editing a customer', function () {
    // ⚠ The customer master carries the credit limit and the price group, so
    // editing it is a financial decision rather than an operational one.
    ($this->asRole)('Staff')->post('/sales/customers', $this->full)->assertForbidden();

    ($this->asRole)('Manager')->post('/sales/customers', $this->full);
    $customer = Customer::where('code', 'KARIM')->firstOrFail();

    ($this->asRole)('Staff')
        ->put("/sales/customers/{$customer->id}", array_merge($this->full, ['name' => 'Changed']))
        ->assertForbidden();

    expect($customer->fresh()->name)->toBe('Karim Traders');
});

it('keeps a customer inside its own business', function () {
    ($this->asRole)('Manager')->post('/sales/customers', $this->full);

    $customer = Customer::where('code', 'KARIM')->firstOrFail();

    expect($customer->business_id)->toBe($this->business->id);

    // ⚠ Another business may hold the same code. The unique index is per
    // business precisely so two tenants can both have a KARIM.
    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-customer-co']);
    app(BusinessContext::class)->set($other->id);

    expect(Customer::where('code', 'KARIM')->exists())->toBeFalse();
});

it('still renders the customer list after the bin/tin change', function () {
    // ⚠ Same gap as CRM: the write paths were tested, the render was not. The
    // index now serialises three new columns and a derived phone_normalized.
    ($this->asRole)('Manager')->post('/sales/customers', $this->full);

    ($this->asRole)('Manager')->get('/sales/customers')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('sales/customers/index')->has('customers.data', 2));
});

it('derives phone_normalized on a customer without touching the entered phone', function () {
    ($this->asRole)('Manager')->post('/sales/customers', array_merge($this->full, ['phone' => '+8801711000000']));

    $customer = Customer::where('code', 'KARIM')->sole();

    // ⚠⚠ The user's input is theirs. C-CRM-12.
    expect($customer->phone)->toBe('+8801711000000')
        ->and($customer->phone_normalized)->toBe('01711000000');
});

it('refuses to put a retail shop in the RETAIL group, and defaults it to SHOP when blank', function () {
    $retail = PriceGroup::create(['business_id' => $this->business->id, 'code' => 'RETAIL', 'name' => 'Retail', 'falls_back_to_mrp' => true]);
    PriceGroup::create(['business_id' => $this->business->id, 'code' => 'SHOP', 'name' => 'Retail shop']);
    ($this->asRole)('Owner')->from('/sales/customers')
        ->post('/sales/customers', ['name' => 'Corner Store', 'type' => 'shop', 'price_group_id' => $retail->id])
        ->assertSessionHasErrors('price_group_id');
    expect(Customer::where('name', 'Corner Store')->exists())->toBeFalse();

    ($this->asRole)('Owner')->from('/sales/customers')
        ->post('/sales/customers', ['name' => 'Corner Store', 'type' => 'shop', 'price_group_id' => ''])
        ->assertSessionHasNoErrors();

    $shop = Customer::where('name', 'Corner Store')->firstOrFail();
    expect($shop->type->value)->toBe('shop')->and($shop->price_group_id)->toBeNull();

    // A consumer may still sit in RETAIL by hand.
    ($this->asRole)('Owner')->from('/sales/customers')
        ->post('/sales/customers', ['name' => 'A Person', 'type' => 'retail', 'price_group_id' => $retail->id])
        ->assertSessionHasNoErrors();
});

it('saves credit days as a whole number of days and refuses nonsense', function () {
    ($this->asRole)('Owner')->from('/sales/customers')
        ->post('/sales/customers', ['name' => 'Rahim Traders', 'type' => 'dealer', 'credit_days' => '45', 'price_group_id' => (string) $this->dealerGroup->id])
        ->assertSessionHasNoErrors();

    $dealer = Customer::where('name', 'Rahim Traders')->firstOrFail();
    expect($dealer->credit_days)->toBe(45)->and($dealer->creditDays())->toBe(45);

    ($this->asRole)('Owner')->from('/sales/customers')
        ->post('/sales/customers', ['name' => 'Odd Terms', 'type' => 'dealer', 'credit_days' => 'thirty'])
        ->assertSessionHasErrors('credit_days');
    ($this->asRole)('Owner')->from('/sales/customers')
        ->post('/sales/customers', ['name' => 'Odd Terms', 'type' => 'dealer', 'credit_days' => '400'])
        ->assertSessionHasErrors('credit_days');

    // Blank is the default, 10, shown as such.
    ($this->asRole)('Owner')->from('/sales/customers')
        ->post('/sales/customers', ['name' => 'Plain Dealer', 'type' => 'dealer', 'credit_days' => ''])
        ->assertSessionHasNoErrors();
    $plain = Customer::where('name', 'Plain Dealer')->firstOrFail();
    expect($plain->credit_days)->toBeNull()->and($plain->creditDays())->toBe(10);

    $page = ($this->asRole)('Owner')->get('/sales/customers/'.$plain->id)->assertOk()->viewData('page')['props'];
    expect($page['customer']['credit_days'])->toBeNull()->and($page['customer']['credit_days_effective'])->toBe(10);
});
