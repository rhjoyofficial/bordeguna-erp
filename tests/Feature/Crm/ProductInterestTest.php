<?php

use App\Models\User;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Crm\database\seeders\CrmPermissionsSeeder;
use App\Modules\Crm\Enums\InterestStatus;
use App\Modules\Crm\Enums\LeadStatus;
use App\Modules\Crm\Models\CrmProductInterest;
use App\Modules\Crm\Models\Lead;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * What somebody asked for, as a dated event.
 *
 * ⚠⚠ THE CLAIM UNDER TEST IS THAT THE REQUEST IS IMMUTABLE AND THE DEMAND
 * HISTORY SURVIVES. An earlier draft of this feature used one mutable row per
 * product, which would have destroyed the first statement every time somebody
 * made a second one. CRM-PHASE-B-ARCHITECTURE.md, C-CRMB-22..30.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-09 10:00:00');

    $this->business = Business::create(['name' => 'Int Co', 'slug' => 'int-co', 'fiscal_year_start_month' => 7]);
    $this->other = Business::create(['name' => 'Rival Co', 'slug' => 'rival-int-co', 'fiscal_year_start_month' => 7]);

    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new CrmPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    // ⚠ Returns the USER: actingAs() returns $this and mutates shared auth state.
    $this->asRole = function (string $role): User {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $user;
    };

    $this->owner = ($this->asRole)('Owner');

    $this->kg = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
    $this->piece = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pcs']);
    $this->category = Category::create(['business_id' => $this->business->id, 'name' => 'Honey', 'slug' => 'honey']);
    $this->brand = Brand::create(['business_id' => $this->business->id, 'code' => 'LLD', 'name' => 'LaLaDia', 'slug' => 'laladia']);

    $this->product = Product::create([
        'business_id' => $this->business->id, 'category_id' => $this->category->id,
        'brand_id' => $this->brand->id, 'unit_of_measure_id' => $this->kg->id,
        'sku' => 'MANGO-HIM', 'name' => 'Himsagar Mango', 'type' => ProductType::FinishedGood,
    ]);

    $this->lead = Lead::create([
        'business_id' => $this->business->id, 'name' => 'Rahim Traders',
        'phone' => '01712345678', 'status' => LeadStatus::Qualified,
    ]);
});

afterEach(fn () => Carbon::setTestNow());

// ── Recording what they asked for ───────────────────────────────────────────

it('records a product request with a quantity and the unit they said', function () {
    $this->actingAs($this->owner)->post("/crm/lead/{$this->lead->id}/interests", [
        'product_id' => $this->product->id,
        'requested_quantity' => '5',
        'requested_unit_id' => $this->kg->id,
        'note' => 'For Eid',
    ])->assertRedirect();

    $interest = CrmProductInterest::sole();

    expect($interest->product_id)->toBe($this->product->id)
        ->and((float) $interest->requested_quantity)->toBe(5.0)
        ->and($interest->requested_unit_id)->toBe($this->kg->id)
        ->and($interest->status)->toBe(InterestStatus::Open)
        ->and($interest->recorded_by)->toBe($this->owner->id);
});

it('records the unit they SAID, not the product stock unit', function () {
    // ⚠⚠ The product is stocked in KILOGRAMS. The customer said "2 packs".
    // Converting at capture would invent a number nobody stated, and storing
    // the stock unit instead would put words in their mouth.
    $this->actingAs($this->owner)->post("/crm/lead/{$this->lead->id}/interests", [
        'product_id' => $this->product->id,
        'requested_quantity' => '2',
        'requested_unit_id' => $this->piece->id,
    ])->assertRedirect();

    $interest = CrmProductInterest::sole();

    expect($interest->requested_unit_id)->toBe($this->piece->id)
        ->and($interest->product->unit_of_measure_id)->toBe($this->kg->id)
        ->and($interest->requested_unit_id)->not->toBe($interest->product->unit_of_measure_id);
});

