<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Sales\database\seeders\PriceGroupSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PriceGroup;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The customer list in and out of a spreadsheet.
 *
 * ⚠⚠ The importer's primary product is the PLAN, not the write. Most of what is
 * tested here is what the tool REFUSES and what it reports, because that is
 * what makes a file safe to run against a live customer list.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Cust Co', 'slug' => 'cust-co', 'fiscal_year_start_month' => 7]);

    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new SalesPermissionsSeeder)->run();
    (new PriceGroupSeeder)->run();
    (new WalkInCustomerSeeder)->run();

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->staff->assignRole('Staff');

    // ⚠ Re-entered AFTER the seeders. They walk every business and hand the
    // context back to whatever held it before, which leaves it null here, and
    // then every scoped query in the test body reads an empty table.
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);

    $this->asOwner = fn () => $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id]);

    // A file with the full header, so a test only has to say the rows.
    $this->file = function (string $body, ?string $header = null): UploadedFile {
        $header ??= 'code,name,type,contact_name,phone,email,address,price_group,payment_terms,credit_limit,bin,tin,note,is_active';
        $path = tempnam(sys_get_temp_dir(), 'cust').'.csv';
        file_put_contents($path, $header."\n".$body);

        return new UploadedFile($path, 'customers.csv', 'text/csv', null, true);
    };

    $this->review = fn (UploadedFile $file) => ($this->asOwner)()
        ->post(route('sales.customers.import.review'), ['file' => $file]);
});

// ── Review writes nothing ───────────────────────────────────────────────────

it('shows what a file would do and writes nothing', function () {
    ($this->review)(($this->file)("CUS-0001,Rahim Store,retail,,01711000000,,,,,,,,,\n"))
        ->assertRedirect()
        ->assertSessionHas('customerImportPlan', fn ($plan) => count($plan['creates']) === 1
            && $plan['creates'][0]['code'] === 'CUS-0001'
            && $plan['errors'] === []);

    TenantScope::enter($this->business->id);
    expect(Customer::where('code', 'CUS-0001')->exists())->toBeFalse();
});

it('refuses to write anything until a file has been reviewed', function () {
    ($this->asOwner)()->post(route('sales.customers.import.apply'))
        ->assertSessionHasErrors('file');

    expect(Customer::where('code', 'CUS-0001')->exists())->toBeFalse();
});

// ── The write itself ────────────────────────────────────────────────────────

