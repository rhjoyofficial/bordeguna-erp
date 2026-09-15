<?php

use App\Models\User;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Crm\database\seeders\CrmPermissionsSeeder;
use App\Modules\Crm\Enums\LeadStatus;
use App\Modules\Crm\Models\Campaign;
use App\Modules\Crm\Models\CrmAttribution;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadSource;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Sales\Models\Customer;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The lead screen through the real HTTP layer, plus the guards that stop this
 * slice from quietly doing something it must not.
 *
 * Design note: CRM-FOUNDATION-ARCHITECTURE.md, Corrections C-CRM-1..17.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Lead Co', 'slug' => 'lead-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new CrmPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->brand = Brand::create([
        'business_id' => $this->business->id, 'code' => 'LLD', 'name' => 'LaLaDia', 'slug' => 'laladia',
    ]);
    $this->source = LeadSource::create([
        'business_id' => $this->business->id, 'code' => 'META', 'name' => 'Meta Ads',
    ]);
    $this->campaign = Campaign::create([
        'business_id' => $this->business->id,
        'lead_source_id' => $this->source->id,
        'brand_id' => $this->brand->id,
        'code' => 'EID-HONEY-2026',
        'name' => 'Eid Honey Campaign 2026',
        'external_campaign_id' => '238471923847',
    ]);
    $this->lostReason = Reason::create([
        'business_id' => $this->business->id, 'context' => 'lead_lost', 'name' => 'Price too high', 'is_active' => true,
    ]);

    // A member of this business, available to be assigned work.
    $this->agent = User::factory()->create(['name' => 'Call Centre Agent']);
    $this->agent->businesses()->attach($this->business->id);

    // ⚠ These tests act as the OWNER, and until 15 Sep 2026 they acted as
    // Manager. Since CRM lead slice 2 only the Owner sees every lead (Joy,
    // 14 Sep); a Manager assigning a lead to the call-centre agent would no
    // longer see it afterwards. What this file proves is the screen, not who
    // sees what - LeadVisibilityTest owns that.
    $this->asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);
        $this->currentUser = $user;

        return $this->actingAs($user);
    };

    $this->full = [
        'name' => 'Rahima Begum',
        'phone' => '+8801711000000',
        'email' => 'rahima@example.com',
        'address' => 'Mirpur, Dhaka',
        'status' => 'new',
        'brand_id' => $this->brand->id,
        'assigned_to_user_id' => $this->agent->id,
        'qualification_note' => 'Asked about 500g honey.',
        'note' => 'Prefers evening calls.',
        'lead_source_id' => $this->source->id,
        'campaign_id' => $this->campaign->id,
        'ad_set' => 'Dhaka Housewives',
        'ad_name' => '500g Honey Offer',
        'utm_source' => 'facebook',
        'utm_medium' => 'paid_social',
        'utm_campaign' => 'eid_honey_2026',
        'external_lead_id' => 'META-LEAD-99881',
    ];
});

// ── The structural guard ────────────────────────────────────────────────────

/**
 * ⚠⚠ The guard Product, Supplier and Customer all carry, and whose ABSENCE let
 * four customer fields ship unreachable. Add a fillable field to Lead and forget
 * the screen, and this fails by name.
 */
it('lets the screen set every field the model can hold', function () {
    ($this->asRole)('Owner')->post('/crm/leads', $this->full);

    $lead = Lead::where('email', 'rahima@example.com')->firstOrFail();

    // business_id comes from the tenant scope. status has a default.
    // ⚠ lost_reason_id / lost_at are set by losing a lead, and
    // converted_customer_id / converted_at ONLY by the Phase 3 conversion action
    // (C-CRM-9) - no form may set them, which is the point of this slice.
    $expected = collect((new Lead)->getFillable())
        ->reject(fn ($f) => in_array($f, [
            'business_id', 'status', 'lost_reason_id', 'lost_at',
            'converted_customer_id', 'converted_at',
        ], true))
        ->values();

    expect($expected)->not->toBeEmpty();

    $unsettable = $expected->reject(fn ($f) => filled($lead->{$f}))->values()->all();

    expect($unsettable)->toBe([], 'these fields cannot be set from the screen: '.implode(', ', $unsettable));
});

// ── Capture and attribution ─────────────────────────────────────────────────