it('records interest in a category or a brand, not only a product', function (string $field) {
    $id = $field === 'category_id' ? $this->category->id : $this->brand->id;

    // "Interested in honey" and "interested in LaLaDia" are genuine statements
    // made before a SKU is chosen.
    $this->actingAs($this->owner)
        ->post("/crm/lead/{$this->lead->id}/interests", [$field => $id])
        ->assertRedirect();

    expect(CrmProductInterest::sole()->{$field})->toBe($id);
})->with(['category_id', 'brand_id']);

it('records interest in an INACTIVE product', function () {
    // ⚠⚠ Somebody asking for last season's mango is the demand signal that
    // decides whether to stock it again. Refusing it would drop exactly the
    // information worth having.
    $this->product->update(['is_active' => false]);

    $this->actingAs($this->owner)
        ->post("/crm/lead/{$this->lead->id}/interests", ['product_id' => $this->product->id])
        ->assertRedirect();

    expect(CrmProductInterest::count())->toBe(1);
});

it('attaches to a customer as readily as to a lead', function () {
    $customer = Customer::create(['business_id' => $this->business->id, 'code' => 'C1', 'name' => 'Adabor Grocery']);

    $this->actingAs($this->owner)
        ->post("/crm/customer/{$customer->id}/interests", ['product_id' => $this->product->id])
        ->assertRedirect();

    expect(CrmProductInterest::sole()->subject_type)->toBe($customer->getMorphClass());
});

// ── ⚠⚠ The correction: history survives a second request ────────────────────

it('keeps every request as its own dated row', function () {
    // ⚠⚠ THE TEST THAT WOULD HAVE CAUGHT MY FIRST DESIGN. "Wants 2kg" in June
    // and "wants 10kg for Eid" in August are two different business facts, and
    // an updateOrCreate would have destroyed the first.
    $this->actingAs($this->owner)->post("/crm/lead/{$this->lead->id}/interests", [
        'product_id' => $this->product->id, 'requested_quantity' => '2',
        'requested_unit_id' => $this->kg->id, 'requested_at' => '2026-06-01 10:00:00',
    ])->assertRedirect();

    $this->actingAs($this->owner)->post("/crm/lead/{$this->lead->id}/interests", [
        'product_id' => $this->product->id, 'requested_quantity' => '10',
        'requested_unit_id' => $this->kg->id, 'note' => 'For Eid',
    ])->assertRedirect();

    $interests = CrmProductInterest::orderBy('requested_at')->get();

    expect($interests)->toHaveCount(2)
        ->and((float) $interests[0]->requested_quantity)->toBe(2.0)
        ->and($interests[0]->requested_at->toDateString())->toBe('2026-06-01')
        ->and((float) $interests[1]->requested_quantity)->toBe(10.0);
});

it('counts people, not rows, when asking who wants a product', function () {
    // ⚠ The concern that produced the wrong first design, answered correctly:
    // count DISTINCT SUBJECTS rather than overwrite history.
    foreach (['3', '7'] as $quantity) {
        $this->actingAs($this->owner)->post("/crm/lead/{$this->lead->id}/interests", [
            'product_id' => $this->product->id, 'requested_quantity' => $quantity,
            'requested_unit_id' => $this->kg->id,
        ]);
    }

    $rows = CrmProductInterest::open()->where('product_id', $this->product->id)->count();

    // ⚠ Counted with a plain grouped query rather than a driver-specific
    // CONCAT: this has to give the same answer on SQLite and MySQL.
    $people = CrmProductInterest::open()->where('product_id', $this->product->id)
        ->get(['subject_type', 'subject_id'])
        ->unique(fn ($row) => $row->subject_type.'#'.$row->subject_id)
        ->count();

    expect($rows)->toBe(2)->and($people)->toBe(1);
});

// ── C-CRMB-22: the request is immutable ─────────────────────────────────────

it('refuses to rewrite what was originally asked for', function () {
    $interest = CrmProductInterest::create([
        'business_id' => $this->business->id,
        'subject_type' => $this->lead->getMorphClass(), 'subject_id' => $this->lead->id,
        'product_id' => $this->product->id, 'requested_quantity' => '5',
        'requested_at' => now(),
    ]);

    // ⚠⚠ "Wants 10kg for Eid" was true when they said it, whatever happened
    // afterwards. Linking a sale or closing the interest must never rewrite it.
    expect(fn () => $interest->update(['requested_quantity' => '99']))
        ->toThrow(RuntimeException::class, 'cannot be changed');

    expect(fn () => $interest->update(['product_id' => 999]))
        ->toThrow(RuntimeException::class, 'cannot be changed');

    expect(fn () => $interest->delete())
        ->toThrow(RuntimeException::class, 'cannot be deleted');

    expect((float) $interest->fresh()->requested_quantity)->toBe(5.0);
});

