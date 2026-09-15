<?php

use App\Models\User;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Crm\database\seeders\CrmPermissionsSeeder;
use App\Modules\Crm\Enums\ActivityType;
use App\Modules\Crm\Enums\LeadStatus;
use App\Modules\Crm\Models\CrmActivity;
use App\Modules\Crm\Models\CrmAttribution;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadSource;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * A prospect becomes a customer.
 *
 * ⚠⚠ CONVERSION IS THE ONE LEAD CHANGE WITH A CONSEQUENCE OUTSIDE CRM. Every
 * conversion rate, campaign ROI and funnel figure is computed from
 * `converted_customer_id`, so most of this file tests what conversion REFUSES
 * to do, not that it can create a row.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-09 10:00:00');

    $this->business = Business::create(['name' => 'Conv Co', 'slug' => 'conv-co', 'fiscal_year_start_month' => 7]);
    $this->other = Business::create(['name' => 'Rival Co', 'slug' => 'rival-conv-co', 'fiscal_year_start_month' => 7]);

    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new CrmPermissionsSeeder)->run();
    (new WalkInCustomerSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    // ⚠ Returns the USER. actingAs() returns $this and mutates shared auth
    // state, so a stored instance silently acts as whoever authenticated last.
    $this->asRole = function (string $role): User {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $user;
    };

    $this->owner = ($this->asRole)('Owner');

    $this->source = LeadSource::create([
        'business_id' => $this->business->id, 'code' => 'FB', 'name' => 'Facebook',
    ]);

    $this->lead = Lead::create([
        'business_id' => $this->business->id, 'name' => 'Rahim Traders',
        'phone' => '01712345678', 'status' => LeadStatus::Qualified,
    ]);
});

afterEach(fn () => Carbon::setTestNow());

// ── Creating a new customer ─────────────────────────────────────────────────

it('converts a lead into a new customer', function () {
    $this->actingAs($this->owner)
        ->post("/crm/leads/{$this->lead->id}/convert", ['code' => 'RAHIM', 'name' => 'Rahim Traders'])
        ->assertRedirect();

    $customer = Customer::where('code', 'RAHIM')->sole();
    $lead = $this->lead->fresh();

    expect($lead->status)->toBe(LeadStatus::Converted)
        ->and($lead->converted_customer_id)->toBe($customer->id)
        ->and($lead->converted_at->toDateTimeString())->toBe('2026-08-09 10:00:00')
        // Details carry across from the lead when not overridden.
        ->and($customer->phone)->toBe('01712345678')
        ->and($customer->is_system)->toBeFalse()
        ->and($customer->is_active)->toBeTrue();
});

it('creates no accounting, stock or sales record', function () {
    $this->actingAs($this->owner)
        ->post("/crm/leads/{$this->lead->id}/convert", ['code' => 'RAHIM'])
        ->assertRedirect();

    // ⚠⚠ Conversion is a CRM lifecycle statement. The first sale is a separate
    // act, and a conversion that quietly posted anything would put revenue in
    // the books for a customer who has bought nothing.
    expect(Journal::count())->toBe(0)
        ->and(JournalLine::count())->toBe(0)
        ->and(SalesInvoice::count())->toBe(0)
        ->and(ProductPrice::count())->toBe(0)
        ->and(StockMovement::count())->toBe(0);
});

// ── Linking to an existing customer ─────────────────────────────────────────

it('links to an existing customer instead of creating a second one', function () {
    $existing = Customer::create([
        'business_id' => $this->business->id, 'code' => 'RAHIM', 'name' => 'Rahim Traders',
    ]);

    $this->actingAs($this->owner)
        ->post("/crm/leads/{$this->lead->id}/convert", ['customer_id' => $existing->id])
        ->assertRedirect();

    // ⚠⚠ One person, one customer record. A duplicate would split their
    // purchase history and their receivable in two.
    expect(Customer::where('code', 'RAHIM')->count())->toBe(1)
        ->and($this->lead->fresh()->converted_customer_id)->toBe($existing->id);
});

it('refuses to create a customer whose phone already belongs to somebody', function () {
    Customer::create([
        'business_id' => $this->business->id, 'code' => 'EXISTING',
        'name' => 'Already here', 'phone' => '01712345678',
    ]);

    // ⚠⚠ The screen offers matches before the click, but between offering and
    // clicking somebody else may create the same customer. Without this
    // last-moment check the two paths race and the business gets one person
    // twice.
    $this->actingAs($this->owner)
        ->post("/crm/leads/{$this->lead->id}/convert", ['code' => 'RAHIM'])
        ->assertSessionHasErrors('phone');

    expect(Customer::count())->toBe(2) // WALK-IN + the existing one
        ->and($this->lead->fresh()->converted_customer_id)->toBeNull();
});