it('captures a lead and its acquisition history in one action', function () {
    ($this->asRole)('Owner')->post('/crm/leads', $this->full)->assertRedirect();

    $lead = Lead::where('email', 'rahima@example.com')->sole();

    expect($lead->name)->toBe('Rahima Begum')
        ->and($lead->status)->toBe(LeadStatus::New)
        ->and($lead->brand_id)->toBe($this->brand->id)
        // ⚠ The user's input is preserved EXACTLY. C-CRM-12.
        ->and($lead->phone)->toBe('+8801711000000')
        // ⚠ …and the matching key is derived beside it, never instead of it.
        ->and($lead->phone_normalized)->toBe('01711000000');

    $attribution = $lead->attributions()->sole();

    expect($attribution->lead_source_id)->toBe($this->source->id)
        ->and($attribution->campaign_id)->toBe($this->campaign->id)
        // ⚠⚠ C-CRM-8: Meta's strings preserved verbatim, not normalised into a
        // taxonomy of ours that would have to mirror theirs.
        ->and($attribution->ad_set)->toBe('Dhaka Housewives')
        ->and($attribution->ad_name)->toBe('500g Honey Offer')
        ->and($attribution->external_lead_id)->toBe('META-LEAD-99881')
        ->and($attribution->recorded_by)->toBe($this->currentUser->id);
});

it('appends a second touch instead of overwriting the first', function () {
    ($this->asRole)('Owner')->post('/crm/leads', $this->full);
    $lead = Lead::sole();

    $second = Campaign::create([
        'business_id' => $this->business->id,
        'lead_source_id' => $this->source->id,
        'code' => 'WINTER-2026',
        'name' => 'Winter Ghee 2026',
    ]);

    ($this->asRole)('Owner')->put("/crm/leads/{$lead->id}", array_merge($this->full, [
        'status' => 'contacted',
        'campaign_id' => $second->id,
        'ad_set' => 'Dhaka Winter',
    ]))->assertRedirect();

    // ⚠⚠ TWO rows, not one changed row. "Preserve the ENTIRE acquisition
    // history, not merely first and latest" is the requirement.
    expect($lead->attributions()->count())->toBe(2)
        ->and($lead->fresh()->firstAttribution()->campaign_id)->toBe($this->campaign->id)
        ->and($lead->fresh()->latestAttribution()->campaign_id)->toBe($second->id);
});

it('refuses a campaign that does not belong to the chosen source', function () {
    $otherSource = LeadSource::create([
        'business_id' => $this->business->id, 'code' => 'EVENT', 'name' => 'Event',
    ]);

    ($this->asRole)('Owner')
        ->post('/crm/leads', array_merge($this->full, ['lead_source_id' => $otherSource->id]))
        ->assertSessionHasErrors('campaign_id');

    expect(Lead::count())->toBe(0);
});

// ── C-CRM-9: converted is unreachable ───────────────────────────────────────

it('refuses to set converted through the form', function () {
    // ⚠⚠ `converted` asserts that a customer exists. A lead reading converted
    // with a null customer link is a lie every report would repeat for ever, so
    // only the Phase 3 conversion action may set it - and that action does not
    // exist yet, so NO path sets it at all.
    ($this->asRole)('Owner')
        ->post('/crm/leads', array_merge($this->full, ['status' => 'converted']))
        ->assertSessionHasErrors('status');

    expect(Lead::count())->toBe(0);
});

it('refuses to edit a lead that has already been converted', function () {
    ($this->asRole)('Owner')->post('/crm/leads', $this->full);
    $lead = Lead::sole();

    // Simulate what Phase 3 will do, directly - there is no route that does it.
    $customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Rahima Begum']);
    $lead->forceFill([
        'status' => LeadStatus::Converted,
        'converted_customer_id' => $customer->id,
        'converted_at' => now(),
    ])->save();

    ($this->asRole)('Owner')
        ->put("/crm/leads/{$lead->id}", array_merge($this->full, ['name' => 'Changed']))
        ->assertSessionHasErrors('status');

    expect($lead->fresh()->name)->toBe('Rahima Begum');
});

// ── C-CRM-7: append-only attribution ────────────────────────────────────────

