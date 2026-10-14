<?php

use App\Models\User;
use App\Modules\Crm\database\seeders\CrmPermissionsSeeder;
use App\Modules\Crm\Models\Lead;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Models\Customer;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Module 4, Slice 1 (CRM spec §7-§8): opportunities and the pipeline.
 *
 * An opportunity is a deal being chased: it belongs to a lead or a customer,
 * carries a value and an expected close, and sits in exactly one stage. Won
 * and Lost are OUTCOMES, not stages - a board whose last column is "Won"
 * teaches people to park deals there and stop looking. Losing requires a
 * reason, the same reasons the lead flow already keeps.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Pipe Co', 'slug' => 'pipe-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }
    (new CrmPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    $this->customer = Customer::create([
        'business_id' => $this->business->id,
        'code' => 'CUS-0001', 'name' => 'Rahim Traders', 'type' => 'dealer',
    ]);
    $this->lead = Lead::create([
        'business_id' => $this->business->id,
        'name' => 'Karim Store', 'phone' => '01711111111',
    ]);
    $this->lostReason = Reason::create([
        'business_id' => $this->business->id, 'context' => 'lead_lost', 'name' => 'Chose a competitor', 'is_active' => true,
    ]);

    $this->asOwner = fn () => $this->actingAs($this->owner)
        ->withHeader('X-Business-Id', (string) $this->business->id);
});

function stageId(string $name): int
{
    return (int) DB::table('crm_pipeline_stages')->where('name', $name)->value('id');
}