it('allows the outcome to be appended without touching the request', function () {
    $this->actingAs($this->owner)->post("/crm/lead/{$this->lead->id}/interests", [
        'product_id' => $this->product->id, 'requested_quantity' => '5', 'requested_unit_id' => $this->kg->id,
    ]);

    $interest = CrmProductInterest::sole();

    $this->actingAs($this->owner)->put("/crm/interests/{$interest->id}/close", [
        'status' => 'unavailable', 'closed_note' => 'Out of season',
    ])->assertRedirect();

    $interest->refresh();

    expect($interest->status)->toBe(InterestStatus::Unavailable)
        ->and($interest->closed_note)->toBe('Out of season')
        ->and($interest->closed_by)->toBe($this->owner->id)
        // ⚠ And the original request is exactly as it was.
        ->and((float) $interest->requested_quantity)->toBe(5.0)
        ->and($interest->product_id)->toBe($this->product->id);
});

it('has no route that deletes an interest', function () {
    // ⚠⚠ A demand signal that quietly disappears is one nobody can audit.
    // Withdrawal is a recorded outcome, not an absence.
    $routes = collect(Route::getRoutes())->filter(
        fn ($r) => str_contains($r->uri(), 'interests') && in_array('DELETE', $r->methods(), true)
    );

    expect($routes)->toBeEmpty();
});

// ── Validation ──────────────────────────────────────────────────────────────

it('insists on exactly one of product, category or brand', function (array $payload) {
    // ⚠ Zero means the row says nothing; more than one crams three different
    // statements into one and every count over the table becomes ambiguous.
    $this->actingAs($this->owner)
        ->post("/crm/lead/{$this->lead->id}/interests", $payload)
        ->assertSessionHasErrors('product_id');

    expect(CrmProductInterest::count())->toBe(0);
})->with([
    'none of them' => [[]],
    'product and category' => [fn () => ['product_id' => 1, 'category_id' => 1]],
]);

it('refuses a unit with no quantity', function () {
    // ⚠⚠ "Kilograms" is not a request; "5 kilograms" is. Storing the unit alone
    // would put a figure on a report with nothing behind it.
    $this->actingAs($this->owner)->post("/crm/lead/{$this->lead->id}/interests", [
        'product_id' => $this->product->id, 'requested_unit_id' => $this->kg->id,
    ])->assertSessionHasErrors('requested_quantity');

    expect(CrmProductInterest::count())->toBe(0);
});

it('refuses a request dated in the future', function () {
    $this->actingAs($this->owner)->post("/crm/lead/{$this->lead->id}/interests", [
        'product_id' => $this->product->id, 'requested_at' => '2026-09-01 10:00:00',
    ])->assertSessionHasErrors('requested_at');
});

it('refuses a zero or negative quantity', function () {
    $this->actingAs($this->owner)->post("/crm/lead/{$this->lead->id}/interests", [
        'product_id' => $this->product->id, 'requested_quantity' => '0', 'requested_unit_id' => $this->kg->id,
    ])->assertSessionHasErrors('requested_quantity');
});

// ── Tenant isolation ────────────────────────────────────────────────────────

it('refuses a product, category, brand or unit from another business', function (string $field) {
    TenantScope::enter($this->other->id);
    $foreign = match ($field) {
        'product_id' => Product::create([
            'business_id' => $this->other->id,
            'unit_of_measure_id' => UnitOfMeasure::create(['business_id' => $this->other->id, 'name' => 'U', 'abbreviation' => 'u'])->id,
            'sku' => 'RIVAL-1', 'name' => 'Rival product', 'type' => ProductType::FinishedGood,
        ]),
        'category_id' => Category::create(['business_id' => $this->other->id, 'name' => 'Rival cat', 'slug' => 'rival-cat']),
        'brand_id' => Brand::create(['business_id' => $this->other->id, 'code' => 'RV', 'name' => 'Rival', 'slug' => 'rival']),
    };
    TenantScope::enter($this->business->id);

    $this->actingAs($this->owner)
        ->post("/crm/lead/{$this->lead->id}/interests", [$field => $foreign->id])
        ->assertSessionHasErrors($field);

    expect(CrmProductInterest::count())->toBe(0);
})->with(['product_id', 'category_id', 'brand_id']);