it('refuses to update an attribution', function () {
    ($this->asRole)('Owner')->post('/crm/leads', $this->full);
    $attribution = CrmAttribution::sole();

    expect(fn () => $attribution->update(['ad_set' => 'Rewritten']))
        ->toThrow(RuntimeException::class, 'append-only');

    expect($attribution->fresh()->ad_set)->toBe('Dhaka Housewives');
});

it('refuses to delete an attribution', function () {
    ($this->asRole)('Owner')->post('/crm/leads', $this->full);
    $attribution = CrmAttribution::sole();

    expect(fn () => $attribution->delete())->toThrow(RuntimeException::class, 'append-only');

    expect(CrmAttribution::count())->toBe(1);
});

// ── C-CRM-13: duplicate detection ───────────────────────────────────────────

it('warns across BOTH leads and customers on a matching phone', function () {
    ($this->asRole)('Owner')->post('/crm/leads', $this->full);

    Customer::create([
        'business_id' => $this->business->id,
        'name' => 'Rahima B.',
        'code' => 'RAHIMA',
        // ⚠ A different spelling of the SAME number. If normalisation were not
        // applied on both sides, this match would silently not happen.
        'phone' => '01711-000000',
    ]);

    $response = ($this->asRole)('Owner')->getJson('/crm/leads/duplicates?phone=8801711000000');

    $response->assertOk();

    expect($response->json('leads'))->toHaveCount(1)
        ->and($response->json('customers'))->toHaveCount(1)
        ->and($response->json('customers.0.code'))->toBe('RAHIMA');
});

it('allows the duplicate to be created anyway, because households share numbers', function () {
    ($this->asRole)('Owner')->post('/crm/leads', $this->full);

    // ⚠⚠ WARN AND ALLOW, never block. A hard block would only make staff invent
    // fake numbers, which destroys the identity data this exists to protect.
    ($this->asRole)('Owner')
        ->post('/crm/leads', array_merge($this->full, ['name' => 'Karim, same household', 'email' => 'karim@example.com']))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Lead::where('phone_normalized', '01711000000')->count())->toBe(2);
});

it('never matches a duplicate from another business', function () {
    ($this->asRole)('Owner')->post('/crm/leads', $this->full);

    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-lead-co']);
    app(BusinessContext::class)->set($other->id);
    Lead::create(['business_id' => $other->id, 'name' => 'Someone Else', 'phone' => '01711000000']);
    app(BusinessContext::class)->set($this->business->id);

    $response = ($this->asRole)('Owner')->getJson('/crm/leads/duplicates?phone=01711000000');

    // ⚠ Only our own lead. The global scope is what makes this true, and this
    // asserts it rather than trusting it.
    expect($response->json('leads'))->toHaveCount(1)
        ->and($response->json('leads.0.name'))->toBe('Rahima Begum');
});

// ── Tenant boundaries, per model ────────────────────────────────────────────

it('refuses a brand, source, campaign or lost reason from another business', function (string $field) {
    $other = Business::create(['name' => 'Other Co', 'slug' => "other-{$field}"]);
    app(BusinessContext::class)->set($other->id);

    $foreign = match ($field) {
        'brand_id' => Brand::create(['business_id' => $other->id, 'code' => 'X', 'name' => 'Theirs', 'slug' => 'theirs'])->id,
        'lead_source_id' => LeadSource::create(['business_id' => $other->id, 'code' => 'X', 'name' => 'Theirs'])->id,
        'campaign_id' => Campaign::create([
            'business_id' => $other->id,
            'lead_source_id' => LeadSource::create(['business_id' => $other->id, 'code' => 'Y', 'name' => 'Theirs'])->id,
            'code' => 'X', 'name' => 'Theirs',
        ])->id,
        'lost_reason_id' => Reason::create(['business_id' => $other->id, 'context' => 'lead_lost', 'name' => 'Theirs'])->id,
    };

    app(BusinessContext::class)->set($this->business->id);

    // ⚠⚠ A bare `exists:` would accept every one of these. This project shipped
    // that defect twice - products.category_id/unit_of_measure_id, and ACL-02.
    ($this->asRole)('Owner')
        ->post('/crm/leads', array_merge($this->full, [$field => $foreign]))
        ->assertSessionHasErrors($field);

    expect(Lead::count())->toBe(0);
})->with(['brand_id', 'lead_source_id', 'campaign_id', 'lost_reason_id']);

