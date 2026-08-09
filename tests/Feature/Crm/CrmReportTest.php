<?php

use App\Models\User;
use App\Modules\Crm\database\seeders\CrmPermissionsSeeder;
use App\Modules\Crm\Enums\InterestStatus;
use App\Modules\Crm\Enums\LeadStatus;
use App\Modules\Crm\Models\Campaign;
use App\Modules\Crm\Models\CrmAttribution;
use App\Modules\Crm\Models\CrmProductInterest;
use App\Modules\Crm\Models\CrmTask;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadSource;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * What the CRM can honestly tell management.
 *
 * ⚠⚠ THE CLAIMS UNDER TEST ARE THE COUNTING RULES, not that a page renders.
 * A report that counts conversations as people, or counts a duplicate twice,
 * produces a number somebody makes a buying decision from.
 * CRM-REPORTING-FOUNDATIONS.md, C-CRMR-1..8.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-09 10:00:00');

    $this->business = Business::create(['name' => 'Rep Co', 'slug' => 'rep-co', 'fiscal_year_start_month' => 7]);
    $this->other = Business::create(['name' => 'Rival Co', 'slug' => 'rival-rep-co', 'fiscal_year_start_month' => 7]);

    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new CrmPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->asRole = function (string $role): User {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $user;
    };

    $this->owner = ($this->asRole)('Owner');

    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'MANGO-HIM', 'name' => 'Himsagar Mango', 'type' => ProductType::FinishedGood,
    ]);

    $this->lead = fn (string $name, $status = LeadStatus::New) => Lead::create([
        'business_id' => $this->business->id, 'name' => $name, 'status' => $status,
    ]);

    $this->wants = fn (Lead $lead, $qty = '5') => CrmProductInterest::create([
        'business_id' => $this->business->id,
        'subject_type' => $lead->getMorphClass(), 'subject_id' => $lead->id,
        'product_id' => $this->product->id, 'requested_quantity' => $qty,
        'requested_at' => now(), 'status' => InterestStatus::Open,
    ]);
});

afterEach(fn () => Carbon::setTestNow());

// ── C-CRMR-1: demand counts PEOPLE, not conversations ───────────────────────

it('counts how many PEOPLE want a product, not how many times they asked', function () {
    $rahim = ($this->lead)('Rahim');
    $karim = ($this->lead)('Karim');

    // Rahim asked three times; Karim once. That is TWO people wanting mango.
    ($this->wants)($rahim, '2');
    ($this->wants)($rahim, '5');
    ($this->wants)($rahim, '10');
    ($this->wants)($karim, '1');

    $this->actingAs($this->owner)->get('/crm/reports')
        ->assertInertia(fn (AssertableInertia $p) => $p
            ->where('demand.0.what', 'Himsagar Mango')
            // ⚠⚠ THE NUMBER SOMEBODY BUYS STOCK FROM. Counting rows would say
            // four, and four is a different business decision from two.
            ->where('demand.0.people', 2)
            ->where('demand.0.requests', 4)
            ->etc());
});

it('surfaces a product nobody currently sells', function () {
    // ⚠ The reason the interest table exists: last season's mango being asked
    // for is the signal that decides whether to stock it again.
    $this->product->update(['is_active' => false]);
    ($this->wants)(($this->lead)('Rahim'));

    $this->actingAs($this->owner)->get('/crm/reports')
        ->assertInertia(fn (AssertableInertia $p) => $p
            ->where('demand.0.not_currently_sold', true)->etc());
});

it('ignores a closed interest', function () {
    $lead = ($this->lead)('Rahim');
    $interest = ($this->wants)($lead);
    $interest->update(['status' => InterestStatus::Withdrawn, 'closed_at' => now()]);

    $this->actingAs($this->owner)->get('/crm/reports')
        ->assertInertia(fn (AssertableInertia $p) => $p->has('demand', 0)->etc());
});

// ── C-CRMR-2 / C-CRMR-3: the funnel excludes duplicates ─────────────────────