it('shows the board with default stages, cards and per-column value totals', function () {
    // First visit creates the default stages for the business, idempotently.
    ($this->asOwner)()->get(route('crm.pipeline'))->assertOk();
    ($this->asOwner)()->get(route('crm.pipeline'))->assertOk();

    expect(DB::table('crm_pipeline_stages')->count())->toBe(4);

    ($this->asOwner)()->post(route('crm.opportunities.store'), [
        'name' => 'Dealer opening order',
        'customer_id' => $this->customer->id,
        'stage_id' => stageId('Qualification'),
        'value' => '150000',
        'expected_close_on' => '2026-09-30',
    ])->assertSessionHasNoErrors();

    ($this->asOwner)()->get(route('crm.pipeline'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('crm/pipeline')
            ->has('stages', 4)
            ->where('stages.0.name', 'Qualification')
            ->where('stages.0.total_value', fn ($value) => (float) $value === 150000.0)
            ->where('stages.0.opportunities.0.name', 'Dealer opening order')
            ->where('stages.0.opportunities.0.party', 'Rahim Traders'));

    $bystander = User::factory()->create();
    $bystander->businesses()->attach($this->business->id);
    $this->actingAs($bystander)->withHeader('X-Business-Id', (string) $this->business->id)
        ->get(route('crm.pipeline'))
        ->assertForbidden();
});

it('moves a deal between stages, and wins it as an outcome with a stamp', function () {
    ($this->asOwner)()->get(route('crm.pipeline'));

    ($this->asOwner)()->post(route('crm.opportunities.store'), [
        'name' => 'Corporate gift order',
        'lead_id' => $this->lead->id,
        'stage_id' => stageId('Qualification'),
        'value' => '40000',
    ])->assertSessionHasNoErrors();

    $id = (int) DB::table('crm_opportunities')->value('id');

    ($this->asOwner)()->post(route('crm.opportunities.stage', $id), [
        'stage_id' => stageId('Negotiation'),
    ])->assertSessionHasNoErrors();

    expect(DB::table('crm_opportunities')->find($id)->stage_id)->toBe(stageId('Negotiation'));

    ($this->asOwner)()->post(route('crm.opportunities.won', $id))->assertSessionHasNoErrors();

    $won = DB::table('crm_opportunities')->find($id);
    expect($won->status)->toBe('won')
        ->and($won->won_at)->not->toBeNull();
});

it('refuses to lose a deal without a reason, and keeps the reason it is given', function () {
    ($this->asOwner)()->get(route('crm.pipeline'));

    ($this->asOwner)()->post(route('crm.opportunities.store'), [
        'name' => 'Distributor talk',
        'customer_id' => $this->customer->id,
        'stage_id' => stageId('Proposal'),
        'value' => '90000',
    ])->assertSessionHasNoErrors();

    $id = (int) DB::table('crm_opportunities')->value('id');

    ($this->asOwner)()->post(route('crm.opportunities.lost', $id), [])
        ->assertSessionHasErrors('lost_reason_id');

    ($this->asOwner)()->post(route('crm.opportunities.lost', $id), [
        'lost_reason_id' => $this->lostReason->id,
    ])->assertSessionHasNoErrors();

    $lost = DB::table('crm_opportunities')->find($id);
    expect($lost->status)->toBe('lost')
        ->and($lost->lost_reason_id)->toBe($this->lostReason->id)
        ->and($lost->lost_at)->not->toBeNull();
});

it('insists a deal belongs to somebody - a lead or a customer', function () {
    ($this->asOwner)()->get(route('crm.pipeline'));

    ($this->asOwner)()->post(route('crm.opportunities.store'), [
        'name' => 'Orphan deal',
        'stage_id' => stageId('Qualification'),
        'value' => '1000',
    ])->assertSessionHasErrors();
});

// CRM-009 (audit 14 Oct 2026): a deal goes to a member of this business, on a
// lead the person adding it may see.
it('refuses a deal assigned to another business\'s login, or on a lead the officer cannot see', function () {
    ($this->asOwner)()->get(route('crm.pipeline'));

    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-co']);
    $stranger = User::factory()->create();
    $stranger->businesses()->attach($other->id);
    app(BusinessContext::class)->set($this->business->id);

    ($this->asOwner)()->post(route('crm.opportunities.store'), [
        'name' => 'Big order', 'customer_id' => $this->customer->id,
        'stage_id' => stageId('Qualification'), 'value' => '1000',
        'assigned_to_user_id' => $stranger->id,
    ])->assertSessionHasErrors('assigned_to_user_id');

    // An officer (Staff) may not see an unassigned lead, so may not deal on it.
    $officer = User::factory()->create();
    $officer->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $officer->assignRole('Staff');

    $this->actingAs($officer)->withHeader('X-Business-Id', (string) $this->business->id)
        ->post(route('crm.opportunities.store'), [
            'name' => 'Hidden lead deal', 'lead_id' => $this->lead->id,
            'stage_id' => stageId('Qualification'), 'value' => '1000',
        ])->assertSessionHasErrors('lead_id');

    expect(DB::table('crm_opportunities')->count())->toBe(0);
});

// Adding a deal is a popup over the board since 14 Oct 2026 (the form no
// longer sits above it). The page carries what the popup draws - the first
// stage it aims a new deal at, and the leads, customers and salespeople it
// picks from - and the popup saves through the same route.
it('carries what the add-deal popup needs, and saves through the same route', function () {
    ($this->asOwner)()->get(route('crm.pipeline'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('crm/pipeline')
            ->where('stages.0.name', 'Qualification')
            ->where('leads', fn ($leads) => collect($leads)->contains(fn ($lead) => $lead['id'] === $this->lead->id))
            ->where('customers', fn ($customers) => collect($customers)->contains(fn ($customer) => $customer['id'] === $this->customer->id))
            ->where('users', fn ($users) => collect($users)->contains(fn ($user) => $user['id'] === $this->owner->id)));

    // Exactly what the popup sends for a lead: the customer side travels as null.
    ($this->asOwner)()->post(route('crm.opportunities.store'), [
        'name' => 'First carton order',
        'lead_id' => (string) $this->lead->id,
        'customer_id' => null,
        'stage_id' => (string) stageId('Qualification'),
        'value' => '25000',
        'expected_close_on' => null,
        'assigned_to_user_id' => (string) $this->owner->id,
        'next_action' => 'Call on Sunday',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $deal = DB::table('crm_opportunities')->where('name', 'First carton order')->first();

    expect($deal->lead_id)->toBe($this->lead->id)
        ->and($deal->customer_id)->toBeNull()
        ->and($deal->stage_id)->toBe(stageId('Qualification'))
        ->and($deal->assigned_to_user_id)->toBe($this->owner->id)
        ->and($deal->status)->toBe('open');
});