it('creates customers with their type and price group, and leaves the credit limit to a Credit terms request', function () {
    ($this->review)(($this->file)(
        "CUS-0001,Rahim Store,dealer,Rahim Uddin,01711000000,rahim@example.com,Mirpur,DISTRIBUTOR,30 days,50000,,,First dealer,active\n"
    ));

    ($this->asOwner)()->post(route('sales.customers.import.apply'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    TenantScope::enter($this->business->id);
    $customer = Customer::where('code', 'CUS-0001')->sole();

    expect($customer->name)->toBe('Rahim Store')
        ->and($customer->type)->toBe(CustomerType::Dealer)
        ->and($customer->contact_name)->toBe('Rahim Uddin')
        ->and($customer->price_group_id)->toBe(PriceGroup::where('code', 'DISTRIBUTOR')->value('id'))
        // ⭐ 26 Sep 2026 (Q16): the plain import no longer writes a limit;
        // credit terms are granted through Sales > Credit terms, with approval.
        ->and($customer->credit_limit)->toBeNull()
        ->and($customer->payment_terms)->toBe('30 days')
        ->and($customer->is_active)->toBeTrue()
        // Derived, never taken from the file: it is what finds the same person
        // twice, and the typed original is kept as typed.
        ->and($customer->phone_normalized)->toBe('01711000000');
});

it('treats a blank type as retail', function () {
    ($this->review)(($this->file)("CUS-0002,Karim,,,,,,,,,,,,\n"));
    ($this->asOwner)()->post(route('sales.customers.import.apply'));

    TenantScope::enter($this->business->id);
    expect(Customer::where('code', 'CUS-0002')->sole()->type)->toBe(CustomerType::Retail);
});

it('updates an existing customer without touching what the file leaves blank', function () {
    $existing = Customer::create([
        'business_id' => $this->business->id,
        'code' => 'CUS-0003',
        'name' => 'Old Name',
        'type' => CustomerType::Retail,
        'phone' => '01711000001',
        'credit_limit' => 25000,
        'note' => 'Knows Musa',
    ]);

    ($this->review)(($this->file)("CUS-0003,New Name,retail,,,,,,,,,,,\n"));
    ($this->asOwner)()->post(route('sales.customers.import.apply'))->assertSessionHasNoErrors();

    TenantScope::enter($this->business->id);
    $customer = $existing->fresh();

    // ⚠⚠ C-CUS-5. A blank cell says nothing; it does not erase. Otherwise an
    // export with one column accidentally cleared wipes every credit limit in
    // the business on the way back in.
    expect($customer->name)->toBe('New Name')
        ->and($customer->phone)->toBe('01711000001')
        ->and((float) $customer->credit_limit)->toBe(25000.0)
        ->and($customer->note)->toBe('Knows Musa');
});

it('reports a row that matches the list exactly as unchanged', function () {
    Customer::create([
        'business_id' => $this->business->id,
        'code' => 'CUS-0004',
        'name' => 'Same Store',
        'type' => CustomerType::Retail,
    ]);

    ($this->review)(($this->file)("CUS-0004,Same Store,retail,,,,,,,,,,,\n"))
        ->assertSessionHas('customerImportPlan', fn ($plan) => $plan['unchanged'] === 1
            && $plan['creates'] === [] && $plan['updates'] === []);
});

// ── What it refuses ─────────────────────────────────────────────────────────

it('refuses a row with no code, because a second import could not recognise it', function () {
    ($this->review)(($this->file)(",Nameless Buyer,retail,,,,,,,,,,,\n"))
        ->assertSessionHas('customerImportPlan', fn ($plan) => count($plan['errors']) === 1
            && str_contains($plan['errors'][0], 'needs a code'));
});

it('refuses the same code twice in one file', function () {
    ($this->review)(($this->file)("CUS-9,First,retail,,,,,,,,,,,\nCUS-9,Second,retail,,,,,,,,,,,\n"))
        ->assertSessionHas('customerImportPlan', fn ($plan) => count($plan['errors']) === 1
            && str_contains($plan['errors'][0], 'identifies exactly one customer'));
});

it('refuses a price group that does not exist rather than quietly billing at retail', function () {
    ($this->review)(($this->file)("CUS-0005,Big Dealer,dealer,,,,,WHOLESALE,,,,,,\n"))
        ->assertSessionHas('customerImportPlan', fn ($plan) => count($plan['errors']) === 1
            && str_contains($plan['errors'][0], 'no price group with the code "WHOLESALE"'));

    ($this->asOwner)()->post(route('sales.customers.import.apply'))->assertSessionHasErrors('file');

    TenantScope::enter($this->business->id);
    expect(Customer::where('code', 'CUS-0005')->exists())->toBeFalse();
});

it('refuses to let a spreadsheet touch the walk-in counter customer', function () {
    // ⚠⚠ Every counter sale resolves through this row by its code. A file that
    // could rename or retype it would break the till, and the failure would
    // appear at the till rather than on the screen that caused it.
    $walkIn = Customer::walkIn();

    ($this->review)(($this->file)("{$walkIn->code},Renamed Counter,retail,,,,,,,,,,,\n"))
        ->assertSessionHas('customerImportPlan', fn ($plan) => count($plan['errors']) === 1
            && str_contains($plan['errors'][0], 'walk-in counter customer'));

    expect($walkIn->fresh()->name)->toBe($walkIn->name)
        ->and($walkIn->fresh()->type)->toBe(CustomerType::WalkIn);
});

it('refuses walk_in as a type on any row', function () {
    ($this->review)(($this->file)("CUS-0006,Pretender,walk_in,,,,,,,,,,,\n"))
        ->assertSessionHas('customerImportPlan', fn ($plan) => count($plan['errors']) === 1
            && str_contains($plan['errors'][0], 'type must be retail'));
});

it('refuses a credit limit that is not an amount', function () {
    ($this->review)(($this->file)("CUS-0007,Odd Money,retail,,,,,,,about 5000,,,,\n"))
        ->assertSessionHas('customerImportPlan', fn ($plan) => count($plan['errors']) === 1
            && str_contains($plan['errors'][0], 'credit_limit'));
});

it('refuses a file whose columns it does not understand', function () {
    ($this->review)(($this->file)("CUS-0008,Someone\n", 'code,name,mobile'))
        ->assertSessionHasErrors('file');
});

// ── What it warns about, without stopping ───────────────────────────────────

// ⭐ 25 Oct 2026 (Q13 = A): a dealer is priced in DISTRIBUTOR now; Dealer is retired.
it('warns when a dealer has no price group, because they are priced in DISTRIBUTOR and refused without a DISTRIBUTOR price', function () {
    // This is exactly the F2 finding the cutover preflight reports.
    ($this->review)(($this->file)("CUS-0009,Quiet Dealer,dealer,,,,,,,,,,,\n"))
        ->assertSessionHas('customerImportPlan', fn ($plan) => $plan['errors'] === []
            && count($plan['warnings']) === 1
            && str_contains($plan['warnings'][0], 'priced in DISTRIBUTOR'));
});

it('does not warn about a retail customer with no price group', function () {
    ($this->review)(($this->file)("CUS-0010,Ordinary Buyer,retail,,,,,,,,,,,\n"))
        ->assertSessionHas('customerImportPlan', fn ($plan) => $plan['warnings'] === []);
});

it('warns when a phone already belongs to somebody else but never merges them', function () {
    Customer::create([
        'business_id' => $this->business->id,
        'code' => 'CUS-0011',
        'name' => 'Original Owner',
        'type' => CustomerType::Retail,
        'phone' => '01711000009',
    ]);

    // ⚠ A shared number is legitimate: a household, or a shop and its owner.
    // PhoneIdentity is explicit that the match warns and never blocks.
    ($this->review)(($this->file)("CUS-0012,Second Person,retail,,+8801711000009,,,,,,,,,\n"))
        ->assertSessionHas('customerImportPlan', fn ($plan) => $plan['errors'] === []
            && count($plan['warnings']) === 1
            && str_contains($plan['warnings'][0], 'Original Owner'));

    ($this->asOwner)()->post(route('sales.customers.import.apply'))->assertSessionHasNoErrors();

    TenantScope::enter($this->business->id);
    expect(Customer::where('phone_normalized', '01711000009')->count())->toBe(2);
});

it('names the customers the file says nothing about and leaves them alone', function () {
    Customer::create([
        'business_id' => $this->business->id,
        'code' => 'CUS-OLD',
        'name' => 'Long Standing Buyer',
        'type' => CustomerType::Retail,
    ]);

    ($this->review)(($this->file)("CUS-NEW,Fresh Buyer,retail,,,,,,,,,,,\n"))
        ->assertSessionHas('customerImportPlan', fn ($plan) => in_array('CUS-OLD', $plan['absentFromFile'], true));

    ($this->asOwner)()->post(route('sales.customers.import.apply'));

    TenantScope::enter($this->business->id);
    expect(Customer::where('code', 'CUS-OLD')->sole()->is_active)->toBeTrue();
});

// ── The file round-trips ────────────────────────────────────────────────────

it('exports a file its own importer reads back as no change at all', function () {
    Customer::create([
        'business_id' => $this->business->id,
        'code' => 'CUS-0013',
        'name' => 'Round Trip Store',
        'type' => CustomerType::Dealer,
        'phone' => '01711000012',
        'credit_limit' => 12000,
        'price_group_id' => PriceGroup::where('code', 'DEALER')->value('id'),
    ]);

    $csv = ($this->asOwner)()->get(route('sales.customers.export'))->streamedContent();

    $path = tempnam(sys_get_temp_dir(), 'cust').'.csv';
    file_put_contents($path, $csv);

    // ⚠⚠ The walk-in row exports too and must come back as an error, not as a
    // silent no-op: the file is honest about what it cannot do.
    ($this->review)(new UploadedFile($path, 'customers.csv', 'text/csv', null, true))
        ->assertSessionHas('customerImportPlan', function ($plan) {
            $walkInOnly = count($plan['errors']) === 1 && str_contains($plan['errors'][0], 'walk-in');

            return $walkInOnly && $plan['creates'] === [] && $plan['updates'] === [] && $plan['unchanged'] === 1;
        });
});

// ── Who may do it ───────────────────────────────────────────────────────────

it('does not let a member without customer management import or export', function () {
    $asStaff = fn () => $this->actingAs($this->staff)
        ->withSession(['current_business_id' => $this->business->id]);

    $asStaff()->post(route('sales.customers.import.review'), ['file' => ($this->file)("CUS-X,X,retail,,,,,,,,,,,\n")])
        ->assertForbidden();
    $asStaff()->post(route('sales.customers.import.apply'))->assertForbidden();
    $asStaff()->get(route('sales.customers.export'))->assertForbidden();
    $asStaff()->get(route('sales.customers.template'))->assertForbidden();
});

it('imports credit days as the last column and refuses a figure that is not whole days', function () {
    // The new column is LAST so an old template still lines up: 14 old columns, then credit_days.
    $header = 'code,name,type,contact_name,phone,email,address,price_group,payment_terms,credit_limit,bin,tin,note,is_active,credit_days';

    ($this->review)(($this->file)("CUS-0020,Terms Dealer,dealer,,,,,DISTRIBUTOR,,,,,,,45\n", $header))
        ->assertSessionHas('customerImportPlan', fn ($plan) => $plan['errors'] === []);

    ($this->review)(($this->file)("CUS-0021,Odd Dealer,dealer,,,,,DISTRIBUTOR,,,,,,,forty\n", $header))
        ->assertSessionHas('customerImportPlan', fn ($plan) => count($plan['errors']) === 1 && str_contains($plan['errors'][0], 'credit_days'));
});

// CRM-014 follow-up (14 Oct 2026): the reviewed rows waited in the session with
// no business, so Apply after a business switch wrote them into a list whose
// diff nobody had read.
it('refuses to apply a customer import in a different business from the one it was reviewed in', function () {
    $other = Business::create(['name' => 'Second Co', 'slug' => 'second-cust-co', 'fiscal_year_start_month' => 7]);
    Role::create(['name' => 'Owner', 'guard_name' => 'web', 'business_id' => $other->id]);
    (new SalesPermissionsSeeder)->run();
    $this->owner->businesses()->attach($other->id);
    setPermissionsTeamId($other->id);
    $this->owner->assignRole('Owner');
    setPermissionsTeamId($this->business->id);

    ($this->review)(($this->file)("CUS-0001,Rahim Store,retail,,,,,,,,,,,active\n"))->assertSessionHasNoErrors();

    $this->actingAs($this->owner)->withSession(['current_business_id' => $other->id])
        ->post(route('sales.customers.import.apply'))
        ->assertSessionHasErrors('file');

    expect(Customer::withoutGlobalScopes()->where('code', 'CUS-0001')->exists())->toBeFalse();
});