it('refuses a duplicate customer code', function () {
    Customer::create(['business_id' => $this->business->id, 'code' => 'RAHIM', 'name' => 'Someone else']);

    $this->actingAs($this->owner)
        ->post("/crm/leads/{$this->lead->id}/convert", ['code' => 'rahim', 'name' => 'Rahim'])
        ->assertSessionHasErrors('code');

    expect($this->lead->fresh()->status)->toBe(LeadStatus::Qualified);
});

it('refuses a new customer with no code, because a code is permanent', function () {
    // ⚠ Never machine-generated: an invented identifier is one nobody
    // recognises out loud. Suppliers settled this rule on 9 August.
    $this->actingAs($this->owner)
        ->post("/crm/leads/{$this->lead->id}/convert", ['name' => 'Rahim Traders'])
        ->assertSessionHasErrors('code');

    expect(Customer::count())->toBe(1); // WALK-IN only
});

// ── Idempotence and lifecycle ───────────────────────────────────────────────

it('cannot be converted twice', function () {
    $this->actingAs($this->owner)->post("/crm/leads/{$this->lead->id}/convert", ['code' => 'RAHIM']);

    // ⚠⚠ A REFUSAL, not a silent no-op. Converting twice would either create a
    // second customer for one prospect or quietly repoint the first, and the
    // person clicking would believe both worked.
    $this->actingAs($this->owner)
        ->post("/crm/leads/{$this->lead->id}/convert", ['code' => 'RAHIM-2'])
        ->assertSessionHasErrors('lead');

    expect(Customer::where('code', 'like', 'RAHIM%')->count())->toBe(1);
});

it('refuses to convert a lost lead without reopening it', function () {
    $this->lead->update(['status' => LeadStatus::Lost]);

    // ⚠ Going straight from lost to converted destroys the fact that it was
    // ever lost, which is exactly what a funnel report needs.
    $this->actingAs($this->owner)
        ->post("/crm/leads/{$this->lead->id}/convert", ['code' => 'RAHIM'])
        ->assertSessionHasErrors('lead');

    expect($this->lead->fresh()->converted_customer_id)->toBeNull();
});

it('cannot be edited back into another lifecycle state once converted', function () {
    $this->actingAs($this->owner)->post("/crm/leads/{$this->lead->id}/convert", ['code' => 'RAHIM']);

    // ⚠⚠ Otherwise somebody could change which campaign a PAYING customer came
    // from after the fact - the retrospective rewrite this design forbids.
    $this->actingAs($this->owner)
        ->put("/crm/leads/{$this->lead->id}", ['name' => 'Renamed', 'status' => 'qualified'])
        ->assertSessionHasErrors('status');

    expect($this->lead->fresh()->status)->toBe(LeadStatus::Converted);
});

it('cannot have its conversion claimed by ordinary editing', function () {
    // ⚠⚠ converted_customer_id and converted_at are UNFILLABLE. Before this
    // slice they were fillable, so any mass assignment could have claimed a
    // conversion with nothing behind it.
    $customer = Customer::create(['business_id' => $this->business->id, 'code' => 'SNEAK', 'name' => 'Sneaky']);

    $this->lead->fill([
        'converted_customer_id' => $customer->id,
        'converted_at' => now(),
    ])->save();

    $lead = $this->lead->fresh();
    expect($lead->converted_customer_id)->toBeNull()
        ->and($lead->converted_at)->toBeNull()
        ->and($lead->status)->toBe(LeadStatus::Qualified);
});

// ── History is preserved, never copied ──────────────────────────────────────