it('never counts a duplicate lead in the funnel', function () {
    $survivor = ($this->lead)('Rahim Traders', LeadStatus::Qualified);
    $duplicate = ($this->lead)('Rahim Trading');

    $this->actingAs($this->owner)->get('/crm/reports')
        ->assertInertia(fn (AssertableInertia $p) => $p->where('funnel.workable', 2)->etc());

    $this->actingAs($this->owner)
        ->post("/crm/leads/{$duplicate->id}/duplicate-of", ['survivor_id' => $survivor->id]);

    // ⚠⚠ A funnel that double-counts lies about the conversion rate, in the
    // direction that flatters it.
    $this->actingAs($this->owner)->get('/crm/reports')
        ->assertInertia(fn (AssertableInertia $p) => $p
            ->where('funnel.workable', 1)
            ->where('funnel.duplicatesExcluded', 1)
            ->etc());
});

it('states the denominator alongside the conversion rate', function () {
    $a = ($this->lead)('A', LeadStatus::Qualified);
    ($this->lead)('B');
    ($this->lead)('C');
    ($this->lead)('D');

    $this->actingAs($this->owner)->post("/crm/leads/{$a->id}/convert", ['code' => 'A-CUST']);

    // 1 of 4 = 25%. ⚠ A bare percentage invites the reader to guess what it
    // divided by, so both numbers are sent.
    $this->actingAs($this->owner)->get('/crm/reports')
        ->assertInertia(fn (AssertableInertia $p) => $p
            ->where('funnel.workable', 4)
            ->where('funnel.converted', 1)
            ->where('funnel.conversionRate', 25) // ⚠ round() gives float 25.0; JSON drops the .0. A rate of 25.5 stays 25.5.
            ->etc());
});

it('reports no conversion rate rather than dividing by zero', function () {
    $this->actingAs($this->owner)->get('/crm/reports')
        ->assertInertia(fn (AssertableInertia $p) => $p
            ->where('funnel.workable', 0)
            ->where('funnel.conversionRate', null)
            ->etc());
});

// ── C-CRMR-4: attribution is read through the LEAD ──────────────────────────

it('credits a campaign for a customer it produced', function () {
    $source = LeadSource::create(['business_id' => $this->business->id, 'code' => 'FB', 'name' => 'Facebook']);
    $campaign = Campaign::create([
        'business_id' => $this->business->id, 'lead_source_id' => $source->id,
        'code' => 'EID', 'name' => 'Eid Honey',
    ]);

    foreach (['Rahim', 'Karim'] as $name) {
        $lead = ($this->lead)($name, LeadStatus::Qualified);
        CrmAttribution::create([
            'business_id' => $this->business->id,
            'subject_type' => $lead->getMorphClass(), 'subject_id' => $lead->id,
            'lead_source_id' => $source->id, 'campaign_id' => $campaign->id,
            'occurred_at' => now(),
        ]);
        $leads[$name] = $lead;
    }

    $this->actingAs($this->owner)->post("/crm/leads/{$leads['Rahim']->id}/convert", ['code' => 'RAHIM']);

    // ⚠⚠ Nothing was copied onto the customer at conversion, so this figure can
    // only come from following leads.converted_customer_id. A report that read
    // customer-side attribution would silently return zero for everything.
    $this->actingAs($this->owner)->get('/crm/reports')
        ->assertInertia(fn (AssertableInertia $p) => $p
            ->where('sources.0.source', 'Facebook')
            ->where('sources.0.campaign', 'Eid Honey')
            ->where('sources.0.leads', 2)
            ->where('sources.0.converted', 1)
            ->where('sources.0.rate', 50)
            ->etc());
});

// ── C-CRMR-7: the follow-up load respects the queue's own rule ──────────────