it('cannot record interest against another business\'s lead', function () {
    TenantScope::enter($this->other->id);
    $foreignLead = Lead::create(['business_id' => $this->other->id, 'name' => 'Rival lead', 'status' => 'new']);
    TenantScope::enter($this->business->id);

    // ⚠ 404, not a redirect: `lead` is a valid subject KIND, so the route
    // matches and the controller resolves the id through the scoped model,
    // which cannot see another tenant's row. Different refusal from an unknown
    // subject kind, which never matches a route at all.
    $this->actingAs($this->owner)
        ->post("/crm/lead/{$foreignLead->id}/interests", ['product_id' => $this->product->id])
        ->assertNotFound();

    TenantScope::enter($this->other->id);
    expect(CrmProductInterest::count())->toBe(0);
});

// ── Permissions, render, and the structural guard ───────────────────────────

it('keeps interest away from somebody with no CRM permission', function () {
    $outsider = User::factory()->create();
    $outsider->businesses()->attach($this->business->id);

    $this->actingAs($outsider)
        ->post("/crm/lead/{$this->lead->id}/interests", ['product_id' => $this->product->id])
        ->assertForbidden();
});

it('renders interest on the timeline with the pickers it needs', function () {
    $this->actingAs($this->owner)->post("/crm/lead/{$this->lead->id}/interests", [
        'product_id' => $this->product->id, 'requested_quantity' => '5', 'requested_unit_id' => $this->kg->id,
    ]);

    $this->actingAs($this->owner)->get("/crm/lead/{$this->lead->id}/timeline")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p
            ->component('crm/timeline')
            ->has('interests', 1)
            ->where('interests.0.what', 'Himsagar Mango')
            ->where('interests.0.quantity', '5')
            ->where('interests.0.unit', 'kg')
            ->has('products')
            ->has('categories')
            ->has('brands')
            ->has('units')
            ->etc());
});

it('creates no stock, price, invoice or journal', function () {
    $this->actingAs($this->owner)->post("/crm/lead/{$this->lead->id}/interests", [
        'product_id' => $this->product->id, 'requested_quantity' => '5', 'requested_unit_id' => $this->kg->id,
    ]);

    // ⛔ Interest is a statement about intent. It must not reach Inventory,
    // Sales or the ledger.
    expect(StockMovement::count())->toBe(0)
        ->and(ProductPrice::count())->toBe(0)
        ->and(SalesInvoice::count())->toBe(0)
        ->and(Journal::count())->toBe(0);
});

it('lets the screen set every request field the model can hold', function () {
    // ⚠⚠ The products.barcode failure class. A fillable field the screen cannot
    // set is dead in production.
    $this->actingAs($this->owner)->post("/crm/lead/{$this->lead->id}/interests", [
        'product_id' => $this->product->id,
        'requested_quantity' => '5',
        'requested_unit_id' => $this->kg->id,
        'note' => 'For Eid',
        'requested_at' => '2026-08-01 09:00:00',
    ])->assertRedirect();

    $interest = CrmProductInterest::sole();

    // category_id and brand_id are mutually exclusive with product_id, and the
    // outcome columns are set by closing rather than by recording.
    $expected = collect((new CrmProductInterest)->getFillable())->reject(fn ($f) => in_array($f, [
        'business_id', 'subject_type', 'subject_id', 'category_id', 'brand_id',
        'status', 'closed_at', 'closed_by', 'closed_note', 'fulfilled_by_invoice_id',
    ], true));

    $unsettable = $expected->reject(fn ($f) => filled($interest->{$f}))->values()->all();

    expect($unsettable)->toBe([], 'these cannot be set from the screen: '.implode(', ', $unsettable));
});