it('preserves the lead, its activities and its attribution, and keeps them reportable', function () {
    CrmAttribution::create([
        'business_id' => $this->business->id,
        'subject_type' => $this->lead->getMorphClass(), 'subject_id' => $this->lead->id,
        'lead_source_id' => $this->source->id, 'utm_campaign' => 'eid-honey',
        // ⚠ Required: an attribution records WHEN somebody came from a source,
        // and a campaign report with no date cannot be windowed.
        'occurred_at' => '2026-08-01 09:00:00',
    ]);

    CrmActivity::create([
        'business_id' => $this->business->id,
        'subject_type' => $this->lead->getMorphClass(), 'subject_id' => $this->lead->id,
        'type' => ActivityType::Call, 'occurred_at' => '2026-08-01 09:00:00', 'summary' => 'First call',
    ]);

    $this->actingAs($this->owner)->post("/crm/leads/{$this->lead->id}/convert", ['code' => 'RAHIM']);

    $customer = Customer::where('code', 'RAHIM')->sole();

    // ⚠⚠ NOTHING IS COPIED AND NOTHING IS DESTROYED. Copying would make "when
    // did we first speak" answerable twice, differently, and the two answers
    // would drift.
    expect(Lead::count())->toBe(1)
        ->and(CrmActivity::count())->toBe(1)
        ->and(CrmActivity::sole()->subject_type)->toBe($this->lead->getMorphClass())
        ->and(CrmAttribution::count())->toBe(1)
        ->and(CrmAttribution::sole()->subject_id)->toBe($this->lead->id);

    // ⚠ And it is still REPORTABLE: campaign ROI for a paying customer is read
    // by following the link rather than by duplicating the data.
    expect($customer->leads)->toHaveCount(1)
        ->and($customer->leads->flatMap->attributions->pluck('utm_campaign')->all())->toBe(['eid-honey']);
});

// ── WALK-IN and tenant protections ──────────────────────────────────────────

it('refuses to convert a lead into the WALK-IN customer', function () {
    $walkIn = Customer::where('code', Customer::WALK_IN_CODE)->sole();

    // ⚠⚠ WALK-IN is the ANONYMOUS counter customer. Converting a named prospect
    // into it would attach one person's identity, campaign and call history to
    // every counter sale the business has ever made.
    $this->actingAs($this->owner)
        ->post("/crm/leads/{$this->lead->id}/convert", ['customer_id' => $walkIn->id])
        ->assertSessionHasErrors('customer_id');

    expect($this->lead->fresh()->converted_customer_id)->toBeNull();
});

it('refuses to link a customer from another business', function () {
    TenantScope::enter($this->other->id);
    $foreign = Customer::create(['business_id' => $this->other->id, 'code' => 'RIVAL', 'name' => 'Rival customer']);
    TenantScope::enter($this->business->id);

    $this->actingAs($this->owner)
        ->post("/crm/leads/{$this->lead->id}/convert", ['customer_id' => $foreign->id])
        ->assertSessionHasErrors('customer_id');

    expect($this->lead->fresh()->converted_customer_id)->toBeNull();
});

it('cannot convert another business\'s lead', function () {
    TenantScope::enter($this->other->id);
    $foreignLead = Lead::create(['business_id' => $this->other->id, 'name' => 'Rival lead', 'status' => 'new']);
    TenantScope::enter($this->business->id);

    $this->actingAs($this->owner)
        ->post("/crm/leads/{$foreignLead->id}/convert", ['code' => 'X'])
        ->assertNotFound();
});

// ── Permission boundary ─────────────────────────────────────────────────────

it('refuses Staff, who may qualify a lead but not create master data', function () {
    // ⚠ C-CRMB-14. A customer code is permanent. Staff qualify; deciding a
    // customer record exists is a manager's call.
    $staff = ($this->asRole)('Staff');
    // ⚠ Their own lead, so the PERMISSION refuses rather than visibility (a lead
    // the reader cannot see 404s at route binding since 15 Sep 2026).
    $this->lead->update(['assigned_to_user_id' => $staff->id]);

    $this->actingAs($staff)
        ->post("/crm/leads/{$this->lead->id}/convert", ['code' => 'RAHIM'])
        ->assertForbidden();

    expect($this->lead->fresh()->converted_customer_id)->toBeNull();
});

it('lets a Manager convert', function () {
    $manager = ($this->asRole)('Manager');

    $this->actingAs($manager)
        ->post("/crm/leads/{$this->lead->id}/convert", ['code' => 'RAHIM'])
        ->assertRedirect();

    expect($this->lead->fresh()->status)->toBe(LeadStatus::Converted);
});

// ── Structural guard ────────────────────────────────────────────────────────

it('keeps the conversion columns out of the lead\'s fillable list', function () {
    // ⚠⚠ A structural guard, not a behavioural one. If somebody adds these back
    // to $fillable for convenience, this fails and says why.
    $fillable = (new Lead)->getFillable();

    expect($fillable)->not->toContain('converted_customer_id')
        ->and($fillable)->not->toContain('converted_at');
});