it('shows a Staff member only their own follow-up load', function () {
    $staff = ($this->asRole)('Staff');
    $manager = ($this->asRole)('Manager');
    $lead = ($this->lead)('Rahim');

    foreach ([$staff, $manager] as $user) {
        CrmTask::create([
            'business_id' => $this->business->id,
            'subject_type' => $lead->getMorphClass(), 'subject_id' => $lead->id,
            'title' => 'Call back', 'due_at' => '2026-08-01 10:00:00',
            'assigned_to_user_id' => $user->id,
        ]);
    }

    // ⚠⚠ Otherwise an agent reads the whole team's workload from the report
    // instead of the queue screen that guards it.
    $this->actingAs($staff)->get('/crm/reports')
        ->assertInertia(fn (AssertableInertia $p) => $p
            ->where('canSeeAllTasks', false)->has('followUps', 1)->etc());

    $this->actingAs($manager)->get('/crm/reports')
        ->assertInertia(fn (AssertableInertia $p) => $p
            ->where('canSeeAllTasks', true)->has('followUps', 2)->etc());
});

it('counts a task overdue only while it is open', function () {
    $lead = ($this->lead)('Rahim');
    $task = CrmTask::create([
        'business_id' => $this->business->id,
        'subject_type' => $lead->getMorphClass(), 'subject_id' => $lead->id,
        'title' => 'Late', 'due_at' => '2026-08-01 10:00:00',
        'assigned_to_user_id' => $this->owner->id,
    ]);

    $this->actingAs($this->owner)->get('/crm/reports')
        ->assertInertia(fn (AssertableInertia $p) => $p->where('followUps.0.overdue', 1)->etc());

    // ⚠ A task completed late is DONE, not overdue.
    $task->update(['status' => 'done', 'completed_at' => now()]);

    $this->actingAs($this->owner)->get('/crm/reports')
        ->assertInertia(fn (AssertableInertia $p) => $p->has('followUps', 0)->etc());
});

// ── C-CRMR-6: tenant isolation ──────────────────────────────────────────────

it('never counts another business\'s leads, interests or tasks', function () {
    TenantScope::enter($this->other->id);
    $foreignLead = Lead::create(['business_id' => $this->other->id, 'name' => 'Rival lead', 'status' => 'new']);
    $foreignUnit = UnitOfMeasure::create(['business_id' => $this->other->id, 'name' => 'U', 'abbreviation' => 'u']);
    $foreignProduct = Product::create([
        'business_id' => $this->other->id, 'unit_of_measure_id' => $foreignUnit->id,
        'sku' => 'RIVAL', 'name' => 'Rival product', 'type' => ProductType::FinishedGood,
    ]);
    CrmProductInterest::create([
        'business_id' => $this->other->id,
        'subject_type' => $foreignLead->getMorphClass(), 'subject_id' => $foreignLead->id,
        'product_id' => $foreignProduct->id, 'requested_at' => now(),
    ]);
    TenantScope::enter($this->business->id);

    ($this->wants)(($this->lead)('Rahim'));

    $this->actingAs($this->owner)->get('/crm/reports')
        ->assertInertia(fn (AssertableInertia $p) => $p
            ->where('funnel.workable', 1)
            ->has('demand', 1)
            ->where('demand.0.what', 'Himsagar Mango')
            ->etc());
});

// ── Reachability and permission ─────────────────────────────────────────────

it('renders for somebody who may view leads', function () {
    $this->actingAs($this->owner)->get('/crm/reports')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('crm/reports')
            ->has('funnel')->has('sources')->has('demand')->has('followUps'));
});

it('refuses somebody with no CRM permission', function () {
    $outsider = User::factory()->create();
    $outsider->businesses()->attach($this->business->id);

    $this->actingAs($outsider)->get('/crm/reports')->assertForbidden();
});

it('reports no revenue or campaign return, because there are no sales yet', function () {
    // ⚠⚠ Deliberately absent. A revenue-per-campaign column of zeros today is
    // either a report that looks broken or, worse, one somebody trusts. It
    // arrives with Sales.
    $this->actingAs($this->owner)->get('/crm/reports')
        ->assertInertia(fn (AssertableInertia $p) => $p->missing('revenue')->missing('roi')->etc());
});
