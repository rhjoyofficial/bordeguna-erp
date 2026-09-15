<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Models\Customer;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * 6 Sep 2026, Joy: "the search box not showing enough link to click."
 *
 * Three separate defects hid behind that sentence and each one gets a test
 * here, because each would come back silently:
 *
 *  1. A group showed five results and then stopped. No total, no sixth click.
 *  2. Customers and suppliers pointed at a filtered LIST although both have a
 *     record page - so the search knew the record and refused to open it.
 *  3. Quotations, tickets and opportunities shipped in Modules 3 and 4 and were
 *     never added to the box, so three document types were unfindable.
 *
 * ⚠ The last group of tests is the one that matters most. Tickets,
 * opportunities and quotations have no Eloquent model, so they carry NO
 * business global scope: their scoping is a hand-written `where('business_id')`
 * that a refactor could drop without breaking a single other test. The
 * cross-business cases below are what stands between that and one company
 * reading another's deals out of the header.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Depth Co', 'slug' => 'depth-co']);
    $this->other = Business::create(['name' => 'Other Co', 'slug' => 'other-co']);

    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['sales.reports.view', 'purchasing.reports.view', 'crm.leads.view', 'crm.leads.view.all', 'sales.orders.record'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    Role::firstOrCreate(['name' => 'DepthOwner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        // ⚠ view.all since 15 Sep 2026: these deals are unassigned, and only an
        // Owner-like reader sees those. LeadVisibilityTest owns who sees what.
        ->givePermissionTo(['sales.reports.view', 'purchasing.reports.view', 'crm.leads.view', 'crm.leads.view.all', 'sales.orders.record']);
    Role::firstOrCreate(['name' => 'DepthNobody', 'business_id' => $this->business->id, 'guard_name' => 'web']);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('DepthOwner');

    $this->nobody = User::factory()->create();
    $this->nobody->businesses()->attach($this->business->id);
    $this->nobody->assignRole('DepthNobody');
});

function depthSearch(User $user, string $q): array
{
    return test()->actingAs($user)
        ->withSession(['current_business_id' => test()->business->id])
        ->getJson(route('search', ['q' => $q]))
        ->json();
}

function depthGroup(User $user, string $q, string $title): ?array
{
    return collect(depthSearch($user, $q)['groups'])->firstWhere('title', $title);
}

it('reports the true total and a way to the rest when more matched than fit', function () {
    // Twelve against a page of eight: the four nobody could reach before.
    foreach (range(1, 12) as $i) {
        Customer::create([
            'business_id' => $this->business->id,
            'code' => 'DEP-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            'name' => 'Rahman Traders '.$i,
            'type' => 'retail',
        ]);
    }

    $group = depthGroup($this->owner, 'Rahman', 'Customers');

    expect($group)->not->toBeNull()
        ->and($group['items'])->toHaveCount(8)
        ->and($group['total'])->toBe(12)
        ->and($group['more_url'])->toBe('/sales/customers?search=Rahman');
});

it('offers no "view all" when everything already fits', function () {
    Customer::create([
        'business_id' => $this->business->id,
        'code' => 'DEP-ONE', 'name' => 'Solitary Shop', 'type' => 'retail',
    ]);

    $group = depthGroup($this->owner, 'Solitary', 'Customers');

    expect($group['total'])->toBe(1)
        ->and($group['more_url'])->toBeNull();
});

it('counts every match across the groups so the box can say how many there are', function () {
    Customer::create([
        'business_id' => $this->business->id,
        'code' => 'DEP-X1', 'name' => 'Mango House', 'type' => 'retail',
    ]);
    DB::table('suppliers')->insert([
        'business_id' => $this->business->id,
        'code' => 'MANGOS', 'name' => 'Mango Supply', 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(depthSearch($this->owner, 'Mango')['total'])->toBe(2);
});

it('lands a customer on the customer, not on a list to hunt through', function () {
    $customer = Customer::create([
        'business_id' => $this->business->id,
        'code' => 'DEP-REC', 'name' => 'Record Landing Ltd', 'type' => 'retail',
    ]);

    $group = depthGroup($this->owner, 'Record Landing', 'Customers');

    expect($group['items'][0]['url'])->toBe('/sales/customers/'.$customer->id);
});

it('lands a supplier on the supplier record', function () {
    $id = DB::table('suppliers')->insertGetId([
        'business_id' => $this->business->id,
        'code' => 'DEPSUP', 'name' => 'Landing Supplier', 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $group = depthGroup($this->owner, 'Landing Supplier', 'Suppliers');

    expect($group['items'][0]['url'])->toBe('/purchasing/suppliers/'.$id);
});

it('finds a ticket by its number, its subject or the name of whoever rang up', function () {
    DB::table('crm_tickets')->insert([
        'business_id' => $this->business->id,
        'ticket_number' => 'TK-2026-0001',
        'caller_name' => 'Shahana Begum',
        'caller_phone' => '01711000111',
        'subject' => 'Broken jar on delivery',
        'category' => 'quality',
        'priority' => 'high',
        'status' => 'open',
        'resolve_by' => now()->addDay(),
        'created_by' => $this->owner->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach (['TK-2026-0001', 'Broken jar', 'Shahana'] as $term) {
        expect(depthGroup($this->owner, $term, 'Tickets'))->not->toBeNull("searching '{$term}' should find the ticket");
    }

    expect(depthGroup($this->nobody, 'TK-2026-0001', 'Tickets'))->toBeNull();
});

it('never shows another company its tickets', function () {
    DB::table('crm_tickets')->insert([
        'business_id' => $this->other->id,
        'ticket_number' => 'TK-9999-0001',
        'caller_name' => 'Someone Else',
        'subject' => 'Not our complaint',
        'category' => 'quality',
        'priority' => 'low',
        'status' => 'open',
        'resolve_by' => now()->addDay(),
        'created_by' => $this->owner->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(depthGroup($this->owner, 'TK-9999-0001', 'Tickets'))->toBeNull()
        ->and(depthGroup($this->owner, 'Not our complaint', 'Tickets'))->toBeNull();
});

it('finds an opportunity, and only inside this business', function () {
    $stageId = DB::table('crm_pipeline_stages')->insertGetId([
        'business_id' => $this->business->id,
        'name' => 'Qualifying', 'sort_order' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach ([$this->business->id, $this->other->id] as $businessId) {
        DB::table('crm_opportunities')->insert([
            'business_id' => $businessId,
            'name' => 'Dealer deal '.$businessId,
            'stage_id' => $stageId,
            'value' => 50000,
            'status' => 'open',
            'created_by' => $this->owner->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $group = depthGroup($this->owner, 'Dealer deal', 'Opportunities');

    expect($group)->not->toBeNull()
        ->and($group['items'])->toHaveCount(1)
        ->and($group['items'][0]['label'])->toBe('Dealer deal '.$this->business->id);

    expect(depthGroup($this->nobody, 'Dealer deal', 'Opportunities'))->toBeNull();
});

it('finds a quotation by its number, gated on being allowed to take the order', function () {
    DB::table('quotations')->insert([
        'business_id' => $this->business->id,
        'quotation_number' => 'QT-2026-0007',
        'customer_id' => Customer::create([
            'business_id' => $this->business->id,
            'code' => 'DEP-QT', 'name' => 'Quote Customer', 'type' => 'retail',
        ])->id,
        'status' => 'sent',
        'valid_until' => now()->addDays(7)->toDateString(),
        'created_by' => $this->owner->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(depthGroup($this->owner, 'QT-2026-0007', 'Quotations'))->not->toBeNull()
        ->and(depthGroup($this->nobody, 'QT-2026-0007', 'Quotations'))->toBeNull();
});

it('gives every group an icon so thirteen of them can be told apart', function () {
    Customer::create([
        'business_id' => $this->business->id,
        'code' => 'DEP-IC', 'name' => 'Icon Customer', 'type' => 'retail',
    ]);

    expect(depthGroup($this->owner, 'Icon Customer', 'Customers')['icon'])->toBe('customer');
});