it('refuses a reason whose context is not lead_lost', function () {
    // ⚠ C-CRM-10: in the business is not enough. A stock-adjustment reason must
    // not be selectable as a lead's lost reason.
    $wrongContext = Reason::create([
        'business_id' => $this->business->id, 'context' => 'stock_adjustment', 'name' => 'Damaged',
    ]);

    ($this->asRole)('Owner')
        ->post('/crm/leads', array_merge($this->full, ['lost_reason_id' => $wrongContext->id]))
        ->assertSessionHasErrors('lost_reason_id');
});

// ── C-CRM-2: assignment through the pivot ───────────────────────────────────

it('accepts an assignee who belongs to this business through the pivot', function () {
    ($this->asRole)('Owner')
        ->post('/crm/leads', $this->full)
        ->assertSessionHasNoErrors();

    expect(Lead::sole()->assigned_to_user_id)->toBe($this->agent->id);
});

it('refuses an assignee who is not a member of this business', function () {
    // ⚠⚠ `users` has NO business_id column - membership is the business_user
    // PIVOT. A scoped Rule::exists on users would match NOTHING and reject every
    // valid agent; validating against the pivot is what makes this work at all.
    $outsider = User::factory()->create();

    ($this->asRole)('Owner')
        ->post('/crm/leads', array_merge($this->full, ['assigned_to_user_id' => $outsider->id]))
        ->assertSessionHasErrors('assigned_to_user_id');

    expect(Lead::count())->toBe(0);
});

// ── C-CRM-14: permissions ───────────────────────────────────────────────────

it('lets Staff capture and work leads', function () {
    ($this->asRole)('Staff')->post('/crm/leads', $this->full)->assertRedirect();

    expect(Lead::count())->toBe(1);
});

it('ignores an assignment from Staff, who may not assign, and gives the lead to its creator', function () {
    ($this->asRole)('Staff')
        ->post('/crm/leads', $this->full)
        ->assertRedirect();

    // ⚠ The lead is captured - Staff's job - but the workload decision is a
    // manager's, so the chosen agent is dropped rather than the capture refused.
    // ⭐ Since 15 Sep 2026 (build plan D1) the lead is the CREATOR's, not
    // nobody's: an unassigned lead is visible only to those who hand leads out,
    // so it would have vanished from the officer's own list on save.
    expect(Lead::sole()->assigned_to_user_id)->toBe($this->currentUser->id)
        ->and(Lead::sole()->created_by_user_id)->toBe($this->currentUser->id);
});

// ── C-CRM-15: no implicit customer, no ledger ───────────────────────────────

it('creates no customer and writes no journal', function () {
    ($this->asRole)('Owner')->post('/crm/leads', $this->full);

    // ⚠⚠ A lead is not a customer and never becomes one by accident. Conversion
    // is a deliberate Phase 3 action.
    expect(Customer::count())->toBe(0)
        ->and(Journal::count())->toBe(0);
});

// ── Sources and campaigns: the reporting axes ───────────────────────────────

it('lets a manager create a source and a campaign through the screen', function () {
    // ⚠⚠ Without these routes the two tables would exist, validate, and never
    // hold a row - the `products.barcode` failure, which has happened four times
    // on this project.
    ($this->asRole)('Owner')->post('/crm/settings/sources', [
        'code' => 'FAIR', 'name' => 'Trade Fair', 'is_active' => true,
    ])->assertRedirect();

    $source = LeadSource::where('code', 'FAIR')->sole();

    ($this->asRole)('Owner')->post('/crm/settings/campaigns', [
        'lead_source_id' => $source->id,
        'brand_id' => $this->brand->id,
        'code' => 'DHAKA-FAIR-2026',
        'name' => 'Dhaka Trade Fair 2026',
        'external_campaign_id' => 'FB-99',
        'is_active' => true,
    ])->assertRedirect();

    $campaign = Campaign::where('code', 'DHAKA-FAIR-2026')->sole();

    expect($campaign->lead_source_id)->toBe($source->id)
        ->and($campaign->brand_id)->toBe($this->brand->id)
        ->and($campaign->external_campaign_id)->toBe('FB-99');
});

it('refuses a duplicate source code within the business', function () {
    ($this->asRole)('Owner')
        ->post('/crm/settings/sources', ['code' => 'META', 'name' => 'Meta again', 'is_active' => true])
        ->assertSessionHasErrors('code');

    expect(LeadSource::where('code', 'META')->count())->toBe(1);
});

it('keeps Staff out of the reporting axes', function () {
    // ⚠ C-CRM-14. A source invented mid-campaign by a busy agent is how a report
    // ends up with four spellings of "Meta".
    ($this->asRole)('Staff')->get('/crm/settings')->assertForbidden();

    ($this->asRole)('Staff')
        ->post('/crm/settings/sources', ['code' => 'X', 'name' => 'X', 'is_active' => true])
        ->assertForbidden();
});

it('refuses a campaign whose source belongs to another business', function () {
    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-campaign-co']);
    app(BusinessContext::class)->set($other->id);
    $foreignSource = LeadSource::create(['business_id' => $other->id, 'code' => 'X', 'name' => 'Theirs']);
    app(BusinessContext::class)->set($this->business->id);

    ($this->asRole)('Owner')
        ->post('/crm/settings/campaigns', [
            'lead_source_id' => $foreignSource->id,
            'code' => 'LEAK', 'name' => 'Leak', 'is_active' => true,
        ])
        ->assertSessionHasErrors('lead_source_id');
});

// ── The screens actually render ─────────────────────────────────────────────

/**
 * ⚠⚠ Found during the pre-deployment review: every other test POSTs or PUTs, so
 * nothing proved the INDEX pages render. `index()` maps each row through
 * `$lead->status->label()` and four relations - a null there is a 500 that no
 * amount of write-path testing would catch, and it is the first thing a user
 * sees.
 */
it('renders the lead list with a lead on it', function () {
    ($this->asRole)('Owner')->post('/crm/leads', $this->full);

    ($this->asRole)('Owner')->get('/crm/leads')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('crm/leads/index')
            ->has('leads.data', 1)
            ->where('leads.data.0.name', 'Rahima Begum')
            ->where('leads.data.0.status_label', 'New')
            ->where('leads.data.0.brand', 'LaLaDia')
            ->where('leads.data.0.assigned_to', 'Call Centre Agent')
            // ⚠ The form's list must NOT offer `converted`.
            ->has('selectable_statuses', 6)
            ->has('statuses', 7)
        );
});

it('renders the lead list when a lead has no name, brand or assignee', function () {
    // ⚠ Every personal field is nullable for C-CRM-16 anonymisation, so the list
    // must survive a row where they are all null - which is exactly what an
    // anonymised lead will look like one day.
    ($this->asRole)('Owner')->post('/crm/leads', [
        'name' => null, 'phone' => null, 'email' => null, 'status' => 'new',
    ])->assertRedirect();

    ($this->asRole)('Owner')->get('/crm/leads')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('leads.data', 1)->where('leads.data.0.name', null));
});

it('renders the sources and campaigns screen', function () {
    ($this->asRole)('Owner')->get('/crm/settings')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('crm/settings/index')
            ->has('sources', 1)
            ->where('sources.0.code', 'META')
            ->where('sources.0.campaigns_count', 1)
            ->has('campaigns', 1)
            ->where('campaigns.0.source', 'Meta Ads')
            ->where('campaigns.0.brand', 'LaLaDia')
        );
});

it('filters the lead list without error', function () {
    ($this->asRole)('Owner')->post('/crm/leads', $this->full);

    ($this->asRole)('Owner')->get('/crm/leads?search=8801711000000&status=new')
        ->assertOk()
        // ⚠ Searching an international spelling must find a lead captured any
        // other way, or duplicate detection on the list is theatre.
        ->assertInertia(fn ($page) => $page->has('leads.data', 1));
});

// ── Conversion is REACHABLE, not merely implemented ─────────────────────────

/**
 * ⚠⚠ THE GUARD THAT WAS MISSING. `crm.leads.convert`, the route, the action and
 * seventeen tests all shipped on 9 August, and for eighteen days nothing in
 * resources/js posted to any of it: no lead could become a customer at all.
 *
 * ⚠ EveryModuleIsReachableTest cannot see this class of bug - it filters to GET
 * routes and skips URIs containing `{`, so every POST action is invisible to it.
 * The screen props are the only thing a test can hold on to, so they are what
 * this asserts. Take `can_convert` or `customers` away and the button that
 * depends on them disappears, and this fails by name.
 */
it('gives the lead screen what it needs to offer conversion', function () {
    Lead::create([
        'business_id' => $this->business->id,
        'name' => 'Rahima Begum',
        'phone' => '+8801711000000',
        'status' => LeadStatus::New,
    ]);

    ($this->asRole)('Owner')->get('/crm/leads')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('crm/leads/index')
            ->where('can_convert', true)
            ->has('customers')
            ->has('customer_types')
        );
});

/**
 * The button is permission-gated, so Staff must be told it cannot convert
 * rather than shown a control that 403s on click.
 */
it('tells a Staff member it cannot convert', function () {
    ($this->asRole)('Staff')->get('/crm/leads')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can_convert', false));
});

/**
 * ⚠⚠ `ConvertLead` REFUSES a system customer (WALK-IN carries every counter
 * sale the business has made) and refuses an inactive one. Offering either in
 * the picker would be a form that fails on submit for a reason the person
 * clicking cannot see, so the list must exclude them at source.
 */
it('offers only customers that conversion will actually accept', function () {
    Customer::create([
        'business_id' => $this->business->id, 'code' => 'REAL',
        'name' => 'Karim Stores', 'is_system' => false, 'is_active' => true,
    ]);
    Customer::create([
        'business_id' => $this->business->id, 'code' => 'WALK-IN',
        'name' => 'Walk-in', 'is_system' => true, 'is_active' => true,
    ]);
    Customer::create([
        'business_id' => $this->business->id, 'code' => 'GONE',
        'name' => 'Closed Shop', 'is_system' => false, 'is_active' => false,
    ]);

    ($this->asRole)('Owner')->get('/crm/leads')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('customers', 1)
            ->where('customers.0.code', 'REAL')
        );
});

// ── Duplicate linking is REACHABLE too ──────────────────────────────────────

/**
 * ⚠⚠ THE SECOND DEAD ENGINE. `LinkDuplicateLead` and fifteen tests shipped on
 * 9 August; nothing ever posted to the route, so the amber duplicate banner
 * listed matches as plain text with no way to act on them.
 *
 * ⚠ DIRECTION: the lead in the URL becomes the DUPLICATE and `survivor_id` is
 * the one that stays. Backwards marks the wrong person as the copy, and nothing
 * about the result looks wrong afterwards - which is why it is pinned here.
 */
it('marks the lead in the url as the duplicate, not the survivor', function () {
    $survivor = Lead::create([
        'business_id' => $this->business->id, 'name' => 'Rahima Begum',
        'phone' => '+8801711000000', 'status' => LeadStatus::New,
    ]);
    $copy = Lead::create([
        'business_id' => $this->business->id, 'name' => 'Rahima B',
        'phone' => '+8801711000000', 'status' => LeadStatus::New,
    ]);

    ($this->asRole)('Owner')
        ->post("/crm/leads/{$copy->id}/duplicate-of", ['survivor_id' => $survivor->id])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($copy->fresh()->duplicate_of_lead_id)->toBe($survivor->id)
        ->and($survivor->fresh()->duplicate_of_lead_id)->toBeNull();
});

/**
 * The banner can only offer the button once there is a lead to mark, so the row
 * has to carry the link for the list to show it. Take the prop away and the
 * "duplicate of" line under the status disappears.
 */
it('tells the screen which lead a duplicate points at', function () {
    $survivor = Lead::create([
        'business_id' => $this->business->id, 'name' => 'Rahima Begum',
        'phone' => '+8801711000000', 'status' => LeadStatus::New,
    ]);
    $copy = Lead::create([
        'business_id' => $this->business->id, 'name' => 'Rahima B',
        'phone' => '+8801711000000', 'status' => LeadStatus::New,
    ]);

    app(\App\Modules\Crm\Actions\LinkDuplicateLead::class)->handle($copy, $survivor, $this->agent);

    ($this->asRole)('Owner')->get('/crm/leads')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('leads.data.0.duplicate_of', 'Rahima Begum')
            ->where('leads.data.0.duplicate_of_lead_id', $survivor->id)
            ->where('leads.data.1.duplicate_of', null)
        );
});
